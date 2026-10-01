<?php
/**
 * LPFPullman - Script para copiar la tabla `infoVehiculos` desde AWS Lightsail a AWS RDS
 */

set_time_limit(300);
ini_set('memory_limit', '256M');

// Configuración Origen (AWS Lightsail obt. vía MCP aws-trabajo)
$SRC_HOST = 'ls-8e1aff8e78a1e05f7ef2bbd722e94e9834548e1f.cs9gyyc0moxd.us-east-1.rds.amazonaws.com';
$SRC_USER = 'dbmasteruser';
$SRC_PASS = 'K5f=.FF0[W`ioN]<<<<T{mTJ=i%$l4At';
$SRC_DB   = 'masgps';

// Configuración Destino (AWS RDS masGPS)
$DST_HOST = 'masgps.c6xou04wqeof.us-east-1.rds.amazonaws.com';
$DST_USER = 'admin';
$DST_PASS = 'BIWEHB?NtOi6GPo.WaKD-Uvy[I9F';
$DST_DB   = 'masGPS';

function runMigration() {
    global $SRC_HOST, $SRC_USER, $SRC_PASS, $SRC_DB;
    global $DST_HOST, $DST_USER, $DST_PASS, $DST_DB;

    $startTime = microtime(true);

    echo "=======================================================\n";
    echo "  Copia de Tabla infoVehiculos entre Bases de Datos    \n";
    echo "=======================================================\n";
    echo "Origen:  $SRC_HOST ($SRC_DB)\n";
    echo "Destino: $DST_HOST ($DST_DB)\n";
    echo "-------------------------------------------------------\n";

    mysqli_report(MYSQLI_REPORT_OFF);

    // 1. Conectar a Base de Datos Origen
    echo "Conectando a base de datos Origen...\n";
    $srcConn = @new mysqli($SRC_HOST, $SRC_USER, $SRC_PASS, $SRC_DB);
    if ($srcConn->connect_errno) {
        throw new Exception("Error al conectar a BD Origen: " . $srcConn->connect_error);
    }
    $srcConn->set_charset("utf8mb4");
    echo "Conexión exitosa a BD Origen.\n";

    // 2. Conectar a Base de Datos Destino
    echo "Conectando a base de datos Destino...\n";
    $dstConn = @new mysqli($DST_HOST, $DST_USER, $DST_PASS, $DST_DB);
    if ($dstConn->connect_errno) {
        $dstConn = @new mysqli($DST_HOST, $DST_USER, $DST_PASS, 'masgps');
    }
    if ($dstConn->connect_errno) {
        throw new Exception("Error al conectar a BD Destino: " . $dstConn->connect_error);
    }
    $dstConn->set_charset("utf8mb4");
    echo "Conexión exitosa a BD Destino.\n";

    // 3. Obtener el esquema CREATE TABLE de infoVehiculos desde Origen
    echo "Obteniendo estructura DDL de la tabla infoVehiculos...\n";
    $resCreate = $srcConn->query("SHOW CREATE TABLE infoVehiculos");
    if (!$resCreate || $resCreate->num_rows === 0) {
        throw new Exception("No se encontró la tabla infoVehiculos en la BD Origen.");
    }
    $rowCreate = $resCreate->fetch_array();
    $createTableSql = $rowCreate[1];

    // Reemplazar CREATE TABLE por CREATE TABLE IF NOT EXISTS
    $createTableSql = preg_replace('/CREATE TABLE/i', 'CREATE TABLE IF NOT EXISTS', $createTableSql, 1);

    echo "Creando tabla infoVehiculos en BD Destino (si no existe)...\n";
    if (!$dstConn->query($createTableSql)) {
        throw new Exception("Error al crear la tabla infoVehiculos en Destino: " . $dstConn->error);
    }

    // 4. Leer registros de la tabla Origen
    echo "Leyendo registros de infoVehiculos en Origen...\n";
    $resData = $srcConn->query("SELECT * FROM infoVehiculos");
    if (!$resData) {
        throw new Exception("Error al consultar datos de Origen: " . $srcConn->error);
    }

    $totalRows = $resData->num_rows;
    echo "Se encontraron $totalRows registros para migrar.\n";

    if ($totalRows === 0) {
        echo "La tabla Origen está vacía. Proceso finalizado.\n";
        return;
    }

    // Obtener campos de la tabla
    $fields = [];
    $fieldTypes = [];
    while ($field = $resData->fetch_field()) {
        $fields[] = "`" . $field->name . "`";
        $fieldTypes[] = $field->name;
    }

    $fieldNamesSql = implode(", ", $fields);
    
    // Limpiar o vaciar la tabla destino antes de copiar (opcional/seguro)
    $dstConn->query("TRUNCATE TABLE infoVehiculos");

    // 5. Inserción masiva en lotes de 500 registros con transacciones
    echo "Copiando datos a la BD Destino en lotes...\n";
    $dstConn->begin_transaction();

    $batchSize = 500;
    $rowsBatch = [];
    $copied = 0;

    while ($row = $resData->fetch_assoc()) {
        $values = [];
        foreach ($fieldTypes as $colName) {
            $val = $row[$colName];
            if ($val === null) {
                $values[] = "NULL";
            } else {
                $values[] = "'" . $dstConn->real_escape_string($val) . "'";
            }
        }
        $rowsBatch[] = "(" . implode(", ", $values) . ")";

        if (count($rowsBatch) >= $batchSize) {
            $insertSql = "INSERT INTO infoVehiculos ($fieldNamesSql) VALUES " . implode(", ", $rowsBatch);
            if (!$dstConn->query($insertSql)) {
                $dstConn->rollback();
                throw new Exception("Error al insertar lote en Destino: " . $dstConn->error);
            }
            $copied += count($rowsBatch);
            $rowsBatch = [];
        }
    }

    // Insertar último lote sobrante
    if (count($rowsBatch) > 0) {
        $insertSql = "INSERT INTO infoVehiculos ($fieldNamesSql) VALUES " . implode(", ", $rowsBatch);
        if (!$dstConn->query($insertSql)) {
            $dstConn->rollback();
            throw new Exception("Error al insertar lote final en Destino: " . $dstConn->error);
        }
        $copied += count($rowsBatch);
    }

    $dstConn->commit();
    $elapsed = round(microtime(true) - $startTime, 2);

    echo "-------------------------------------------------------\n";
    echo "  Migración Finalizada con Éxito                       \n";
    echo "-------------------------------------------------------\n";
    echo "Registros Copiados:   $copied de $totalRows\n";
    echo "Tiempo de Ejecución: $elapsed segundos\n";
    echo "-------------------------------------------------------\n";

    return [
        'success' => true,
        'copied' => $copied,
        'total' => $totalRows,
        'elapsed_seconds' => $elapsed
    ];
}

if (php_sapi_name() === 'cli') {
    runMigration();
} else {
    header('Content-Type: application/json');
    try {
        $res = runMigration();
        echo json_encode($res, JSON_PRETTY_PRINT);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

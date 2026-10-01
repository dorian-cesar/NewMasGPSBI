<?php
/**
 * LPFPullman - Script genérico para copiar cualquier tabla desde AWS Lightsail a AWS RDS
 */

set_time_limit(300);
ini_set('memory_limit', '256M');

// Configuración Origen (AWS Lightsail)
$SRC_HOST = 'ls-8e1aff8e78a1e05f7ef2bbd722e94e9834548e1f.cs9gyyc0moxd.us-east-1.rds.amazonaws.com';
$SRC_USER = 'dbmasteruser';
$SRC_PASS = 'K5f=.FF0[W`ioN]<<<<T{mTJ=i%$l4At';
$SRC_DB   = 'masgps';

// Configuración Destino (AWS RDS masGPS)
$DST_HOST = 'masgps.c6xou04wqeof.us-east-1.rds.amazonaws.com';
$DST_USER = 'admin';
$DST_PASS = 'BIWEHB?NtOi6GPo.WaKD-Uvy[I9F';
$DST_DB   = 'masGPS';

function copyTable($tableName) {
    global $SRC_HOST, $SRC_USER, $SRC_PASS, $SRC_DB;
    global $DST_HOST, $DST_USER, $DST_PASS, $DST_DB;

    $startTime = microtime(true);

    echo "=======================================================\n";
    echo "  Copia de Tabla '$tableName' entre Bases de Datos     \n";
    echo "=======================================================\n";
    echo "Origen:  $SRC_HOST ($SRC_DB)\n";
    echo "Destino: $DST_HOST ($DST_DB)\n";
    echo "-------------------------------------------------------\n";

    mysqli_report(MYSQLI_REPORT_OFF);

    // 1. Conectar a Base de Datos Origen
    $srcConn = @new mysqli($SRC_HOST, $SRC_USER, $SRC_PASS, $SRC_DB);
    if ($srcConn->connect_errno) {
        throw new Exception("Error al conectar a BD Origen: " . $srcConn->connect_error);
    }
    $srcConn->set_charset("utf8mb4");

    // 2. Conectar a Base de Datos Destino
    $dstConn = @new mysqli($DST_HOST, $DST_USER, $DST_PASS, $DST_DB);
    if ($dstConn->connect_errno) {
        $dstConn = @new mysqli($DST_HOST, $DST_USER, $DST_PASS, 'masgps');
    }
    if ($dstConn->connect_errno) {
        throw new Exception("Error al conectar a BD Destino: " . $dstConn->connect_error);
    }
    $dstConn->set_charset("utf8mb4");

    // 3. Obtener el esquema CREATE TABLE de la tabla desde Origen
    $resCreate = $srcConn->query("SHOW CREATE TABLE `$tableName`");
    if (!$resCreate || $resCreate->num_rows === 0) {
        throw new Exception("No se encontró la tabla '$tableName' en la BD Origen.");
    }
    $rowCreate = $resCreate->fetch_array();
    $createTableSql = $rowCreate[1];
    $createTableSql = preg_replace('/CREATE TABLE/i', 'CREATE TABLE IF NOT EXISTS', $createTableSql, 1);

    echo "Creando tabla '$tableName' en BD Destino (si no existe)...\n";
    if (!$dstConn->query($createTableSql)) {
        throw new Exception("Error al crear la tabla '$tableName' en Destino: " . $dstConn->error);
    }

    // 4. Leer registros de la tabla Origen
    $resData = $srcConn->query("SELECT * FROM `$tableName`");
    if (!$resData) {
        throw new Exception("Error al consultar datos de Origen: " . $srcConn->error);
    }

    $totalRows = $resData->num_rows;
    echo "Se encontraron $totalRows registros para migrar en '$tableName'.\n";

    if ($totalRows === 0) {
        echo "La tabla Origen está vacía. Proceso finalizado.\n";
        return ['success' => true, 'copied' => 0, 'total' => 0, 'elapsed_seconds' => 0];
    }

    // Obtener campos de la tabla
    $fields = [];
    $fieldNames = [];
    while ($field = $resData->fetch_field()) {
        $fields[] = "`" . $field->name . "`";
        $fieldNames[] = $field->name;
    }

    $fieldNamesSql = implode(", ", $fields);
    
    // Limpiar tabla destino
    $dstConn->query("TRUNCATE TABLE `$tableName`");

    // 5. Inserción masiva en lotes con transacciones
    $dstConn->begin_transaction();

    $batchSize = 500;
    $rowsBatch = [];
    $copied = 0;

    while ($row = $resData->fetch_assoc()) {
        $values = [];
        foreach ($fieldNames as $colName) {
            $val = $row[$colName];
            if ($val === null) {
                $values[] = "NULL";
            } else {
                $values[] = "'" . $dstConn->real_escape_string($val) . "'";
            }
        }
        $rowsBatch[] = "(" . implode(", ", $values) . ")";

        if (count($rowsBatch) >= $batchSize) {
            $insertSql = "INSERT INTO `$tableName` ($fieldNamesSql) VALUES " . implode(", ", $rowsBatch);
            if (!$dstConn->query($insertSql)) {
                $dstConn->rollback();
                throw new Exception("Error al insertar lote en Destino: " . $dstConn->error);
            }
            $copied += count($rowsBatch);
            $rowsBatch = [];
        }
    }

    if (count($rowsBatch) > 0) {
        $insertSql = "INSERT INTO `$tableName` ($fieldNamesSql) VALUES " . implode(", ", $rowsBatch);
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
    echo "Tabla Copiada:        $tableName\n";
    echo "Registros Copiados:   $copied de $totalRows\n";
    echo "Tiempo de Ejecución: $elapsed segundos\n";
    echo "-------------------------------------------------------\n";

    return [
        'success' => true,
        'table' => $tableName,
        'copied' => $copied,
        'total' => $totalRows,
        'elapsed_seconds' => $elapsed
    ];
}

$tableName = $_GET['table'] ?? ($argv[1] ?? 'unidadNegocio');

if (php_sapi_name() === 'cli') {
    copyTable($tableName);
} else {
    header('Content-Type: application/json');
    try {
        $res = copyTable($tableName);
        echo json_encode($res, JSON_PRETTY_PRINT);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

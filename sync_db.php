<?php
/**
 * LPFPullman - Script de Sincronización de Rastreadores a Base de Datos AWS RDS (`masGPS.lpf`)
 */

set_time_limit(600);
ini_set('memory_limit', '512M');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

date_default_timezone_set('America/Santiago');

function executeSync() {
    $startTime = microtime(true);
    $cuentaName = 'Pullman';
    $user = DEFAULT_MASGPS_USER;
    $pass = DEFAULT_MASGPS_PASSWORD;
    $dealerId = DEFAULT_MASGPS_DEALER_ID;

    echo "=======================================================\n";
    echo "  LPFPullman - Sincronizador de Base de Datos AWS RDS  \n";
    echo "=======================================================\n";
    echo "Fecha/Hora: " . date('Y-m-d H:i:s') . "\n";
    echo "Conectando a base de datos AWS RDS (masGPS)...\n";

    // 1. Conexión a la Base de Datos
    $mysqli = getDbConnection();
    echo "Conexión exitosa a MySQL AWS RDS.\n";

    // 2. Asegurar que la tabla `lpf` exista con el esquema exacto solicitado
    $createTableSql = "CREATE TABLE IF NOT EXISTS `lpf` (
      `id` int NOT NULL AUTO_INCREMENT,
      `cuenta` varchar(50) DEFAULT NULL,
      `id_tracker` varchar(15) DEFAULT NULL,
      `lat` varchar(20) DEFAULT NULL,
      `long` varchar(20) DEFAULT NULL,
      `patente` varchar(15) DEFAULT NULL,
      `fecha` date DEFAULT NULL,
      `last_update` date DEFAULT NULL,
      `imei` varchar(20) DEFAULT NULL,
      `connection_status` varchar(15) DEFAULT NULL,
      `grupo` varchar(15) DEFAULT NULL,
      `direccion` varchar(100) DEFAULT NULL,
      PRIMARY KEY (`id`),
      UNIQUE KEY `id_UNIQUE` (`id`),
      KEY `idx_id_tracker` (`id_tracker`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;";

    if (!$mysqli->query($createTableSql)) {
        throw new Exception("Error al verificar/crear la tabla 'lpf': " . $mysqli->error);
    }

    // Cargar mapas de id_trackers existentes en memoria en 1 sola consulta
    echo "Cargando mapa de trackers existentes desde la BD...\n";
    $existingTrackers = [];
    $resExisting = $mysqli->query("SELECT id_tracker FROM lpf");
    if ($resExisting) {
        while ($row = $resExisting->fetch_assoc()) {
            $existingTrackers[(string)$row['id_tracker']] = true;
        }
    }
    echo "Trackers existentes en BD: " . count($existingTrackers) . "\n";

    // 3. Autenticación en API MasGPS
    echo "Autenticando en MasGPS con cuenta $user...\n";
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => MASGPS_API_URL . '/user/auth',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => json_encode([
            "login" => $user,
            "password" => $pass,
            "dealer_id" => $dealerId,
            "locale" => "es",
            "hash" => null
        ]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json']
    ]);
    $authRes = curl_exec($ch);
    curl_close($ch);

    $authJson = json_decode($authRes, true);
    if (!isset($authJson['hash'])) {
        throw new Exception("Error de autenticación en MasGPS: " . json_encode($authJson));
    }
    $hash = $authJson['hash'];
    echo "Autenticado con éxito. Token Hash: $hash\n";

    // 4. Obtener listado de trackers
    echo "Solicitando listado de trackers a MasGPS API...\n";
    $ch2 = curl_init();
    curl_setopt_array($ch2, [
        CURLOPT_URL => MASGPS_API_URL . '/tracker/list',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => json_encode(["hash" => $hash]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json']
    ]);
    $listRes = curl_exec($ch2);
    curl_close($ch2);

    $listJson = json_decode($listRes, true);
    $trackers = $listJson['list'] ?? [];
    $totalTrackers = count($trackers);
    echo "Se encontraron $totalTrackers rastreadores en la cuenta.\n";

    if ($totalTrackers === 0) {
        echo "No hay rastreadores para procesar.\n";
        return;
    }

    // 5. Procesar estados mediante curl_multi en lotes de 50
    echo "Consultando estado de $totalTrackers trackers mediante curl_multi (lotes de 50)...\n";
    $batchSize = 50;
    $chunks = array_chunk($trackers, $batchSize);

    // Preparar declaraciones SQL (Statements) para alto rendimiento
    $stmtUpdate = $mysqli->prepare("UPDATE lpf SET `lat` = ?, `long` = ?, `patente` = ?, `fecha` = ?, `last_update` = ?, `imei` = ?, `connection_status` = ?, `grupo` = ?, `cuenta` = ?, `direccion` = ? WHERE `id_tracker` = ?");
    $stmtInsert = $mysqli->prepare("INSERT INTO lpf (`cuenta`, `id_tracker`, `lat`, `long`, `patente`, `fecha`, `last_update`, `imei`, `connection_status`, `grupo`, `direccion`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    $inserted = 0;
    $updated = 0;
    $hoyFecha = date('Y-m-d');

    // Iniciar transacción de MySQL para maximizar rendimiento
    $mysqli->begin_transaction();

    foreach ($chunks as $chunkIndex => $chunk) {
        $mh = curl_multi_init();
        $curlHandles = [];

        foreach ($chunk as $item) {
            $id = $item['id'];
            $ch = curl_init();

            curl_setopt_array($ch, [
                CURLOPT_URL => MASGPS_API_URL . '/tracker/get_state',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => json_encode([
                    "hash" => $hash,
                    "tracker_id" => $id
                ]),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json']
            ]);

            $curlHandles[$id] = $ch;
            curl_multi_add_handle($mh, $ch);
        }

        $active = null;
        do {
            $mrc = curl_multi_exec($mh, $active);
        } while ($active > 0);

        foreach ($chunk as $item) {
            $id = (string)$item['id'];
            $ch = $curlHandles[$id];
            $response = curl_multi_getcontent($ch);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);

            $stateJson = json_decode($response, true);
            $state = $stateJson['state'] ?? [];
            $gps = $state['gps'] ?? [];
            $location = $gps['location'] ?? [];

            $latStr = isset($location['lat']) ? substr((string)$location['lat'], 0, 20) : '0';
            $lngStr = isset($location['lng']) ? substr((string)$location['lng'], 0, 20) : '0';
            $patenteStr = substr($item['label'] ?? '', 0, 15);
            $imeiStr = substr((string)($item['source']['device_id'] ?? ''), 0, 20);
            $statusStr = substr($state['connection_status'] ?? 'offline', 0, 15);
            $grupoStr = substr((string)($item['group_id'] ?? '0'), 0, 15);
            $direccionStr = '';

            $rawUpdate = $state['last_update'] ?? ($gps['updated'] ?? $hoyFecha);
            $lastUpdateDate = date('Y-m-d', strtotime($rawUpdate));

            if (isset($existingTrackers[$id])) {
                // Actualizar registro existente
                $stmtUpdate->bind_param("sssssssssss", $latStr, $lngStr, $patenteStr, $hoyFecha, $lastUpdateDate, $imeiStr, $statusStr, $grupoStr, $cuentaName, $direccionStr, $id);
                $stmtUpdate->execute();
                $updated++;
            } else {
                // Insertar nuevo registro
                $stmtInsert->bind_param("sssssssssss", $cuentaName, $id, $latStr, $lngStr, $patenteStr, $hoyFecha, $lastUpdateDate, $imeiStr, $statusStr, $grupoStr, $direccionStr);
                $stmtInsert->execute();
                $existingTrackers[$id] = true;
                $inserted++;
            }
        }

        curl_multi_close($mh);
    }

    // Confirmar la transacción
    $mysqli->commit();

    $stmtUpdate->close();
    $stmtInsert->close();

    $elapsed = round(microtime(true) - $startTime, 2);

    echo "-------------------------------------------------------\n";
    echo "  Sincronización Completada con Éxito                  \n";
    echo "-------------------------------------------------------\n";
    echo "Registros Creados:     $inserted\n";
    echo "Registros Actualizados:$updated\n";
    echo "Total Procesados:      " . ($inserted + $updated) . "\n";
    echo "Tiempo de Ejecución:   $elapsed segundos\n";
    echo "-------------------------------------------------------\n";

    return [
        'success' => true,
        'created' => $inserted,
        'updated' => $updated,
        'total' => ($inserted + $updated),
        'elapsed_seconds' => $elapsed
    ];
}

// Ejecución
if (php_sapi_name() === 'cli') {
    executeSync();
} else {
    header('Content-Type: application/json');
    try {
        $result = executeSync();
        echo json_encode($result, JSON_PRETTY_PRINT);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

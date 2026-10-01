<?php
/**
 * LPFPullman - Sincronizador de Ralenti utilizando la lógica exacta de patron.php
 * Conexión a AWS RDS: masgps.c6xou04wqeof.us-east-1.rds.amazonaws.com (masGPS)
 */

set_time_limit(900);
ini_set('memory_limit', '512M');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

date_default_timezone_set("America/Santiago");

function syncRalentiPullman($targetDate = null) {
    $startTime = microtime(true);
    $user = DEFAULT_MASGPS_USER;
    $pass = DEFAULT_MASGPS_PASSWORD;
    $dealerId = DEFAULT_MASGPS_DEALER_ID;
    $contrato = 'Pullman';

    $ayer = $targetDate ? $targetDate : date('Y-m-d', strtotime("-1 days"));
    $from = "$ayer 00:00:00";
    $to   = "$ayer 23:59:00";

    echo "=======================================================\n";
    echo "  LPFPullman - Sincronizador Ralenti (patron.php)      \n";
    echo "=======================================================\n";
    echo "Fecha de Consulta: $ayer ($from a $to)\n";
    echo "Conectando a base de datos AWS RDS (masGPS)...\n";

    // 1. Base de datos
    $mysqli = getDbConnection();
    echo "Conexión exitosa a MySQL AWS RDS.\n";

    // 2. Crear tabla ralentiPullman si no existe
    $createTableSql = "CREATE TABLE IF NOT EXISTS `ralentiPullman` (
      `id` int NOT NULL AUTO_INCREMENT,
      `contrato` varchar(20) DEFAULT NULL,
      `id_tracker` varchar(20) DEFAULT NULL,
      `patente` varchar(15) DEFAULT NULL,
      `fecha` date DEFAULT NULL,
      `duration` varchar(10) DEFAULT NULL,
      `in_movement` varchar(10) DEFAULT NULL,
      `idle` varchar(10) DEFAULT NULL,
      PRIMARY KEY (`id`),
      KEY `idx_tracker_fecha` (`id_tracker`, `fecha`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;";

    if (!$mysqli->query($createTableSql)) {
        throw new Exception("Error al verificar/crear la tabla 'ralentiPullman': " . $mysqli->error);
    }
    echo "Tabla 'ralentiPullman' lista en la base de datos.\n";

    // 3. Autenticación en MasGPS
    echo "Autenticando en MasGPS ($user)...\n";
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => MASGPS_API_URL . '/user/auth',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => json_encode(["login" => $user, "password" => $pass, "dealer_id" => $dealerId, "locale" => "es"]),
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
    echo "Solicitando catálogo de trackers a MasGPS API...\n";
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
    $trackersList = $listJson['list'] ?? [];
    $totalTrackers = count($trackersList);
    echo "Se encontraron $totalTrackers rastreadores en la cuenta.\n";

    if ($totalTrackers === 0) {
        echo "No hay rastreadores para procesar.\n";
        return;
    }

    // 5. Cargar registros existentes en BD para evitar duplicados en la fecha
    $existingRecords = [];
    $resCheck = $mysqli->query("SELECT id_tracker FROM ralentiPullman WHERE fecha = '$ayer'");
    if ($resCheck) {
        while ($r = $resCheck->fetch_assoc()) {
            $existingRecords[(string)$r['id_tracker']] = true;
        }
    }

    // Parametrización según patron.php (Plugin ID 7: Informe de horas de motor)
    $fromUrl = urlencode($from);
    $toUrl   = urlencode($to);
    $title   = urlencode('Informe de horas de motor');
    $time_filter = urlencode('{"from":"00:00","to":"23:59","weekdays":[1,2,3,4,5,6,7]}');
    $plugin = urlencode('{"hide_empty_tabs":true,"plugin_id":7,"show_seconds":false,"show_detailed":false,"include_summary_sheet_only":false,"filter":true}');

    $batchSize = 50;
    $chunks = array_chunk($trackersList, $batchSize);

    $inserted = 0;
    $updated = 0;

    $stmtInsert = $mysqli->prepare("INSERT INTO ralentiPullman (`contrato`, `id_tracker`, `patente`, `fecha`, `duration`, `in_movement`, `idle`) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmtUpdate = $mysqli->prepare("UPDATE ralentiPullman SET `duration` = ?, `in_movement` = ?, `idle` = ?, `patente` = ? WHERE `id_tracker` = ? AND `fecha` = ?");

    $mysqli->begin_transaction();

    foreach ($chunks as $chunkIndex => $chunkTrackers) {
        $chunkIdsArr = array_map(fn($t) => $t['id'], $chunkTrackers);
        $idsStr = '[' . implode(',', $chunkIdsArr) . ']';
        $trackersUrl = urlencode($idsStr);

        $cadena = 'hash=' . $hash . '&title=' . $title . '&trackers=' . $trackersUrl . '&from=' . $fromUrl . '&to=' . $toUrl . '&time_filter=' . $time_filter . '&plugin=' . $plugin;

        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => MASGPS_API_URL . '/report/tracker/generate',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $cadena,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded; charset=UTF-8'
            ]
        ]);
        $informe = curl_exec($curl);
        curl_close($curl);

        $genJson = json_decode($informe);
        if (!isset($genJson->id)) {
            continue;
        }

        $reporteId = $genJson->id;

        // Polling para recuperar el reporte generado
        $reportData = null;
        for ($retry = 0; $retry < 15; $retry++) {
            sleep(2);
            $curlRet = curl_init();
            curl_setopt_array($curlRet, [
                CURLOPT_URL => MASGPS_API_URL . '/report/tracker/retrieve',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => 'hash=' . $hash . '&report_id=' . $reporteId,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/x-www-form-urlencoded; charset=UTF-8'
                ]
            ]);
            $retRes = curl_exec($curlRet);
            curl_close($curlRet);

            $retJson = json_decode($retRes);
            if ($retJson && isset($retJson->success) && $retJson->success) {
                $reportData = $retJson->report;
                break;
            }
        }

        if (!$reportData || !isset($reportData->sheets)) {
            continue;
        }

        // Extracción con estructura exacta de patron.php
        foreach ($reportData->sheets as $vehiculo) {
            if (isset($vehiculo->header) && $vehiculo->header == 'Período Resumen') {
                continue;
            }

            $patente     = substr($vehiculo->header ?? '', 0, 15);
            $id_tracker  = (string)($vehiculo->entity_ids[0] ?? '');
            $duration    = substr($vehiculo->sections[3]->data[0]->rows[0]->duration->v ?? '00:00', 0, 10);
            $in_movement = substr($vehiculo->sections[3]->data[0]->rows[0]->in_movement->v ?? '00:00', 0, 10);
            $idle        = substr($vehiculo->sections[3]->data[0]->rows[0]->idle->v ?? '00:00', 0, 10);

            if (empty($id_tracker)) continue;

            if (isset($existingRecords[$id_tracker])) {
                $stmtUpdate->bind_param("ssssss", $duration, $in_movement, $idle, $patente, $id_tracker, $ayer);
                $stmtUpdate->execute();
                $updated++;
            } else {
                $stmtInsert->bind_param("sssssss", $contrato, $id_tracker, $patente, $ayer, $duration, $in_movement, $idle);
                $stmtInsert->execute();
                $existingRecords[$id_tracker] = true;
                $inserted++;
            }
        }
    }

    $mysqli->commit();

    $stmtInsert->close();
    $stmtUpdate->close();

    $elapsed = round(microtime(true) - $startTime, 2);

    echo "-------------------------------------------------------\n";
    echo "  Sincronización ralentiPullman Finalizada             \n";
    echo "-------------------------------------------------------\n";
    echo "Fecha Procesada:       $ayer\n";
    echo "Registros Creados:     $inserted\n";
    echo "Registros Actualizados:$updated\n";
    echo "Total Procesados:      " . ($inserted + $updated) . "\n";
    echo "Tiempo de Ejecución:   $elapsed segundos\n";
    echo "-------------------------------------------------------\n";

    return [
        'success' => true,
        'fecha' => $ayer,
        'created' => $inserted,
        'updated' => $updated,
        'total' => ($inserted + $updated),
        'elapsed_seconds' => $elapsed
    ];
}

if (php_sapi_name() === 'cli') {
    $targetDate = $argv[1] ?? null;
    syncRalentiPullman($targetDate);
} else {
    header('Content-Type: application/json');
    try {
        $targetDate = $_GET['fecha'] ?? null;
        $res = syncRalentiPullman($targetDate);
        echo json_encode($res, JSON_PRETTY_PRINT);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

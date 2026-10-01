<?php
/**
 * LPFPullman - Sincronizador de Excesos de Velocidad Urbano/Interurbano (interUrbanoOverSpeed)
 * Basado en reporteOverSpeed.php
 */

set_time_limit(0);
ini_set('memory_limit', '512M');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

date_default_timezone_set("America/Santiago");
ob_implicit_flush(true);
if (ob_get_level()) ob_end_clean();

function syncOverSpeedPullman($targetDate = null) {
    $startTime = microtime(true);
    $user = DEFAULT_MASGPS_USER;
    $pass = DEFAULT_MASGPS_PASSWORD;
    $dealerId = DEFAULT_MASGPS_DEALER_ID;

    $ayer = $targetDate ? $targetDate : date('Y-m-d', strtotime("-1 days"));
    $from = "$ayer 00:00:00";
    $to   = "$ayer 23:59:59";

    echo "=======================================================\n";
    echo "  LPFPullman - Sincronizador interUrbanoOverSpeed      \n";
    echo "=======================================================\n";
    echo "Fecha de Consulta: $ayer ($from a $to)\n";
    echo "Conectando a base de datos AWS RDS (masGPS)...\n";

    // 1. Conexión a la Base de Datos
    $mysqli = getDbConnection();
    echo "Conexión exitosa a MySQL AWS RDS.\n";

    // 2. Crear tabla interUrbanoOverSpeed si no existe
    $createTableSql = "CREATE TABLE IF NOT EXISTS `interUrbanoOverSpeed` (
      `id` int NOT NULL AUTO_INCREMENT,
      `contrato` varchar(50) DEFAULT NULL,
      `id_tracker` int DEFAULT NULL,
      `patente` varchar(20) DEFAULT NULL,
      `fecha` date DEFAULT NULL,
      `start_time` time DEFAULT NULL,
      `duration` time DEFAULT NULL,
      `max_speed` int DEFAULT NULL,
      `lat` decimal(9,6) DEFAULT NULL,
      `lng` decimal(9,6) DEFAULT NULL,
      `direccion` varchar(255) DEFAULT NULL,
      PRIMARY KEY (`id`),
      UNIQUE KEY `id_UNIQUE` (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;";

    if (!$mysqli->query($createTableSql)) {
        throw new Exception("Error al verificar/crear la tabla 'interUrbanoOverSpeed': " . $mysqli->error);
    }
    echo "Tabla 'interUrbanoOverSpeed' lista en la base de datos.\n";

    // Limpiar registros previos de la fecha a consultar para evitar duplicados
    $mysqli->query("DELETE FROM `interUrbanoOverSpeed` WHERE `fecha` = '$ayer'");
    echo "Registros previos de $ayer limpiados.\n";

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

    // Parámetros formateados según reporteOverSpeed.php (Plugin ID 27: Violación de velocidad, max_speed=50)
    $fromUrl = urlencode($from);
    $toUrl   = urlencode($to);
    $title   = urlencode('Informe de violación de velocidad');
    $time_filter = urlencode('{"from":"00:00","to":"23:59","weekdays":[1,2,3,4,5,6,7]}');
    $plugin = urlencode('{"hide_empty_tabs":true,"plugin_id":27,"show_seconds":true,"min_duration_minutes":1,"max_speed":50,"group_by_driver":false,"filter":true}');

    $batchSize = 20;
    $chunks = array_chunk($trackersList, $batchSize);
    $totalChunks = count($chunks);

    $totalEvents = 0;

    $stmtInsert = $mysqli->prepare("INSERT INTO `interUrbanoOverSpeed` (`contrato`, `id_tracker`, `patente`, `fecha`, `start_time`, `duration`, `max_speed`, `lat`, `lng`, `direccion`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    foreach ($chunks as $chunkIndex => $chunkTrackers) {
        $cNum = $chunkIndex + 1;
        echo "Procesando lote $cNum/$totalChunks (" . count($chunkTrackers) . " rastreadores)...\n";
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
            echo "  Lote $cNum: Error al generar informe de velocidad.\n";
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
            echo "  Lote $cNum: No se obtuvieron datos del informe.\n";
            continue;
        }

        $chunkEvents = 0;
        $mysqli->begin_transaction();

        foreach ($reportData->sheets as $tracker) {
            $pat = substr(trim($tracker->header ?? ''), 0, 20);
            $id_tracker = intval($tracker->entity_ids[0] ?? 0);
            if (empty($id_tracker)) continue;

            $sections = $tracker->sections ?? [];

            foreach ($sections as $sec) {
                if (isset($sec->data[0]->rows)) {
                    $eventos = $sec->data[0]->rows;
                    foreach ($eventos as $evento) {
                        $direccion = str_replace("'", " ", (string)($evento->max_speed_address->v ?? ''));
                        $maxSpeed  = intval($evento->max_speed->v ?? 0);
                        $startTimeStr = substr($evento->start_time->v ?? '00:00:00', 0, 8);
                        $durationStr  = substr($evento->duration->v ?? '00:00:00', 0, 8);
                        $latVal       = floatval($evento->max_speed_address->location->lat ?? 0);
                        $lngVal       = floatval($evento->max_speed_address->location->lng ?? 0);

                        if (strlen($startTimeStr) === 5) $startTimeStr .= ':00';
                        if (strlen($durationStr) === 5) $durationStr .= ':00';

                        $contrato = null;

                        // Condición A: Urbana (max_speed >= 50, dirección contiene 'Zona' y no contiene 'Ruta')
                        if ($maxSpeed >= 50 && str_contains($direccion, 'Zona') && !str_contains($direccion, 'Ruta')) {
                            $contrato = 'Urbana';
                        }

                        // Condición B: InterUrbana (max_speed >= 90 y dirección contiene 'Ruta')
                        if ($maxSpeed >= 90 && str_contains($direccion, 'Ruta')) {
                            $contrato = 'InterUrbana';
                        }

                        if ($contrato !== null) {
                            $dirClean = substr($direccion, 0, 255);
                            $stmtInsert->bind_param("sissssiddd", $contrato, $id_tracker, $pat, $ayer, $startTimeStr, $durationStr, $maxSpeed, $latVal, $lngVal, $dirClean);
                            // Corregir bind types: "sissssiddd" -> 10 params:
                            // 1. contrato (s), 2. id_tracker (i), 3. patente (s), 4. fecha (s), 5. start_time (s), 6. duration (s), 7. max_speed (i), 8. lat (d), 9. lng (d), 10. direccion (s)
                            // string types: "sissssidds" (10 parameters)
                            $stmtInsert->bind_param("sissssidds", $contrato, $id_tracker, $pat, $ayer, $startTimeStr, $durationStr, $maxSpeed, $latVal, $lngVal, $dirClean);
                            $stmtInsert->execute();
                            $chunkEvents++;
                            $totalEvents++;
                        }
                    }
                }
            }
        }

        $mysqli->commit();
        echo "  Lote $cNum/$totalChunks completado: $chunkEvents eventos interurbanos/urbanos guardados. Total acumulado: $totalEvents\n";
    }

    $stmtInsert->close();

    $elapsed = round(microtime(true) - $startTime, 2);

    echo "-------------------------------------------------------\n";
    echo "  Sincronización interUrbanoOverSpeed Finalizada      \n";
    echo "-------------------------------------------------------\n";
    echo "Fecha Procesada:       $ayer\n";
    echo "Eventos Registrados:   $totalEvents\n";
    echo "Tiempo de Ejecución:   $elapsed segundos\n";
    echo "-------------------------------------------------------\n";

    return [
        'success' => true,
        'fecha' => $ayer,
        'events_created' => $totalEvents,
        'elapsed_seconds' => $elapsed
    ];
}

if (php_sapi_name() === 'cli') {
    $targetDate = $argv[1] ?? null;
    syncOverSpeedPullman($targetDate);
} else {
    header('Content-Type: application/json');
    try {
        $targetDate = $_GET['fecha'] ?? null;
        $res = syncOverSpeedPullman($targetDate);
        echo json_encode($res, JSON_PRETTY_PRINT);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

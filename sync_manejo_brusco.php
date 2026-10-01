<?php
/**
 * LPFPullman - Sincronizador de Manejo Brusco y Eventos (manejoBrusco)
 * Basado en manejoBrusco.php
 */

set_time_limit(0);
ini_set('memory_limit', '512M');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

date_default_timezone_set("America/Santiago");
ob_implicit_flush(true);
if (ob_get_level()) ob_end_clean();

function syncManejoBrusco($targetDate = null) {
    $startTime = microtime(true);
    $user = DEFAULT_MASGPS_USER;
    $pass = DEFAULT_MASGPS_PASSWORD;
    $dealerId = DEFAULT_MASGPS_DEALER_ID;
    $cuenta = 'Pullman';

    $ayer = $targetDate ? $targetDate : date('Y-m-d', strtotime("-1 days"));
    $from = "$ayer 00:00:00";
    $to   = "$ayer 23:59:59";

    echo "=======================================================\n";
    echo "  LPFPullman - Sincronizador manejoBrusco (Eventos)    \n";
    echo "=======================================================\n";
    echo "Fecha de Consulta: $ayer ($from a $to)\n";
    echo "Conectando a base de datos AWS RDS (masGPS)...\n";

    // 1. Conexión a la Base de Datos
    $mysqli = getDbConnection();
    echo "Conexión exitosa a MySQL AWS RDS.\n";

    // 2. Crear tabla manejoBrusco si no existe
    $createTableSql = "CREATE TABLE IF NOT EXISTS `manejoBrusco` (
      `id` int NOT NULL AUTO_INCREMENT,
      `cuenta` varchar(10) DEFAULT NULL,
      `id_tracker` varchar(15) DEFAULT NULL,
      `patente` varchar(7) DEFAULT NULL,
      `date` date DEFAULT NULL,
      `time` time DEFAULT NULL,
      `lat` varchar(20) DEFAULT NULL,
      `lng` varchar(20) DEFAULT NULL,
      `evento` varchar(100) DEFAULT NULL,
      PRIMARY KEY (`id`),
      KEY `idx_patente` (`patente`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;";

    if (!$mysqli->query($createTableSql)) {
        throw new Exception("Error al verificar/crear la tabla 'manejoBrusco': " . $mysqli->error);
    }
    echo "Tabla 'manejoBrusco' lista en la base de datos.\n";

    // Limpiar registros previos de la fecha a consultar para evitar duplicados
    $mysqli->query("DELETE FROM `manejoBrusco` WHERE `date` = '$ayer'");
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

    // Parámetros formateados según manejoBrusco.php (Plugin ID 11: Informe de evento)
    $fromUrl = urlencode($from);
    $toUrl   = urlencode($to);
    $title   = urlencode('Informe de evento');
    $time_filter = urlencode('{"from":"00:00","to":"23:59","weekdays":[1,2,3,4,5,6,7]}');
    $plugin = urlencode('{"hide_empty_tabs":true,"plugin_id":11,"show_seconds":true,"group_by_type":false,"event_types":["speedup","harsh_driving"]}');

    $batchSize = 30;
    $chunks = array_chunk($trackersList, $batchSize);
    $totalChunks = count($chunks);

    $totalEvents = 0;

    $stmtInsert = $mysqli->prepare("INSERT INTO `manejoBrusco` (`cuenta`, `id_tracker`, `patente`, `date`, `time`, `lat`, `lng`, `evento`) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");

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
            echo "  Lote $cNum: Error al generar informe de eventos.\n";
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

        foreach ($reportData->sheets as $sheet) {
            $patenteFull = $sheet->header ?? '';
            // Extraer primeros 7 caracteres de patente (ej. "KRFZ-40")
            $patenteShort = substr(trim($patenteFull), 0, 7);
            $id_tracker = (string)($sheet->entity_ids[0] ?? '');
            if (empty($id_tracker)) continue;

            $sections = $sheet->sections ?? [];
            foreach ($sections as $sec) {
                $rows = $sec->data[0]->rows ?? [];
                foreach ($rows as $row) {
                    $eventDesc = substr((string)($row->event->v ?? ''), 0, 100);
                    $timeRaw = trim((string)($row->time->v ?? ''));

                    $dateVal = $ayer;
                    $timeVal = '00:00:00';

                    if (!empty($timeRaw)) {
                        $dt = DateTime::createFromFormat('d/m/Y H:i:s', $timeRaw) 
                           ?: DateTime::createFromFormat('d/m/Y H:i', $timeRaw)
                           ?: DateTime::createFromFormat('Y-m-d H:i:s', $timeRaw);
                        if ($dt) {
                            $dateVal = $dt->format('Y-m-d');
                            $timeVal = $dt->format('H:i:s');
                        }
                    }

                    $latStr = substr((string)($row->address->location->lat ?? '0'), 0, 20);
                    $lngStr = substr((string)($row->address->location->lng ?? '0'), 0, 20);

                    $stmtInsert->bind_param("ssssssss", $cuenta, $id_tracker, $patenteShort, $dateVal, $timeVal, $latStr, $lngStr, $eventDesc);
                    $stmtInsert->execute();
                    $chunkEvents++;
                    $totalEvents++;
                }
            }
        }

        $mysqli->commit();
        echo "  Lote $cNum/$totalChunks completado: $chunkEvents eventos guardados. Total acumulado: $totalEvents\n";
    }

    $stmtInsert->close();

    $elapsed = round(microtime(true) - $startTime, 2);

    echo "-------------------------------------------------------\n";
    echo "  Sincronización manejoBrusco Finalizada con Éxito    \n";
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
    syncManejoBrusco($targetDate);
} else {
    header('Content-Type: application/json');
    try {
        $targetDate = $_GET['fecha'] ?? null;
        $res = syncManejoBrusco($targetDate);
        echo json_encode($res, JSON_PRETTY_PRINT);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

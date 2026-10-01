<?php
/**
 * LPFPullman - API Endpoint para obtener posición y velocidad de trackers en tiempo real
 */

set_time_limit(180);
ini_set('memory_limit', '256M');

require_once __DIR__ . '/config.php';

session_start();

/**
 * Función para obtener token Hash (autenticación)
 */
function getMasGpsHash($user, $password, $dealerId, $forceRefresh = false) {
    $cacheKey = md5($user . $password);
    $cacheFile = __DIR__ . "/hash_cache_{$cacheKey}.json";
    
    if (!$forceRefresh && file_exists($cacheFile)) {
        $cache = json_decode(file_get_contents($cacheFile), true);
        if ($cache && isset($cache['hash']) && isset($cache['time']) && (time() - $cache['time'] < 3600)) {
            return $cache['hash'];
        }
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => MASGPS_API_URL . '/user/auth',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => json_encode([
            "login" => $user,
            "password" => $password,
            "dealer_id" => $dealerId,
            "locale" => "es",
            "hash" => null
        ]),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json'
        ]
    ]);

    $response = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) {
        throw new Exception("Error cURL en autenticación: " . $err);
    }

    $json = json_decode($response, true);
    if (!isset($json['hash']) || empty($json['hash'])) {
        $msg = $json['status']['description'] ?? 'Credenciales o servidor no responde';
        throw new Exception("Autenticación fallida para '$user': " . $msg);
    }

    $hash = $json['hash'];

    file_put_contents($cacheFile, json_encode([
        'hash' => $hash,
        'time' => time()
    ]));

    return $hash;
}

/**
 * Obtener listado de trackers
 */
function getTrackerList($hash) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => MASGPS_API_URL . '/tracker/list',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => json_encode(["hash" => $hash]),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json'
        ]
    ]);

    $response = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) {
        throw new Exception("Error cURL al listar trackers: " . $err);
    }

    $json = json_decode($response, true);
    if (!isset($json['list'])) {
        throw new Exception("Error al obtener lista de trackers: " . json_encode($json));
    }

    return $json['list'];
}

/**
 * Obtener estado (posición y velocidad) de trackers en paralelo mediante curl_multi
 */
function fetchTrackersState($hash, array $trackerList) {
    $results = [];
    $batchSize = 50; // Aumentado a 50 para máxima velocidad en 1,100+ móviles
    $chunks = array_chunk($trackerList, $batchSize);

    foreach ($chunks as $chunk) {
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
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json'
                ]
            ]);

            $curlHandles[$id] = $ch;
            curl_multi_add_handle($mh, $ch);
        }

        $active = null;
        do {
            $mrc = curl_multi_exec($mh, $active);
        } while ($active > 0);

        foreach ($chunk as $item) {
            $id = $item['id'];
            $ch = $curlHandles[$id];
            $response = curl_multi_getcontent($ch);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);

            $stateJson = json_decode($response, true);
            $state = $stateJson['state'] ?? [];
            $gps = $state['gps'] ?? [];
            $location = $gps['location'] ?? [];

            // Extraer velocidad
            $speed = 0;
            if (isset($gps['speed'])) {
                $speed = floatval($gps['speed']);
            } elseif (isset($location['speed'])) {
                $speed = floatval($location['speed']);
            } elseif (isset($state['speed'])) {
                $speed = floatval($state['speed']);
            }

            $lat = isset($location['lat']) ? floatval($location['lat']) : null;
            $lng = isset($location['lng']) ? floatval($location['lng']) : null;
            $connectionStatus = $state['connection_status'] ?? 'desconocido';
            $movementStatus = $state['movement_status'] ?? 'desconocido';
            $lastUpdate = $state['last_update'] ?? ($gps['updated'] ?? 'Sin datos');

            $results[] = [
                'id' => $id,
                'label' => $item['label'] ?? ('Tracker #' . $id),
                'imei' => $item['source']['device_id'] ?? 'N/A',
                'group_id' => $item['group_id'] ?? 0,
                'lat' => $lat,
                'lng' => $lng,
                'speed' => round($speed, 1),
                'connection_status' => $connectionStatus,
                'movement_status' => $movementStatus,
                'last_update' => $lastUpdate
            ];
        }

        curl_multi_close($mh);
    }

    return $results;
}

// Determinar qué cuenta usar
$accountKey = $_GET['account'] ?? 'pullman';
global $ACCOUNTS;

if (!isset($ACCOUNTS[$accountKey])) {
    $accountKey = 'pullman';
}

$accountConfig = $ACCOUNTS[$accountKey];
$user = $accountConfig['user'];
$pass = $accountConfig['password'];
$dealerId = $accountConfig['dealer_id'];

try {
    // Autenticar cuenta seleccionada
    try {
        $hash = getMasGpsHash($user, $pass, $dealerId, false);
    } catch (Exception $e) {
        $hash = getMasGpsHash($user, $pass, $dealerId, true);
    }

    // Obtener lista y estados
    try {
        $trackers = getTrackerList($hash);
    } catch (Exception $e) {
        $hash = getMasGpsHash($user, $pass, $dealerId, true);
        $trackers = getTrackerList($hash);
    }

    $data = fetchTrackersState($hash, $trackers);

    // Calcular resumen
    $summary = [
        'total' => count($data),
        'online' => 0,
        'offline' => 0,
        'moving' => 0,
        'stopped' => 0
    ];

    foreach ($data as $row) {
        $status = strtolower($row['connection_status']);
        if ($status === 'active' || $status === 'online' || $status === 'idle') {
            $summary['online']++;
        } else {
            $summary['offline']++;
        }

        if ($row['speed'] > 0) {
            $summary['moving']++;
        } else {
            $summary['stopped']++;
        }
    }

    $responsePayload = [
        'success' => true,
        'account' => [
            'key' => $accountKey,
            'name' => $accountConfig['name'],
            'user' => $user
        ],
        'timestamp' => date('Y-m-d H:i:s'),
        'summary' => $summary,
        'data' => $data
    ];

} catch (Exception $e) {
    $responsePayload = [
        'success' => false,
        'account' => [
            'key' => $accountKey,
            'name' => $accountConfig['name'],
            'user' => $user
        ],
        'error' => $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ];
}

// Salida JSON
if (php_sapi_name() !== 'cli') {
    header('Content-Type: application/json');
    echo json_encode($responsePayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
} else {
    echo json_encode($responsePayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
}

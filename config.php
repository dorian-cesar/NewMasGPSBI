<?php
/**
 * LPFPullman - Configuración de API MasGPS
 */

define('MASGPS_API_URL', 'http://www.trackermasgps.com/api-v2');

// Credenciales oficiales para Pullman
define('DEFAULT_MASGPS_USER', 'monitoreogps@pullman.cl');
define('DEFAULT_MASGPS_PASSWORD', 'Monitoreo_GPS2025');
define('DEFAULT_MASGPS_DEALER_ID', 10004282);

// Lista de cuentas disponibles para alternar desde la UI
$ACCOUNTS = [
    'pullman' => [
        'name' => 'Pullman Principal (1,135 Móviles)',
        'user' => 'monitoreogps@pullman.cl',
        'password' => 'Monitoreo_GPS2025',
        'dealer_id' => 10004282
    ],
    'bi_pullman' => [
        'name' => 'BI Pullman (Tandem)',
        'user' => 'bi_pullman@masgps.com',
        'password' => 'BI_2024',
        'dealer_id' => 10004282
    ],
    'araucania' => [
        'name' => 'Monitoreo Araucanía Sur',
        'user' => 'monitoreo_araucaniasur@masgps.la',
        'password' => 'Monitoreo_Araucania',
        'dealer_id' => 10004282
    ],
    'ventanas' => [
        'name' => 'Minera Ventanas',
        'user' => 'mineraventanas@masgps.com',
        'password' => 'Ventanas2023',
        'dealer_id' => 10004282
    ],
    'lascondes' => [
        'name' => 'Monitoreo Las Condes',
        'user' => 'monitoreogps_lascondes@wit.la',
        'password' => 'MonitoreoLasCondes',
        'dealer_id' => 10004282
    ],
    'integral' => [
        'name' => 'Proyecto Integral',
        'user' => 'proyecto_integral@masgps.com',
        'password' => 'Integral_2024',
        'dealer_id' => 10004282
    ]
];

date_default_timezone_set('America/Santiago');

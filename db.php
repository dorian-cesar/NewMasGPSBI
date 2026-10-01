<?php
/**
 * LPFPullman - Conexión a Base de Datos AWS RDS
 */

define('DB_HOST', 'masgps.c6xou04wqeof.us-east-1.rds.amazonaws.com');
define('DB_USER', 'admin');
define('DB_PASS', 'BIWEHB?NtOi6GPo.WaKD-Uvy[I9F');
define('DB_NAME', 'masGPS');

function getDbConnection() {
    mysqli_report(MYSQLI_REPORT_OFF);
    $mysqli = @new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

    if ($mysqli->connect_errno) {
        // Intento secundario si el nombre de la BD tiene minúsculas
        $mysqli = @new mysqli(DB_HOST, DB_USER, DB_PASS, 'masgps');
    }

    if ($mysqli->connect_errno) {
        throw new Exception("Error al conectar a la Base de Datos AWS RDS: " . $mysqli->connect_error);
    }

    $mysqli->set_charset("utf8mb4");
    return $mysqli;
}

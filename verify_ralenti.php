<?php
require_once __DIR__ . '/db.php';

$mysqli = getDbConnection();
$res = $mysqli->query("SELECT COUNT(*) as active_idle FROM ralentiPullman WHERE idle != '00:00:00' OR in_movement != '00:00:00'");
$row = $res->fetch_assoc();

echo "Móviles con tiempo registrado de ralenti/movimiento: " . $row['active_idle'] . "\n";

$resData = $mysqli->query("SELECT id, contrato, id_tracker, patente, fecha, duration, in_movement, idle FROM ralentiPullman WHERE idle != '00:00:00' OR in_movement != '00:00:00' LIMIT 5");
print_r($resData->fetch_all(MYSQLI_ASSOC));

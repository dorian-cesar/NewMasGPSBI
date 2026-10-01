<?php
require_once __DIR__ . '/db.php';

$mysqli = getDbConnection();
$res = $mysqli->query("SELECT COUNT(*) as total_records FROM lpf");
$row = $res->fetch_assoc();
echo "TOTAL RECORDS IN TABLA lpf: " . $row['total_records'] . "\n";

$res2 = $mysqli->query("SELECT id_tracker, patente, imei, lat, `long`, last_update, connection_status FROM lpf LIMIT 5");
echo "FIRST 5 RECORDS:\n";
print_r($res2->fetch_all(MYSQLI_ASSOC));

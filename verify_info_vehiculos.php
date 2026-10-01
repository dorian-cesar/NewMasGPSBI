<?php
require_once __DIR__ . '/db.php';

$mysqli = getDbConnection();
$res = $mysqli->query("SELECT COUNT(*) as total FROM infoVehiculos");
$row = $res->fetch_assoc();

echo "VERIFICACIÓN BASE DE DATOS DESTINO (masGPS):\n";
echo "Total de filas en tabla 'infoVehiculos': " . $row['total'] . "\n\n";

echo "Muestra de los primeros 3 registros:\n";
$resData = $mysqli->query("SELECT id, patente, nroInterno, centroCosto, marcaChasis, modeloChasis, flota, ubicacion FROM infoVehiculos LIMIT 3");
print_r($resData->fetch_all(MYSQLI_ASSOC));

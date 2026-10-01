<?php
require_once __DIR__ . '/db.php';

$mysqli = getDbConnection();
$res = $mysqli->query("SELECT COUNT(*) as total FROM unidadNegocio");
$row = $res->fetch_assoc();

echo "VERIFICACIÓN EN BASE DE DATOS DESTINO (masGPS):\n";
echo "Total de filas en la tabla 'unidadNegocio': " . $row['total'] . "\n\n";

$resData = $mysqli->query("SELECT * FROM unidadNegocio");
print_r($resData->fetch_all(MYSQLI_ASSOC));

<?php
// Script de diagnóstico para verificar configuración de PHP y CURL

echo "<h2>Configuración de PHP</h2>";
echo "<pre>";
echo "max_execution_time: " . ini_get('max_execution_time') . " segundos\n";
echo "max_input_time: " . ini_get('max_input_time') . " segundos\n";
echo "memory_limit: " . ini_get('memory_limit') . "\n";
echo "default_socket_timeout: " . ini_get('default_socket_timeout') . " segundos\n";
echo "upload_max_filesize: " . ini_get('upload_max_filesize') . "\n";
echo "post_max_size: " . ini_get('post_max_size') . "\n";
echo "</pre>";

echo "<h2>Información de CURL</h2>";
echo "<pre>";
if (function_exists('curl_version')) {
    $curl_info = curl_version();
    echo "CURL version: " . $curl_info['version'] . "\n";
    echo "SSL version: " . $curl_info['ssl_version'] . "\n";
    echo "Protocols: " . implode(', ', $curl_info['protocols']) . "\n";
} else {
    echo "CURL no está disponible\n";
}
echo "</pre>";

echo "<h2>Test de timeout CURL</h2>";
echo "<pre>";
$ch = curl_init('https://httpbin.org/delay/5');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 0,
    CURLOPT_TIMEOUT_MS => 0,
    CURLOPT_NOSIGNAL => 1,
]);

$start = microtime(true);
$result = curl_exec($ch);
$time = microtime(true) - $start;
$error = curl_error($ch);
curl_close($ch);

echo "Tiempo de ejecución: " . round($time, 2) . " segundos\n";
if ($error) {
    echo "Error: $error\n";
} else {
    echo "✓ Test exitoso - CURL puede hacer peticiones largas\n";
}
echo "</pre>";

echo "<h2>Archivo php.ini en uso</h2>";
echo "<pre>";
echo php_ini_loaded_file() . "\n";
echo "</pre>";

echo "<h2>Cambios con ini_set()</h2>";
echo "<pre>";
echo "Intentando cambiar max_execution_time a 0...\n";
$result = @ini_set('max_execution_time', 0);
if ($result !== false) {
    echo "✓ Cambio exitoso - nuevo valor: " . ini_get('max_execution_time') . "\n";
} else {
    echo "✗ No se pudo cambiar (puede estar en modo seguro)\n";
}

echo "\nIntentando cambiar default_socket_timeout a 0...\n";
$result = @ini_set('default_socket_timeout', 0);
if ($result !== false) {
    echo "✓ Cambio exitoso - nuevo valor: " . ini_get('default_socket_timeout') . "\n";
} else {
    echo "✗ No se pudo cambiar\n";
}
echo "</pre>";

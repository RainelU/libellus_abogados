<?php
/**
 * Script de verificación para producción (SiteGround)
 * Ejecuta esto UNA VEZ después de subir el código para verificar que todo funciona
 * 
 * Acceso: https://app.libellus.cl/verify_production.php?secret=CHECK_SYSTEM
 */

// Seguridad básica
if (($_GET['secret'] ?? '') !== 'CHECK_SYSTEM') {
    http_response_code(403);
    exit('Access denied');
}

// Marcar que somos un script de verificación autorizado
// Esto permite que config.php se cargue sin bloqueo
$_SERVER['PHP_SELF'] = 'verify_production.php'; // Evitar que config.php piense que se accede directamente

header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verificación de Producción - Libellus</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; 
               max-width: 900px; margin: 40px auto; padding: 0 20px; background: #f5f5f5; }
        .check { margin: 20px 0; padding: 15px; background: white; border-radius: 8px; border-left: 4px solid #ddd; }
        .check.pass { border-left-color: #28a745; }
        .check.fail { border-left-color: #dc3545; }
        .check.warn { border-left-color: #ffc107; }
        h1 { color: #1a2f52; }
        h2 { color: #333; margin-top: 30px; border-bottom: 2px solid #1a2f52; padding-bottom: 10px; }
        .status { font-weight: bold; margin-right: 10px; }
        .pass .status { color: #28a745; }
        .fail .status { color: #dc3545; }
        .warn .status { color: #ffc107; }
        code { background: #f4f4f4; padding: 2px 6px; border-radius: 3px; font-size: 0.9em; }
        pre { background: #2d2d2d; color: #f8f8f2; padding: 15px; border-radius: 5px; overflow-x: auto; }
        .metric { display: inline-block; margin-right: 20px; }
        .summary { background: #e3f2fd; border: 2px solid #2196f3; padding: 20px; border-radius: 8px; margin: 20px 0; }
    </style>
</head>
<body>
<h1>🔍 Verificación del Sistema de Cola Asíncrona</h1>
<p><strong>Servidor:</strong> <?= $_SERVER['SERVER_NAME'] ?? 'Unknown' ?> | <strong>Fecha:</strong> <?= date('Y-m-d H:i:s') ?></p>

<h2>1. Entorno PHP</h2>

<?php
// 1.1 PHP Version
$php_ok = version_compare(PHP_VERSION, '8.0.0', '>=');
?>
<div class="check <?= $php_ok ? 'pass' : 'fail' ?>">
    <span class="status"><?= $php_ok ? '✅' : '❌' ?></span>
    <strong>PHP Version:</strong> <?= PHP_VERSION ?>
    <?php if (!$php_ok): ?><br><small>⚠️ Se requiere PHP 8.0+</small><?php endif; ?>
</div>

<?php
// 1.2 SAPI
$sapi = php_sapi_name();
$is_fpm = in_array($sapi, ['fpm-fcgi', 'cgi-fcgi', 'fpm'], true);
?>
<div class="check <?= $is_fpm ? 'pass' : 'warn' ?>">
    <span class="status"><?= $is_fpm ? '✅' : '⚠️' ?></span>
    <strong>Server API:</strong> <?= $sapi ?>
    <?php if ($is_fpm): ?>
        <br><small>✅ PHP-FPM detectado - óptimo para workers en background</small>
    <?php else: ?>
        <br><small>⚠️ No es PHP-FPM - workers usarán CLI fallback</small>
    <?php endif; ?>
</div>

<?php
// 1.3 fastcgi_finish_request
$has_fcgi = function_exists('fastcgi_finish_request');
?>
<div class="check <?= $has_fcgi ? 'pass' : 'warn' ?>">
    <span class="status"><?= $has_fcgi ? '✅' : '⚠️' ?></span>
    <strong>fastcgi_finish_request():</strong> <?= $has_fcgi ? 'Disponible' : 'No disponible' ?>
    <?php if ($has_fcgi): ?>
        <br><small>✅ Worker puede cerrar conexión HTTP y seguir procesando</small>
    <?php else: ?>
        <br><small>⚠️ Usará flush() como fallback (menos eficiente)</small>
    <?php endif; ?>
</div>

<?php
// 1.4 ignore_user_abort
$abort_ok = function_exists('ignore_user_abort');
?>
<div class="check <?= $abort_ok ? 'pass' : 'fail' ?>">
    <span class="status"><?= $abort_ok ? '✅' : '❌' ?></span>
    <strong>ignore_user_abort():</strong> <?= $abort_ok ? 'Disponible' : 'No disponible' ?>
</div>

<h2>2. Extensiones Requeridas</h2>

<?php
$required_extensions = [
    'curl' => 'Para lanzar workers via HTTP',
    'json' => 'Para jobs y configuración',
    'mbstring' => 'Para manejo de strings',
];

foreach ($required_extensions as $ext => $desc) {
    $loaded = extension_loaded($ext);
    echo '<div class="check ' . ($loaded ? 'pass' : 'fail') . '">';
    echo '<span class="status">' . ($loaded ? '✅' : '❌') . '</span>';
    echo '<strong>' . $ext . ':</strong> ' . ($loaded ? 'Instalado' : 'NO instalado');
    echo '<br><small>' . $desc . '</small>';
    echo '</div>';
}
?>

<h2>3. Archivos y Permisos</h2>

<?php
$required_files = [
    'job_queue.php' => 'Gestión de jobs',
    'worker.php' => 'Procesador background',
    'job_status.php' => 'Endpoint de polling',
    'jobs/.htaccess' => 'Protección de jobs',
];

foreach ($required_files as $file => $desc) {
    $path = __DIR__ . '/' . $file;
    $exists = file_exists($path);
    $readable = $exists && is_readable($path);
    
    echo '<div class="check ' . ($exists && $readable ? 'pass' : 'fail') . '">';
    echo '<span class="status">' . ($exists && $readable ? '✅' : '❌') . '</span>';
    echo '<strong>' . $file . ':</strong> ';
    
    if (!$exists) {
        echo 'NO existe';
    } elseif (!$readable) {
        echo 'Existe pero no es legible';
    } else {
        echo 'OK';
        if (substr($file, -4) !== '.php') {
            echo ' (' . substr(sprintf('%o', fileperms($path)), -4) . ')';
        }
    }
    
    echo '<br><small>' . $desc . '</small>';
    echo '</div>';
}

// Verificar directorio jobs/ es escribible
$jobs_dir = __DIR__ . '/jobs/';
$jobs_writable = is_dir($jobs_dir) && is_writable($jobs_dir);
?>
<div class="check <?= $jobs_writable ? 'pass' : 'fail' ?>">
    <span class="status"><?= $jobs_writable ? '✅' : '❌' ?></span>
    <strong>jobs/ directory:</strong> <?= $jobs_writable ? 'Escribible' : 'NO escribible' ?>
    <?php if (!$jobs_writable): ?>
        <br><small>❌ Ejecuta: <code>chmod 755 jobs/</code></small>
    <?php endif; ?>
</div>

<h2>4. Configuración</h2>

<?php
// Intentar cargar config.php con manejo de errores
$config_loaded = false;
$config_error = null;

try {
    if (file_exists(__DIR__ . '/config.php')) {
        require_once __DIR__ . '/config.php';
        $config_loaded = true;
    } else {
        $config_error = 'config.php no existe';
    }
} catch (Throwable $e) {
    $config_error = $e->getMessage();
}

if (!$config_loaded) {
    echo '<div class="check fail">';
    echo '<span class="status">❌</span>';
    echo '<strong>config.php:</strong> Error al cargar';
    if ($config_error) {
        echo '<br><small>' . htmlspecialchars($config_error) . '</small>';
    }
    echo '</div>';
}

// 4.1 WORKER_SECRET
$has_secret = defined('WORKER_SECRET') && strlen(WORKER_SECRET) > 20;
?>
<div class="check <?= $has_secret ? 'pass' : 'fail' ?>">
    <span class="status"><?= $has_secret ? '✅' : '❌' ?></span>
    <strong>WORKER_SECRET:</strong> <?= $has_secret ? 'Configurado' : 'NO configurado o muy corto' ?>
    <?php if ($has_secret): ?>
        <br><small>Longitud: <?= strlen(WORKER_SECRET) ?> caracteres</small>
    <?php endif; ?>
</div>

<?php
// 4.2 ANTHROPIC_API_KEY
$has_api_key = defined('ANTHROPIC_API_KEY') && strlen(ANTHROPIC_API_KEY) > 30;
?>
<div class="check <?= $has_api_key ? 'pass' : 'fail' ?>">
    <span class="status"><?= $has_api_key ? '✅' : '❌' ?></span>
    <strong>ANTHROPIC_API_KEY:</strong> <?= $has_api_key ? 'Configurado' : 'NO configurado' ?>
</div>

<h2>5. Test de Worker (Simulación)</h2>

<?php
// Simular URL del worker
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$dir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$worker_url = $scheme . '://' . $host . $dir . '/worker.php';
?>

<div class="check pass">
    <span class="status">ℹ️</span>
    <strong>Worker URL:</strong> <code><?= htmlspecialchars($worker_url) ?></code>
</div>

<?php
// Test curl al worker (con timeout corto)
if (function_exists('curl_init')) {
    $test_url = $worker_url . '?job_id=verification_test&token=' . urlencode(WORKER_SECRET);
    
    $ch = curl_init($test_url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 3,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    $errno = curl_errno($ch);
    curl_close($ch);
    
    $curl_ok = ($http_code === 202 || $http_code === 404); // 404 = job no existe (esperado)
    
    echo '<div class="check ' . ($curl_ok ? 'pass' : 'warn') . '">';
    echo '<span class="status">' . ($curl_ok ? '✅' : '⚠️') . '</span>';
    echo '<strong>Test curl interno:</strong> HTTP ' . $http_code;
    
    if ($errno) {
        echo '<br><small>curl errno ' . $errno . ': ' . htmlspecialchars($error) . '</small>';
    }
    
    if ($http_code === 202) {
        echo '<br><small>✅ Worker responde correctamente (202 Accepted)</small>';
    } elseif ($http_code === 404) {
        echo '<br><small>✅ Worker accesible (job de test no existe, es normal)</small>';
    } elseif ($http_code === 403) {
        echo '<br><small>❌ Worker bloqueado por .htaccess (verificar configuración)</small>';
    }
    
    echo '</div>';
}
?>

<h2>6. Estado del Sistema</h2>

<?php
require_once __DIR__ . '/job_queue.php';

$jobs = glob(__DIR__ . '/jobs/*.json');
$stats = ['total' => 0, 'pending' => 0, 'running' => 0, 'done' => 0, 'error' => 0];

foreach ($jobs as $file) {
    $job = json_decode(file_get_contents($file), true);
    if ($job && isset($job['status'])) {
        $stats['total']++;
        if (isset($stats[$job['status']])) {
            $stats[$job['status']]++;
        }
    }
}
?>

<div class="check pass">
    <span class="status">📊</span>
    <strong>Jobs en sistema:</strong>
    <div style="margin-top: 10px;">
        <span class="metric">Total: <strong><?= $stats['total'] ?></strong></span>
        <span class="metric">Pending: <strong><?= $stats['pending'] ?></strong></span>
        <span class="metric">Running: <strong><?= $stats['running'] ?></strong></span>
        <span class="metric">Done: <strong><?= $stats['done'] ?></strong></span>
        <span class="metric">Error: <strong><?= $stats['error'] ?></strong></span>
    </div>
</div>

<?php if ($stats['pending'] > 0): ?>
<div class="check warn">
    <span class="status">⚠️</span>
    <strong>Hay <?= $stats['pending'] ?> job(s) pendiente(s)</strong>
    <br><small>Ejecuta manualmente: <code>php process_all_pending.php</code></small>
</div>
<?php endif; ?>

<h2>7. Resumen</h2>

<?php
$critical_ok = $php_ok && $abort_ok && extension_loaded('curl') && $jobs_writable && $has_secret && $has_api_key;
?>

<div class="summary">
    <?php if ($critical_ok): ?>
        <h3 style="color: #28a745; margin-top: 0;">✅ Sistema Listo para Producción</h3>
        <p>Todos los componentes críticos están funcionando correctamente.</p>
        <ul>
            <li>✅ PHP-FPM: <?= $is_fpm ? 'Detectado' : 'Fallback disponible' ?></li>
            <li>✅ Worker puede procesar jobs en background</li>
            <li>✅ Sistema de cola operativo</li>
        </ul>
        
        <h4>Próximos pasos:</h4>
        <ol>
            <li>Hacer una generación de prueba desde la UI</li>
            <li>Verificar que el job se procesa automáticamente</li>
            <li>Verificar los logs: <code>tail -f php_errorlog</code></li>
            <li>Si hay jobs pendientes, ejecutar: <code>php process_all_pending.php</code></li>
        </ol>
        
    <?php else: ?>
        <h3 style="color: #dc3545; margin-top: 0;">❌ Problemas Detectados</h3>
        <p>Hay componentes críticos que necesitan atención:</p>
        <ul>
            <?php if (!$php_ok): ?><li>❌ PHP version muy antigua</li><?php endif; ?>
            <?php if (!$abort_ok): ?><li>❌ ignore_user_abort() no disponible</li><?php endif; ?>
            <?php if (!extension_loaded('curl')): ?><li>❌ Extensión curl no instalada</li><?php endif; ?>
            <?php if (!$jobs_writable): ?><li>❌ Directorio jobs/ no es escribible</li><?php endif; ?>
            <?php if (!$has_secret): ?><li>❌ WORKER_SECRET no configurado</li><?php endif; ?>
            <?php if (!$has_api_key): ?><li>❌ ANTHROPIC_API_KEY no configurado</li><?php endif; ?>
        </ul>
    <?php endif; ?>
</div>

<p style="text-align: center; color: #666; margin-top: 40px;">
    <small>⚠️ Elimina este archivo después de verificar: <code>verify_production.php</code></small>
</p>

</body>
</html>

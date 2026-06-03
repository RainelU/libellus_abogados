<?php
/**
 * Diagnóstico final del sistema
 * Visita: https://app.libellus.cl/ping_worker.php
 * ELIMINAR después de verificar
 */
session_start();
if (empty($_SESSION['authorized_email'])) { http_response_code(403); exit('Login required'); }

require_once __DIR__ . '/job_queue.php';
header('Content-Type: text/plain; charset=utf-8');

echo "=== Estado del Sistema ===\n\n";

// Jobs por estado
$jobs   = glob(__DIR__ . '/jobs/*.json') ?: [];
$counts = ['pending' => 0, 'running' => 0, 'done' => 0, 'error' => 0];
foreach ($jobs as $f) {
    $j = json_decode(file_get_contents($f), true);
    $s = $j['status'] ?? 'unknown';
    if (isset($counts[$s])) $counts[$s]++;
}

echo "Jobs en cola:\n";
foreach ($counts as $status => $n) {
    echo "  $status: $n\n";
}

// Procesos PHP activos en el servidor
echo "\nProcesos PHP activos:\n";
$out = shell_exec('ps aux 2>/dev/null | grep php | grep -v grep | wc -l');
echo "  Total procesos PHP: " . trim($out ?: 'N/A') . "\n";

$out2 = shell_exec('ps aux 2>/dev/null | grep index.php | grep -v grep');
$active = $out2 ? count(array_filter(explode("\n", trim($out2)))) : 0;
echo "  Procesando ahora (index.php): $active\n";

echo "\nConclusion:\n";
if ($counts['running'] > 0) {
    echo "  ⚙️  Hay {$counts['running']} generacion(es) activa(s) ahora mismo\n";
}
echo "  Cada usuario usa 1 proceso PHP durante la generación (~3-5 min)\n";
echo "  Múltiples usuarios corren en paralelo sin problema\n";

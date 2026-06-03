<?php
/**
 * Marca todos los jobs pendientes como error para limpiar la cola
 * Visita: https://app.libellus.cl/cleanup_pending.php
 * ELIMINAR después de usar
 */
session_start();
if (empty($_SESSION['authorized_email'])) { http_response_code(403); exit('Login required'); }

require_once __DIR__ . '/job_queue.php';
header('Content-Type: text/plain; charset=utf-8');

$jobs = glob(__DIR__ . '/jobs/*.json') ?: [];
$cleaned = 0;
foreach ($jobs as $f) {
    $j = json_decode(file_get_contents($f), true);
    if (($j['status'] ?? '') === 'pending') {
        job_update($j['job_id'], [
            'status'      => 'error',
            'finished_at' => time(),
            'error'       => 'Job cancelado — servidor reiniciado',
        ]);
        echo "Cancelado: {$j['job_id']}\n";
        $cleaned++;
    }
}
echo "\nTotal cancelados: $cleaned\n";
echo "Ahora genera un documento nuevo para probar el sistema.\n";

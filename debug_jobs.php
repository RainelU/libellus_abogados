<?php
/**
 * Debug: muestra estado de todos los jobs y archivos generados
 * Acceso: https://app.libellus.cl/debug_jobs.php?secret=DEBUG_NOW
 */
session_start();
if (($_GET['secret'] ?? '') !== 'DEBUG_NOW' && ($_SESSION['user_role'] ?? '') !== 'ADMIN') {
    http_response_code(403); exit('Forbidden');
}

require_once __DIR__ . '/job_queue.php';

header('Content-Type: text/plain; charset=utf-8');

echo "=== DEBUG JOBS ===\n";
echo "Fecha: " . date('Y-m-d H:i:s') . "\n\n";

// 1. Jobs
echo "=== JOBS ===\n";
$jobs = glob(__DIR__ . '/jobs/*.json') ?: [];
if (empty($jobs)) {
    echo "No hay jobs.\n";
} else {
    foreach ($jobs as $file) {
        $job = json_decode(file_get_contents($file), true);
        if (!$job) continue;
        echo "ID: {$job['job_id']}\n";
        echo "  Status: {$job['status']}\n";
        echo "  Email: {$job['data']['user_email']}\n";
        echo "  Creado: " . date('Y-m-d H:i:s', $job['created_at']) . "\n";
        if ($job['started_at'])  echo "  Iniciado: " . date('Y-m-d H:i:s', $job['started_at']) . "\n";
        if ($job['finished_at']) echo "  Finalizado: " . date('Y-m-d H:i:s', $job['finished_at']) . "\n";
        if ($job['status'] === 'done' && !empty($job['result']['files'])) {
            foreach ($job['result']['files'] as $f) {
                $url     = $f['url'] ?? 'N/A';
                $fname   = $f['filename'] ?? 'N/A';
                $fpath   = __DIR__ . '/downloads/' . $fname;
                $exists  = file_exists($fpath);
                echo "  Archivo: $fname\n";
                echo "  URL: $url\n";
                echo "  Existe en disco: " . ($exists ? 'SÍ (' . filesize($fpath) . ' bytes)' : 'NO ← PROBLEMA') . "\n";
            }
        }
        if ($job['status'] === 'error') {
            echo "  Error: {$job['error']}\n";
        }
        echo "\n";
    }
}

// 2. Archivos en downloads/
echo "=== ARCHIVOS EN downloads/ ===\n";
$downloads = glob(__DIR__ . '/downloads/*.docx') ?: [];
if (empty($downloads)) {
    echo "No hay archivos .docx en downloads/\n";
} else {
    foreach ($downloads as $f) {
        echo basename($f) . " (" . round(filesize($f)/1024, 1) . " KB) - " . date('Y-m-d H:i:s', filemtime($f)) . "\n";
    }
}

echo "\n=== FIN ===\n";

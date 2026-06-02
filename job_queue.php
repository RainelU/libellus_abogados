<?php
/**
 * Job Queue System — Gestión de trabajos asíncronos
 * 
 * Cada job se almacena como un archivo JSON en el directorio jobs/
 * Estados posibles: pending → running → done | error
 */

if (php_sapi_name() !== 'cli' && basename($_SERVER['PHP_SELF']) === 'job_queue.php') {
    http_response_code(403);
    exit;
}

define('JOBS_DIR', __DIR__ . '/jobs/');

// Crear directorio si no existe
if (!is_dir(JOBS_DIR)) {
    mkdir(JOBS_DIR, 0755, true);
}

/**
 * Crear nuevo job en estado 'pending'
 * 
 * @param array $data Datos del job (skill_id, pdf_file_ids, user_email)
 * @return string job_id (MD5 único)
 */
function job_create(array $data): string {
    $job_id = md5(uniqid('job_', true));
    $path   = JOBS_DIR . $job_id . '.json';

    $job = [
        'job_id'       => $job_id,
        'status'       => 'pending',
        'created_at'   => time(),
        'started_at'   => null,
        'finished_at'  => null,
        'data'         => $data,
        'result'       => null,
        'error'        => null,
    ];

    file_put_contents($path, json_encode($job, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    return $job_id;
}

/**
 * Leer job por ID
 * 
 * @param string $job_id
 * @return array|null Datos del job o null si no existe
 */
function job_read(string $job_id): ?array {
    $path = JOBS_DIR . $job_id . '.json';
    if (!file_exists($path)) {
        return null;
    }

    $content = file_get_contents($path);
    $job     = json_decode($content, true);

    return $job ?: null;
}

/**
 * Actualizar job
 * 
 * @param string $job_id
 * @param array $updates Campos a actualizar (status, started_at, finished_at, result, error)
 * @return bool true si se actualizó, false si no existe
 */
function job_update(string $job_id, array $updates): bool {
    $job = job_read($job_id);
    if (!$job) {
        return false;
    }

    foreach ($updates as $key => $value) {
        $job[$key] = $value;
    }

    $path = JOBS_DIR . $job_id . '.json';
    file_put_contents($path, json_encode($job, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    return true;
}

/**
 * Obtener tiempo transcurrido desde que empezó el job
 * 
 * @param string $job_id
 * @return int Segundos transcurridos, 0 si no ha empezado o no existe
 */
function job_elapsed(string $job_id): int {
    $job = job_read($job_id);
    if (!$job || !$job['started_at']) {
        return 0;
    }

    $end = $job['finished_at'] ?? time();
    return max(0, $end - $job['started_at']);
}

/**
 * Limpiar jobs antiguos (>24h completados o con error)
 * Llamar periódicamente desde cron o al inicio del worker
 */
function job_cleanup(): void {
    $cutoff = time() - (24 * 60 * 60); // 24 horas

    foreach (glob(JOBS_DIR . '*.json') as $file) {
        $job = json_decode(file_get_contents($file), true);
        if (!$job) continue;

        if (in_array($job['status'], ['done', 'error'], true) && $job['finished_at'] < $cutoff) {
            unlink($file);
        }
    }
}

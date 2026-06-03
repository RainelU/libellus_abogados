<?php
/**
 * Procesa un job individual — llamado por cron_worker.php en background
 * Uso: /usr/local/php83/bin/php process_job.php <job_id>
 *
 * Cada instancia de este script maneja UN job independientemente.
 * Múltiples instancias corren en paralelo sin bloquearse entre sí.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

@set_time_limit(600); // 10 minutos máximo por job

$job_id = $argv[1] ?? null;

if (!$job_id) {
    error_log('[process_job] ERROR: No job_id provided');
    exit(1);
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/job_queue.php';
require_once __DIR__ . '/generation_log.php';
require_once __DIR__ . '/claude.php';

error_log("[process_job] START $job_id");

$job = job_read($job_id);

if (!$job) {
    error_log("[process_job] Job not found: $job_id");
    exit(1);
}

// Aceptar running (el cron lo marcó) o pending
if (!in_array($job['status'], ['pending', 'running'])) {
    error_log("[process_job] Job $job_id already in status: {$job['status']}");
    exit(0);
}

// Asegurar que está en running
if ($job['status'] === 'pending') {
    job_update($job_id, ['status' => 'running', 'started_at' => time()]);
}

try {
    $max_retries = 3;
    $last_error  = null;

    for ($attempt = 1; $attempt <= $max_retries; $attempt++) {
        try {
            if ($attempt > 1) {
                $wait = $attempt * 10; // 10s, 20s entre reintentos
                error_log("[process_job] Retry $attempt/$max_retries para $job_id (esperando {$wait}s)");
                sleep($wait);
            }

            $t0     = microtime(true);
            $result = claude_generate_demand([
                'skill_id'     => $job['data']['skill_id'],
                'pdf_file_ids' => $job['data']['pdf_file_ids'],
            ]);
            $elapsed = round(microtime(true) - $t0, 2);

            if (!$result['success']) {
                throw new Exception($result['error'] ?? 'Error desconocido de Claude API');
            }

            // Éxito — salir del loop de reintentos
            $last_error = null;
            break;

        } catch (Throwable $e) {
            $last_error = $e;
            $msg = $e->getMessage();
            error_log("[process_job] Attempt $attempt FAILED para $job_id: $msg");

            // Errores que no vale la pena reintentar
            $fatal = str_contains($msg, 'invalid_api_key')
                  || str_contains($msg, 'permission_denied')
                  || str_contains($msg, 'not_found');
            if ($fatal) break;
        }
    }

    if ($last_error) {
        throw $last_error;
    }

    log_generation([
        'filename'      => $result['filename'],
        'email'         => $job['data']['user_email'],
        'model'         => $result['model']                  ?? CLAUDE_MODEL,
        'input_tokens'  => $result['usage']['input_tokens']  ?? 0,
        'output_tokens' => $result['usage']['output_tokens'] ?? 0,
        'elapsed'       => $elapsed,
        'generated_at'  => (new DateTime('now', new DateTimeZone('America/Santiago')))->format('c'),
    ]);

    job_update($job_id, [
        'status'      => 'done',
        'finished_at' => time(),
        'result'      => [
            'type'  => 'files',
            'files' => [[
                'filename' => $result['filename'],
                'url'      => 'download.php?file=' . urlencode($result['filename']),
            ]],
            'usage'   => $result['usage'] ?? null,
            'elapsed' => $elapsed,
            'model'   => $result['model'] ?? CLAUDE_MODEL,
        ],
    ]);

    error_log("[process_job] DONE $job_id en {$elapsed}s → {$result['filename']}");

} catch (Throwable $e) {
    error_log("[process_job] FAIL $job_id → " . $e->getMessage());
    job_update($job_id, [
        'status'      => 'error',
        'finished_at' => time(),
        'error'       => $e->getMessage(),
    ]);
}

exit(0);

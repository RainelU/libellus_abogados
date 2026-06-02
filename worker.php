<?php
/**
 * Worker — Procesador de jobs en background
 * Se ejecuta vía HTTP (curl desde index.php) o CLI (fallback).
 */

@ignore_user_abort(true);
@set_time_limit(600);

// Los requires MÍNIMOS antes de autenticar
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/job_queue.php';

// ── Autenticación ─────────────────────────────────────────────────────────────

$is_cli = php_sapi_name() === 'cli';
$token  = $is_cli ? ($argv[2] ?? null) : ($_GET['token'] ?? null);
$job_id = $is_cli ? ($argv[1] ?? null) : ($_GET['job_id'] ?? null);

if ($token !== WORKER_SECRET) {
    if (!$is_cli) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo '{"error":"forbidden"}';
    }
    error_log('[worker] Auth failed — invalid token');
    exit;
}

if (!$job_id) {
    if (!$is_cli) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo '{"error":"missing job_id"}';
    }
    error_log('[worker] No job_id provided');
    exit;
}

// ── Responder al cliente HTTP inmediatamente y soltar la conexión ─────────────

if (!$is_cli) {
    $body = '{"status":"accepted","job_id":"' . $job_id . '"}';
    http_response_code(202);
    header('Content-Type: application/json');
    header('Connection: close');
    header('Content-Length: ' . strlen($body));
    echo $body;

    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
        error_log('[worker] Connection closed via fastcgi_finish_request()');
    } else {
        if (ob_get_level() > 0) ob_end_flush();
        flush();
        error_log('[worker] Connection closed via flush()');
    }
}

// ── Verificar job ─────────────────────────────────────────────────────────────

error_log("[worker] Starting job: $job_id");

$job = job_read($job_id);

if (!$job) {
    error_log("[worker] Job not found: $job_id");
    exit;
}

if ($job['status'] !== 'pending') {
    error_log("[worker] Job already processed: $job_id (status: {$job['status']})");
    exit;
}

// Marcar como running
job_update($job_id, [
    'status'     => 'running',
    'started_at' => time(),
]);

error_log("[worker] Job $job_id marked as running");

// ── Procesar ──────────────────────────────────────────────────────────────────

try {
    // Cargar dependencias solo cuando realmente las necesitamos
    require_once __DIR__ . '/generation_log.php';
    require_once __DIR__ . '/claude.php';

    $skill_id     = $job['data']['skill_id']     ?? '';
    $pdf_file_ids = $job['data']['pdf_file_ids'] ?? [];
    $user_email   = $job['data']['user_email']   ?? '';

    if (!$skill_id || empty($pdf_file_ids)) {
        throw new Exception('Datos del job inválidos: skill_id o pdf_file_ids vacíos');
    }

    error_log("[worker] Calling Claude API for job: $job_id");

    $start  = microtime(true);
    $result = claude_generate_demand([
        'skill_id'     => $skill_id,
        'pdf_file_ids' => $pdf_file_ids,
    ]);
    $elapsed = round(microtime(true) - $start, 2);

    if (!$result['success']) {
        throw new Exception($result['error'] ?? 'Error desconocido en Claude API');
    }

    // Registrar en log
    $tz      = new DateTimeZone('America/Santiago');
    $now     = new DateTime('now', $tz);

    log_generation([
        'filename'      => $result['filename'],
        'email'         => $user_email,
        'model'         => $result['model'] ?? CLAUDE_MODEL,
        'input_tokens'  => $result['usage']['input_tokens']  ?? 0,
        'output_tokens' => $result['usage']['output_tokens'] ?? 0,
        'elapsed'       => $elapsed,
        'generated_at'  => $now->format('c'),
    ]);

    // Marcar como done
    job_update($job_id, [
        'status'      => 'done',
        'finished_at' => time(),
        'result'      => [
            'type'    => 'files',
            'files'   => [[
                'filename' => $result['filename'],
                'url'      => 'download.php?file=' . urlencode($result['filename']),
            ]],
            'usage'   => $result['usage']   ?? null,
            'elapsed' => $elapsed,
            'model'   => $result['model']   ?? CLAUDE_MODEL,
        ],
    ]);

    error_log("[worker] Job $job_id done in {$elapsed}s — {$result['filename']}");

} catch (Throwable $e) {
    $msg = $e->getMessage();
    error_log("[worker] Job $job_id FAILED: $msg");
    error_log("[worker] Trace: " . $e->getTraceAsString());

    job_update($job_id, [
        'status'      => 'error',
        'finished_at' => time(),
        'error'       => $msg,
    ]);
}

// Limpieza periódica (10% de probabilidad)
if (rand(1, 10) === 1) {
    job_cleanup();
}

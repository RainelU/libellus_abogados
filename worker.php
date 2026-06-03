<?php
/**
 * Worker — llamado desde el navegador vía fetch con keepalive:true
 * Esto garantiza que el proceso PHP siga vivo aunque el usuario
 * cierre la pestaña o cambie de página.
 *
 * El navegador llama: fetch('worker.php', {method:'POST', keepalive:true, body:...})
 * y NO espera la respuesta (fire and forget).
 */

// Lo primero: evitar que el proceso muera si el cliente se desconecta
@ignore_user_abort(true);
@set_time_limit(600);

session_start();

// Solo usuarios autenticados pueden disparar el worker
if (!isset($_SESSION['authorized_email'])) {
    http_response_code(401);
    exit;
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/job_queue.php';

$job_id = $_POST['job_id'] ?? '';
$token  = $_POST['token']  ?? '';

if ($token !== WORKER_SECRET) {
    http_response_code(403);
    error_log("[worker] Auth failed for job: $job_id");
    exit;
}

if (!$job_id) {
    http_response_code(400);
    exit;
}

// Verificar job existe y está pending
$job = job_read($job_id);
if (!$job || $job['status'] !== 'pending') {
    http_response_code(200); // no error — ya procesado o no existe
    exit;
}

// Responder 202 inmediatamente y soltar la conexión
http_response_code(202);
header('Content-Type: application/json');
header('Connection: close');
$body = '{"status":"accepted"}';
header('Content-Length: ' . strlen($body));
echo $body;

// Cerrar sesión y buffer para liberar al cliente
session_write_close();
if (ob_get_level() > 0) ob_end_flush();
flush();

// ── A partir de aquí el cliente ya no espera ─────────────────────────────────

require_once __DIR__ . '/generation_log.php';
require_once __DIR__ . '/claude.php';

error_log("[worker] Starting job: $job_id");
job_update($job_id, ['status' => 'running', 'started_at' => time()]);
error_log("[worker] Job $job_id marked as running");

try {
    $start  = microtime(true);
    $result = claude_generate_demand([
        'skill_id'     => $job['data']['skill_id'],
        'pdf_file_ids' => $job['data']['pdf_file_ids'],
    ]);
    $elapsed = round(microtime(true) - $start, 2);

    if (!$result['success']) {
        throw new Exception($result['error'] ?? 'Error desconocido en Claude API');
    }

    log_generation([
        'filename'      => $result['filename'],
        'email'         => $job['data']['user_email'],
        'model'         => $result['model'] ?? CLAUDE_MODEL,
        'input_tokens'  => $result['usage']['input_tokens']  ?? 0,
        'output_tokens' => $result['usage']['output_tokens'] ?? 0,
        'elapsed'       => $elapsed,
        'generated_at'  => (new DateTime('now', new DateTimeZone('America/Santiago')))->format('c'),
    ]);

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
    error_log("[worker] Job $job_id FAILED: " . $e->getMessage());
    job_update($job_id, [
        'status'      => 'error',
        'finished_at' => time(),
        'error'       => $e->getMessage(),
    ]);
}

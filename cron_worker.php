<?php
/**
 * Cron Worker — FIFO secuencial, completamente autosanante
 *
 * SiteGround cPanel → Cron Jobs → cada 1 minuto:
 *   Comando: /usr/local/php83/bin/php /home/customer/www/app.libellus.cl/public_html/cron_worker.php
 *   Intervalo: * * * * *
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

define('CRON_START',  time());
define('CRON_MAX',    480);   // 8 min máximo por ejecución
define('JOB_TIMEOUT', 600);   // 10 min → job "running" se considera muerto
define('LOCK_FILE',   __DIR__ . '/jobs/.cron.lock');

// ── Lock con flock() — se libera solo aunque PHP muera ───────────────────────
$lock_fp = fopen(LOCK_FILE, 'c');
if (!$lock_fp || !flock($lock_fp, LOCK_EX | LOCK_NB)) {
    // Verificar si el lock está colgado
    if (file_exists(LOCK_FILE) && (time() - filemtime(LOCK_FILE)) > CRON_MAX + 60) {
        @unlink(LOCK_FILE);
        error_log('[cron] Lock colgado eliminado');
    } else {
        exit(0); // Cron sano corriendo, salir
    }
    $lock_fp = fopen(LOCK_FILE, 'c');
    if (!$lock_fp || !flock($lock_fp, LOCK_EX | LOCK_NB)) {
        error_log('[cron] No se pudo adquirir lock');
        exit(1);
    }
}

fwrite($lock_fp, getmypid());
fflush($lock_fp);

register_shutdown_function(function () use ($lock_fp) {
    @flock($lock_fp, LOCK_UN);
    @fclose($lock_fp);
    @unlink(LOCK_FILE);
});

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/job_queue.php';
require_once __DIR__ . '/generation_log.php';
require_once __DIR__ . '/claude.php';

@set_time_limit(CRON_MAX + 60);

// ── Auto-sanación ─────────────────────────────────────────────────────────────
foreach (glob(__DIR__ . '/jobs/*.json') ?: [] as $f) {
    $j = json_decode(file_get_contents($f), true);
    if (!$j) continue;

    // Reset jobs atascados en "running"
    if ($j['status'] === 'running' && isset($j['started_at'])) {
        if ((time() - $j['started_at']) > JOB_TIMEOUT) {
            error_log("[cron] HEAL: {$j['job_id']} atascado → reset a pending");
            job_update($j['job_id'], ['status' => 'pending', 'started_at' => null]);
        }
    }

    // Limpiar completados viejos (+24h)
    if (in_array($j['status'], ['done', 'error']) && isset($j['finished_at'])) {
        if ((time() - $j['finished_at']) > 86400) {
            @unlink($f);
        }
    }
}

// ── Recoger pendientes en orden FIFO (más antiguo primero) ───────────────────
$pending = [];
foreach (glob(__DIR__ . '/jobs/*.json') ?: [] as $f) {
    $j = json_decode(file_get_contents($f), true);
    if ($j && $j['status'] === 'pending') {
        $pending[] = $j;
    }
}

usort($pending, fn($a, $b) => $a['created_at'] <=> $b['created_at']);

if (empty($pending)) {
    exit(0);
}

error_log('[cron] ' . count($pending) . ' job(s) pendiente(s)');

// ── Procesar uno por uno (FIFO) ───────────────────────────────────────────────
foreach ($pending as $job) {
    if ((time() - CRON_START) > CRON_MAX - 60) {
        error_log('[cron] Límite de tiempo — jobs restantes se procesan en el próximo minuto');
        break;
    }

    $job_id = $job['job_id'];

    $fresh = job_read($job_id);
    if (!$fresh || $fresh['status'] !== 'pending') continue;

    error_log("[cron] START $job_id ({$job['data']['user_email']})");
    job_update($job_id, ['status' => 'running', 'started_at' => time()]);

    try {
        $max_retries = 3;
        $last_error  = null;
        $result      = null;

        for ($attempt = 1; $attempt <= $max_retries; $attempt++) {
            try {
                if ($attempt > 1) {
                    $wait = $attempt * 10;
                    error_log("[cron] Retry $attempt/$max_retries para $job_id (wait {$wait}s)");
                    sleep($wait);
                }

                $t0     = microtime(true);
                $result = claude_generate_demand([
                    'skill_id'     => $job['data']['skill_id'],
                    'pdf_file_ids' => $job['data']['pdf_file_ids'],
                ]);
                $elapsed = round(microtime(true) - $t0, 2);

                if (!$result['success']) {
                    throw new Exception($result['error'] ?? 'Error desconocido');
                }

                $last_error = null;
                break;

            } catch (Throwable $e) {
                $last_error = $e;
                error_log("[cron] Attempt $attempt failed: " . $e->getMessage());
                $fatal = str_contains($e->getMessage(), 'invalid_api_key')
                      || str_contains($e->getMessage(), 'permission_denied')
                      || str_contains($e->getMessage(), 'max_tokens');
                if ($fatal) break;
            }
        }

        if ($last_error) throw $last_error;

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

        error_log("[cron] DONE $job_id en {$elapsed}s → {$result['filename']}");

    } catch (Throwable $e) {
        error_log("[cron] FAIL $job_id → " . $e->getMessage());
        job_update($job_id, [
            'status'      => 'error',
            'finished_at' => time(),
            'error'       => $e->getMessage(),
        ]);
    }
}

exit(0);

<?php
session_start();
if (!isset($_SESSION['authorized_email'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/skills.php';
require_once __DIR__ . '/job_queue.php';

// ── Manejo de peticiones POST (AJAX) ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    // Devolver lista de skills disponibles
    if ($action === 'get_skills') {
        echo json_encode(['skills' => get_available_skills()]);
        exit;
    }

    // Encolar generación — el cron_worker.php la procesa automáticamente cada minuto
    if ($action === 'generate') {
        $skill_id     = trim($_POST['skill']        ?? '');
        $pdf_ids_raw  = trim($_POST['pdf_file_ids'] ?? '');
        $pdf_file_ids = [];

        if ($pdf_ids_raw) {
            $decoded = json_decode($pdf_ids_raw, true);
            if (is_array($decoded)) {
                $pdf_file_ids = array_values(array_filter($decoded, 'is_string'));
            }
        }

        if (!$skill_id) {
            echo json_encode(['success' => false, 'error' => 'Seleccioná un skill antes de generar.']);
            exit;
        }
        if (empty($pdf_file_ids)) {
            echo json_encode(['success' => false, 'error' => 'Adjuntá al menos un PDF antes de generar.']);
            exit;
        }

        $job_id = job_create([
            'skill_id'     => $skill_id,
            'pdf_file_ids' => $pdf_file_ids,
            'user_email'   => $_SESSION['authorized_email'] ?? '',
        ]);

        error_log("[queue] Job created: $job_id — waiting for cron");
        echo json_encode(['success' => true, 'job_id' => $job_id]);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Acción desconocida.']);
    exit;
}

/**
 * Procesa un job directamente en este mismo proceso PHP.
 * Se llama DESPUÉS de fastcgi_finish_request(), así que el usuario
 * ya recibió su respuesta y esta función puede tardar minutos sin problema.
 */
function process_job(string $job_id): void {
    require_once __DIR__ . '/generation_log.php';
    require_once __DIR__ . '/claude.php';

    error_log("[worker] Starting job: $job_id");

    $job = job_read($job_id);
    if (!$job || $job['status'] !== 'pending') {
        error_log("[worker] Job $job_id not found or already processed");
        return;
    }

    job_update($job_id, ['status' => 'running', 'started_at' => time()]);
    error_log("[worker] Job $job_id marked as running");

    try {
        $start = microtime(true);

        $result = claude_generate_demand([
            'skill_id'     => $job['data']['skill_id'],
            'pdf_file_ids' => $job['data']['pdf_file_ids'],
        ]);

        $elapsed = round(microtime(true) - $start, 2);

        if (!$result['success']) {
            throw new Exception($result['error'] ?? 'Error desconocido en Claude API');
        }

        // Registrar en log
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
}
?><!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Generador de Demandas — Libellus</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/style.css?v=<?= filemtime(__DIR__ . '/assets/style.css') ?>">
    <link rel="stylesheet" href="assets/libellus-theme.css?v=<?= filemtime(__DIR__ . '/assets/libellus-theme.css') ?>">
    <link rel="icon" type="image/svg+xml" href="favicon.svg">
</head>
<body>

<div class="container py-5" style="max-width:780px">

    <div class="mb-4">
        <h1 class="fs-4 fw-semibold mb-1">Generador de Demandas</h1>
        <p class="text-secondary small mb-0">Adjuntá los PDFs del caso y generá la demanda en formato Word.</p>
    </div>

    <div class="card rounded-3 p-4 mb-3">

        <div class="mb-3">
            <label for="skill-select" class="form-label">Skill</label>
            <select class="form-select" id="skill-select" disabled>
                <option value="">Cargando skills...</option>
            </select>
        </div>

        <div class="mb-3">
            <label class="form-label">Archivos PDF del caso</label>
            <div class="d-flex align-items-center gap-2 flex-wrap">
                <input type="file" id="file-input" class="d-none" multiple accept=".pdf">
                <button type="button" class="btn btn-sm btn-secondary-action" id="attach-btn"
                        title="Adjuntar PDFs" aria-label="Adjuntar PDFs">
                    <i class="bi bi-paperclip me-1"></i> Adjuntar PDFs
                </button>
                <div id="attached-files" class="d-flex gap-2 flex-wrap"></div>
            </div>
            <div id="upload-status" class="mt-2 small text-secondary d-none"></div>
        </div>

        <button class="btn btn-primary w-100" id="generate-btn" disabled>
            <span class="spinner-border spinner-border-sm me-2 d-none" id="spinner"></span>
            <span id="btn-label">Generar Demanda</span>
        </button>

    </div>

    <div class="alert alert-danger d-none" id="error-msg" role="alert"></div>

    <div id="output-section" class="d-none">
        <div id="output-text"></div>
    </div>

    <!-- ── Stats de uso (tokens + tiempo) ──────────────────────────────── -->
    <div id="usage-stats" class="d-none mt-3">
        <div class="card rounded-3 px-4 py-3">
            <div class="d-flex flex-wrap gap-3 align-items-center justify-content-between">
                <div class="d-flex flex-wrap gap-3">
                    <?php if (($_SESSION['user_role'] ?? 'USUARIO') === 'ADMIN'): ?>
                    <div class="usage-stat">
                        <span class="usage-label"><i class="bi bi-arrow-down-circle me-1"></i>Tokens entrada</span>
                        <span class="usage-value" id="stat-input-tokens">—</span>
                    </div>
                    <div class="usage-divider"></div>
                    <div class="usage-stat">
                        <span class="usage-label"><i class="bi bi-arrow-up-circle me-1"></i>Tokens salida</span>
                        <span class="usage-value" id="stat-output-tokens">—</span>
                    </div>
                    <div class="usage-divider"></div>
                    <?php endif; ?>
                    <div class="usage-stat">
                        <span class="usage-label"><i class="bi bi-clock me-1"></i>Tiempo de generación</span>
                        <span class="usage-value" id="stat-elapsed">—</span>
                    </div>
                </div>
                <?php if (($_SESSION['user_role'] ?? 'USUARIO') === 'ADMIN'): ?>
                <div class="usage-stat text-end">
                    <span class="usage-label"><i class="bi bi-cpu me-1"></i>Modelo</span>
                    <span class="usage-value usage-model" id="stat-model">—</span>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    window.LIBELLUS_USER_ROLE = <?= json_encode($_SESSION['user_role'] ?? 'USUARIO') ?>;
    window.WORKER_SECRET      = <?= json_encode(WORKER_SECRET) ?>;
</script>
<script src="assets/app.js?v=<?= filemtime(__DIR__ . '/assets/app.js') ?>"></script>
</body>
</html>

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

    // Encolar generación — responde en <1s con job_id
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

        // Crear job en la cola
        $job_id = job_create([
            'skill_id'     => $skill_id,
            'pdf_file_ids' => $pdf_file_ids,
            'user_email'   => $_SESSION['authorized_email'] ?? '',
        ]);

        // Lanzar worker en background (no bloqueante)
        launch_worker($job_id);

        echo json_encode(['success' => true, 'job_id' => $job_id]);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Acción desconocida.']);
    exit;
}

/**
 * Lanza worker.php en background sin bloquear el request actual.
 * El worker corre con ignore_user_abort(true) para seguir procesando aunque curl corte.
 */
function launch_worker(string $job_id): void {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir    = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
    $url    = $scheme . '://' . $host . $dir . '/worker.php';
    $token  = WORKER_SECRET;
    $params = http_build_query(['job_id' => $job_id, 'token' => $token]);

    $ch = curl_init($url . '?' . $params);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,  // capturar output, NO imprimirlo
        CURLOPT_NOBODY         => false,
        CURLOPT_TIMEOUT_MS     => 5000,  // timeout 5s — worker sigue corriendo después
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_FORBID_REUSE   => true,
        CURLOPT_FRESH_CONNECT  => true,
        CURLOPT_HTTPHEADER     => ['Connection: close', 'X-Worker-Token: ' . $token],
    ]);

    curl_exec($ch);
    $errno = curl_errno($ch);
    curl_close($ch);

    if ($errno !== 0 && $errno !== CURLE_OPERATION_TIMEDOUT) {
        error_log("[queue] curl failed (errno $errno), trying CLI fallback");
        launch_worker_cli($job_id);
    } else {
        error_log("[queue] Launched worker for job: $job_id (curl errno: $errno)");
    }
}

/**
 * Fallback: lanzar worker via PHP CLI (solo para local/desarrollo).
 */
function launch_worker_cli(string $job_id): void {
    $php_bin    = PHP_BINARY ?: 'php';
    $worker     = escapeshellarg(__DIR__ . '/worker.php');
    $job_id_arg = escapeshellarg($job_id);
    $token_arg  = escapeshellarg(WORKER_SECRET);

    if (PHP_OS_FAMILY === 'Windows') {
        $cmd = "start /B \"\" \"{$php_bin}\" {$worker} {$job_id_arg} {$token_arg}";
        pclose(popen($cmd, 'r'));
    } else {
        $cmd = "{$php_bin} {$worker} {$job_id_arg} {$token_arg} > /dev/null 2>&1 &";
        exec($cmd);
    }
    error_log("[queue] Launched worker via CLI for job: $job_id");
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
    // Rol del usuario actual — controlado por el servidor, no modificable desde el cliente
    window.LIBELLUS_USER_ROLE = <?= json_encode($_SESSION['user_role'] ?? 'USUARIO') ?>;
</script>
<script src="assets/app.js?v=<?= filemtime(__DIR__ . '/assets/app.js') ?>"></script>
</body>
</html>

<?php
/**
 * admin.php — Panel de configuración de Libellus
 * Acceso restringido: solo usuarios autenticados con sesión activa Y rol ADMIN.
 * La URL no está enlazada desde ningún lugar público.
 */
session_start();
if (!isset($_SESSION['authorized_email'])) {
    header('Location: login.php');
    exit;
}

// Solo ADMIN puede acceder
if (($_SESSION['user_role'] ?? 'USUARIO') !== 'ADMIN') {
    http_response_code(403);
    exit('Acceso denegado.');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/config_override.php';
require_once __DIR__ . '/generation_log.php';

$success_msg = '';
$error_msg   = '';

// ── Modelos disponibles ───────────────────────────────────────────────────────
$available_models = [
    'claude-opus-4-5' => 'Claude Opus 4.5',
    'claude-opus-4-7' => 'Claude Opus 4.7',
    'claude-opus-4-8' => 'Claude Opus 4.8',
    'claude-sonnet-4-6' => 'Claude Sonnet 4.6',
];

// ── Leer valores actuales ─────────────────────────────────────────────────────
$current_model  = defined('ADMIN_CLAUDE_MODEL') ? ADMIN_CLAUDE_MODEL : CLAUDE_MODEL;
$current_prompt = defined('ADMIN_CLAUDE_PROMPT') ? ADMIN_CLAUDE_PROMPT : '';

// ── Procesar formulario ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_model  = trim($_POST['model']  ?? '');
    $new_prompt = trim($_POST['prompt'] ?? '');

    if (!array_key_exists($new_model, $available_models)) {
        $error_msg = 'Modelo no válido.';
    } else {
        // Escapar el prompt para PHP string
        $escaped_prompt = addslashes($new_prompt);

        $override_content = <<<PHPFILE
<?php
/**
 * config_override.php
 * Sobreescrituras de configuración editables desde admin.php
 * Última actualización: {$_SESSION['authorized_email']} — {$_SERVER['REQUEST_TIME']}
 */
if (php_sapi_name() !== 'cli' && basename(\$_SERVER['PHP_SELF']) === 'config_override.php') {
    http_response_code(403);
    exit;
}

define('ADMIN_CLAUDE_MODEL', '$new_model');
define('ADMIN_CLAUDE_PROMPT', '$escaped_prompt');
PHPFILE;

        if (file_put_contents(__DIR__ . '/config_override.php', $override_content) !== false) {
            $success_msg    = 'Configuración guardada correctamente.';
            $current_model  = $new_model;
            $current_prompt = $new_prompt;
        } else {
            $error_msg = 'No se pudo escribir config_override.php. Verificá los permisos del archivo.';
        }
    }
}
?><!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Admin — Libellus</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/style.css">
    <link rel="stylesheet" href="assets/libellus-theme.css">
    <link rel="icon" type="image/svg+xml" href="favicon.svg">
    <style>
        /* ── Admin-specific overrides ─────────────────────────────── */
        .admin-header h1::after { display: none !important; }

        .admin-badge {
            display: inline-flex;
            align-items: center;
            gap: .35rem;
            background: #fff8e6;
            border: 1px solid var(--lib-gold, #c8a84b);
            color: var(--lib-navy, #1a2f52);
            border-radius: 999px;
            padding: .2rem .75rem;
            font-size: .72rem;
            font-weight: 600;
            letter-spacing: .04em;
            text-transform: uppercase;
        }

        .model-card {
            border: 2px solid var(--lib-border, #ddd8cc);
            border-radius: 10px;
            padding: 1rem 1.25rem;
            cursor: pointer;
            transition: border-color .18s, background .18s;
            background: #fff;
        }
        .model-card:hover {
            border-color: var(--lib-gold, #c8a84b);
            background: #faf7f0;
        }
        .model-card.selected {
            border-color: var(--lib-navy, #1a2f52);
            background: #f0f4fa;
        }
        .model-card input[type="radio"] { display: none; }

        .model-name {
            font-weight: 600;
            font-size: .9rem;
            color: var(--lib-navy, #1a2f52);
        }
        .model-id {
            font-size: .75rem;
            color: var(--lib-muted, #6b7280);
            font-family: 'Courier New', monospace;
        }
        .model-check {
            width: 18px;
            height: 18px;
            border-radius: 50%;
            border: 2px solid var(--lib-border, #ddd8cc);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            transition: border-color .18s, background .18s;
        }
        .model-card.selected .model-check {
            border-color: var(--lib-navy, #1a2f52);
            background: var(--lib-navy, #1a2f52);
        }
        .model-card.selected .model-check::after {
            content: '';
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #fff;
        }

        .prompt-textarea {
            font-family: 'Courier New', monospace !important;
            font-size: .82rem !important;
            line-height: 1.6 !important;
            min-height: 280px;
            resize: vertical;
            border-color: var(--lib-border, #ddd8cc) !important;
            border-radius: 8px !important;
        }
        .prompt-textarea:focus {
            border-color: var(--lib-gold, #c8a84b) !important;
            box-shadow: 0 0 0 3px rgba(200, 168, 75, .18) !important;
        }

        .char-counter {
            font-size: .72rem;
            color: var(--lib-muted, #6b7280);
        }

        .alert-success-lib {
            background: #f0f7f0 !important;
            border-color: #4caf50 !important;
            color: #1b5e20 !important;
            border-radius: 8px;
        }
        .alert-danger-lib {
            background: #fff8e6 !important;
            border-color: var(--lib-gold, #c8a84b) !important;
            color: var(--lib-navy, #1a2f52) !important;
            border-radius: 8px;
        }

        .back-link {
            color: var(--lib-muted, #6b7280);
            font-size: .82rem;
            text-decoration: none;
        }
        .back-link:hover { color: var(--lib-navy, #1a2f52); }

        .section-divider {
            border: none;
            border-top: 1px solid var(--lib-border, #ddd8cc);
            margin: 1.75rem 0;
        }

        .save-btn {
            background: var(--lib-navy, #1a2f52) !important;
            border-color: var(--lib-navy, #1a2f52) !important;
            border-radius: 8px !important;
            font-weight: 600 !important;
            padding: .65rem 2rem !important;
        }
        .save-btn:hover {
            background: #14243f !important;
            border-color: #14243f !important;
        }

        /* ── Tabla de historial ───────────────────────────────────── */
        .history-table {
            font-family: 'Inter', sans-serif;
            border-collapse: collapse;
            width: 100%;
        }
        .history-table thead tr {
            background: #f5f2eb;
            border-bottom: 2px solid var(--lib-border, #ddd8cc);
        }
        .history-table thead th {
            color: var(--lib-navy, #1a2f52);
            font-size: .72rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .05em;
            padding: .65rem 1rem;
            white-space: nowrap;
            border: none;
        }
        .history-table tbody tr {
            border-bottom: 1px solid var(--lib-border, #ddd8cc);
            transition: background .12s;
        }
        .history-table tbody tr:last-child { border-bottom: none; }
        .history-table tbody tr:hover { background: #faf7f0; }
        .history-table tbody td {
            padding: .6rem 1rem;
            vertical-align: middle;
            border: none;
            color: var(--lib-text, #1a2f52);
        }
        .history-date { color: var(--lib-muted, #6b7280); }
        .history-filename {
            display: inline-block;
            max-width: 200px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            vertical-align: middle;
        }
        .history-model {
            background: #f0f4fa;
            color: var(--lib-navy, #1a2f52);
            border-radius: 4px;
            padding: .1rem .4rem;
            font-size: .72rem;
        }
    </style>
</head>
<body>

<div class="container py-5" style="max-width:80%">

    <!-- Header -->
    <div class="mb-4 admin-header">
        <div class="d-flex align-items-center justify-content-between mb-2">
            <a href="index.php" class="back-link">
                <i class="bi bi-arrow-left me-1"></i> Volver al generador
            </a>
            <span class="admin-badge">
                <i class="bi bi-shield-lock-fill"></i> Admin
            </span>
        </div>
        <h1 class="fs-4 fw-semibold mb-1">Configuración</h1>
        <p class="text-secondary small mb-0">
            Ajustes globales del generador. Los cambios aplican a todas las sesiones.
        </p>
    </div>

    <!-- Mensajes de estado -->
    <?php if ($success_msg): ?>
    <div class="alert alert-success-lib d-flex align-items-center gap-2 mb-4" role="alert">
        <i class="bi bi-check-circle-fill"></i>
        <?= htmlspecialchars($success_msg) ?>
    </div>
    <?php endif; ?>

    <?php if ($error_msg): ?>
    <div class="alert alert-danger-lib d-flex align-items-center gap-2 mb-4" role="alert">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <?= htmlspecialchars($error_msg) ?>
    </div>
    <?php endif; ?>

    <form method="POST" action="admin.php">

        <!-- ── Sección 1: Modelo ──────────────────────────────────── -->
        <div class="card rounded-3 p-4 mb-3">

            <div class="mb-1">
                <span class="small fw-semibold" style="color:var(--lib-navy,#1a2f52)">
                    <i class="bi bi-cpu me-1"></i> Modelo de IA
                </span>
                <p class="text-secondary small mb-3 mt-1">
                    El modelo seleccionado se usará en todas las generaciones.
                </p>
            </div>

            <div class="d-flex flex-column gap-2" id="model-list">
                <?php foreach ($available_models as $model_id => $model_name): ?>
                <?php $is_selected = ($model_id === $current_model); ?>
                <label class="model-card d-flex align-items-center gap-3 <?= $is_selected ? 'selected' : '' ?>"
                       data-model="<?= htmlspecialchars($model_id) ?>">
                    <input type="radio" name="model" value="<?= htmlspecialchars($model_id) ?>"
                           <?= $is_selected ? 'checked' : '' ?>>
                    <div class="flex-grow-1">
                        <div class="model-name"><?= htmlspecialchars($model_name) ?></div>
                        <div class="model-id"><?= htmlspecialchars($model_id) ?></div>
                    </div>
                    <div class="model-check"></div>
                </label>
                <?php endforeach; ?>
            </div>

        </div>

        <!-- ── Sección 2: Prompt global ──────────────────────────── -->
        <div class="card rounded-3 p-4 mb-3">

            <div class="mb-1">
                <span class="small fw-semibold" style="color:var(--lib-navy,#1a2f52)">
                    <i class="bi bi-chat-text me-1"></i> Prompt global
                </span>
                <p class="text-secondary small mb-3 mt-1">
                    Si se completa, reemplaza el prompt por defecto en todas las generaciones.
                    Dejalo vacío para usar el prompt original del sistema.
                </p>
            </div>

            <textarea
                class="form-control prompt-textarea"
                id="prompt-input"
                name="prompt"
                placeholder="Dejá vacío para usar el prompt por defecto del sistema..."
                spellcheck="false"
            ><?= htmlspecialchars($current_prompt) ?></textarea>

            <div class="d-flex justify-content-between align-items-center mt-2">
                <span class="char-counter">
                    <span id="char-count">0</span> caracteres
                </span>
                <?php if ($current_prompt): ?>
                <button type="button" class="btn btn-sm btn-secondary-action" id="clear-prompt-btn">
                    <i class="bi bi-x me-1"></i> Limpiar prompt
                </button>
                <?php endif; ?>
            </div>

        </div>

        <!-- ── Guardar ────────────────────────────────────────────── -->
        <div class="d-flex justify-content-end">
            <button type="submit" class="btn btn-primary save-btn">
                <i class="bi bi-floppy me-2"></i> Guardar configuración
            </button>
        </div>

    </form>

    <!-- ── Historial de generaciones ─────────────────────────────── -->
    <?php
    $log_entries = read_generation_log();
    ?>
    <hr class="section-divider mt-4">

    <div class="mb-3 d-flex align-items-center justify-content-between">
        <div>
            <span class="small fw-semibold" style="color:var(--lib-navy,#1a2f52)">
                <i class="bi bi-clock-history me-1"></i> Historial de generaciones
            </span>
            <p class="text-secondary small mb-0 mt-1">
                Últimas <?= count($log_entries) ?> generaciones registradas.
            </p>
        </div>
        <?php if (!empty($log_entries)): ?>
        <span class="admin-badge">
            <?= count($log_entries) ?> registros
        </span>
        <?php endif; ?>
    </div>

    <?php if (empty($log_entries)): ?>
    <div class="card rounded-3 p-4 text-center">
        <i class="bi bi-inbox" style="font-size:2rem;color:var(--lib-muted,#6b7280)"></i>
        <p class="text-secondary small mt-2 mb-0">Aún no hay generaciones registradas.</p>
    </div>
    <?php else: ?>
    <div class="card rounded-3 p-0 mb-4" style="overflow:hidden">
        <div style="overflow-x:auto">
            <table class="table table-sm mb-0 history-table">
                <thead>
                    <tr>
                        <th>Fecha y hora</th>
                        <th>Documento</th>
                        <th>Usuario</th>
                        <th class="text-end">Tokens entrada</th>
                        <th class="text-end">Tokens salida</th>
                        <th class="text-end">Total tokens</th>
                        <th class="text-end">Tiempo</th>
                        <th>Modelo</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($log_entries as $entry): ?>
                <?php
                    $total_tokens = ($entry['input_tokens'] ?? 0) + ($entry['output_tokens'] ?? 0);
                    $secs = (float)($entry['elapsed'] ?? 0);
                    if ($secs >= 60) {
                        $m = floor($secs / 60);
                        $s = round($secs % 60);
                        $elapsed_fmt = "{$m}m {$s}s";
                    } else {
                        $elapsed_fmt = round($secs, 1) . 's';
                    }
                    $date_fmt = '';
                    if (!empty($entry['generated_at'])) {
                        $dt = new DateTime($entry['generated_at']);
                        $date_fmt = $dt->format('d/m/Y H:i:s');
                    }
                ?>
                <tr>
                    <td class="text-nowrap small">
                        <span class="history-date"><?= htmlspecialchars($date_fmt) ?></span>
                    </td>
                    <td class="small">
                        <span class="history-filename" title="<?= htmlspecialchars($entry['filename'] ?? '') ?>">
                            <i class="bi bi-file-earmark-word me-1" style="color:#2b579a"></i>
                            <?= htmlspecialchars($entry['filename'] ?? '—') ?>
                        </span>
                    </td>
                    <td class="small text-nowrap">
                        <?= htmlspecialchars($entry['email'] ?? '—') ?>
                    </td>
                    <td class="text-end small text-nowrap">
                        <?= number_format($entry['input_tokens'] ?? 0, 0, ',', '.') ?>
                    </td>
                    <td class="text-end small text-nowrap">
                        <?= number_format($entry['output_tokens'] ?? 0, 0, ',', '.') ?>
                    </td>
                    <td class="text-end small text-nowrap fw-semibold">
                        <?= number_format($total_tokens, 0, ',', '.') ?>
                    </td>
                    <td class="text-end small text-nowrap">
                        <?= htmlspecialchars($elapsed_fmt) ?>
                    </td>
                    <td class="small">
                        <code class="history-model"><?= htmlspecialchars($entry['model'] ?? '—') ?></code>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ── Selección visual de modelo ────────────────────────────────────────────────
document.querySelectorAll('.model-card').forEach(card => {
    card.addEventListener('click', () => {
        document.querySelectorAll('.model-card').forEach(c => c.classList.remove('selected'));
        card.classList.add('selected');
        card.querySelector('input[type="radio"]').checked = true;
    });
});

// ── Contador de caracteres del prompt ─────────────────────────────────────────
const promptInput = document.getElementById('prompt-input');
const charCount   = document.getElementById('char-count');

function updateCount() {
    charCount.textContent = promptInput.value.length.toLocaleString('es');
}
promptInput.addEventListener('input', updateCount);
updateCount();

// ── Limpiar prompt ────────────────────────────────────────────────────────────
const clearBtn = document.getElementById('clear-prompt-btn');
if (clearBtn) {
    clearBtn.addEventListener('click', () => {
        promptInput.value = '';
        updateCount();
        clearBtn.style.display = 'none';
    });
}
</script>
</body>
</html>

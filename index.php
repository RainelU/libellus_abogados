<?php
session_start();
if (!isset($_SESSION['authorized_email'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/config.php';

require_once __DIR__ . '/skills.php';

require_once __DIR__ . '/claude.php';



if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    header('Content-Type: application/json');

    $action = $_POST['action'] ?? '';



    if ($action === 'get_skills') {

        echo json_encode(['skills' => get_available_skills()]);

        exit;

    }



    if ($action === 'generate') {

        // Configurar para operaciones largas

        @ini_set('max_execution_time', '0'); // Sin límite

        @ini_set('memory_limit', '2G');

        @set_time_limit(0); // Sin límite

        

        // Evitar que Apache corte la conexión

        @apache_setenv('no-gzip', '1');

        @ini_set('zlib.output_compression', '0');

        @ini_set('implicit_flush', '1');

        ob_implicit_flush(true);

        

        // Enviar headers para mantener conexión viva

        header('Connection: keep-alive');

        header('X-Accel-Buffering: no'); // Para nginx

        

        $skill_name  = trim($_POST['skill']    ?? '');

        $user_input   = trim($_POST['input']    ?? '');

        $pdf_data_raw = trim($_POST['pdf_data'] ?? '');



        if (!$skill_name) {

            echo json_encode(['success' => false, 'error' => 'Seleccioná un skill.']);

            exit;

        }



        $pdf_attachments = [];

        if ($pdf_data_raw) {

            $decoded = json_decode($pdf_data_raw, true);

            if (is_array($decoded)) {

                foreach ($decoded as $b64) {

                    $pdf_attachments[] = ['data' => $b64];

                }

            }

        }



        if (!$user_input && empty($pdf_attachments)) {

            echo json_encode(['success' => false, 'error' => 'Adjuntá un archivo antes de generar.']);

            exit;

        }



        try {

            $result = call_claude($skill_name, $user_input, $pdf_attachments);

            

            // Verificar si es un archivo o texto

            if ($result['type'] === 'files') {

                echo json_encode([

                    'success' => true,

                    'type' => 'files',

                    'files' => $result['files'],

                    'message' => $result['message']

                ]);

            } else {

                echo json_encode([

                    'success' => true,

                    'type' => 'text',

                    'content' => $result['content']

                ]);

            }

        } catch (RuntimeException $e) {

            echo json_encode(['success' => false, 'error' => $e->getMessage()]);

        }

        exit;

    }



    echo json_encode(['success' => false, 'error' => 'Acción desconocida.']);

    exit;

}

?><!DOCTYPE html>

<html lang="es">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">

    <title>Generador de Demandas</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">

    <link rel="stylesheet" href="assets/style.css">

    <link rel="stylesheet" href="assets/libellus-theme.css">

    <link rel="icon" type="image/svg+xml" href="favicon.svg">

</head>

<body>



<div class="container py-5" style="max-width:780px">



    <div class="mb-4">

        <h1 class="fs-4 fw-semibold mb-1">Generador de Demandas</h1>

        <p class="text-secondary small mb-0">Seleccioná un skill, adjuntá un archivo y generá contenido legal profesional.</p>

    </div>



    <div class="card rounded-3 p-4 mb-3">



        <div class="mb-3">

            <label for="skill-select" class="form-label">Skill</label>

            <select class="form-select" id="skill-select" disabled>

                <option value="">Cargando skills...</option>

            </select>

        </div>



        <div class="mb-3">

            <label class="form-label">Archivos de entrada</label>

            <div class="d-flex align-items-center gap-2 flex-wrap">

                <input type="file" id="file-input" class="d-none" multiple

                       accept=".pdf,.txt,.docx,.md,.csv,.html,.htm,.xml,.json,.py,.log">

                <button type="button" class="btn btn-sm btn-secondary-action" id="attach-btn" title="Adjuntar archivos" aria-label="Adjuntar archivos">

                    <i class="bi bi-paperclip me-1"></i> Adjuntar archivos

                </button>

                <div id="attached-files" class="d-flex gap-2 flex-wrap"></div>

            </div>

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



</div>



<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script src="assets/app.js"></script>

</body>

</html>


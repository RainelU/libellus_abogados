<?php
// Servir archivos descargados de Claude
if (!isset($_GET['file'])) {
    http_response_code(400);
    echo 'Parámetro file requerido';
    exit;
}

$filename = basename($_GET['file']);
$filepath = __DIR__ . '/downloads/' . $filename;

if (!file_exists($filepath)) {
    http_response_code(404);
    echo 'Archivo no encontrado';
    exit;
}

// Validar que sea un archivo .docx
if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'docx') {
    http_response_code(403);
    echo 'Tipo de archivo no permitido';
    exit;
}

header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($filepath));
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: no-cache');

readfile($filepath);
exit;

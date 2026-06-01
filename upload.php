<?php
// Evitar output no deseado
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Verificar método
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Content-Type: application/json');
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método no permitido']);
    exit;
}

header('Content-Type: application/json');

// Verificar archivo
if (empty($_FILES['file'])) {
    echo json_encode(['success' => false, 'error' => 'No se recibió ningún archivo.']);
    exit;
}

$file = $_FILES['file'];

// Verificar errores de upload
if ($file['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'error' => 'Error al subir el archivo (código ' . $file['error'] . ').']);
    exit;
}

// Verificar tamaño
$max_size = 20 * 1024 * 1024; // 20 MB
if ($file['size'] > $max_size) {
    echo json_encode(['success' => false, 'error' => 'El archivo supera el límite de 20 MB.']);
    exit;
}

$tmp  = $file['tmp_name'];
$name = $file['name'];
$ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));

try {
    if ($ext === 'pdf') {
        // Procesar PDF
        $data = base64_encode(file_get_contents($tmp));
        echo json_encode([
            'success' => true,
            'type' => 'pdf',
            'data' => $data,
            'name' => $name
        ]);
    } elseif ($ext === 'docx') {
        // Extraer texto de DOCX
        $text = extract_docx($tmp);
        echo json_encode([
            'success' => true,
            'type' => 'text',
            'text' => $text,
            'name' => $name
        ]);
    } else {
        // Intentar como texto plano
        $text = file_get_contents($tmp);
        if ($text === false) {
            throw new RuntimeException('No se pudo leer el archivo.');
        }
        echo json_encode([
            'success' => true,
            'type' => 'text',
            'text' => $text,
            'name' => $name
        ]);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

function extract_docx(string $filepath): string {
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('ZipArchive no disponible.');
    }
    
    $zip = new ZipArchive();
    if ($zip->open($filepath) !== true) {
        throw new RuntimeException('No se pudo abrir el archivo DOCX.');
    }
    
    $xml = $zip->getFromName('word/document.xml');
    $zip->close();
    
    if ($xml === false) {
        throw new RuntimeException('Formato DOCX inválido.');
    }
    
    $xml = preg_replace('/<w:p[ >\/]/', "\n\n", $xml);
    $xml = preg_replace('/<w:br[^>]*\/>/', "\n", $xml);
    $text = strip_tags($xml);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
    
    return trim(preg_replace('/\n{3,}/', "\n\n", $text));
}

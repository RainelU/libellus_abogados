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

require_once __DIR__ . '/config.php';

try {
    if ($ext === 'pdf') {
        // Subir PDF directamente a la Files API de Anthropic y devolver file_id
        $file_id = upload_pdf_to_files_api($tmp, $name);
        if (!$file_id) {
            throw new RuntimeException('No se pudo subir el PDF a la Files API de Anthropic.');
        }
        echo json_encode([
            'success'  => true,
            'type'     => 'pdf',
            'file_id'  => $file_id,
            'name'     => $name,
        ]);
    } elseif ($ext === 'docx') {
        // Extraer texto de DOCX
        $text = extract_docx($tmp);
        echo json_encode([
            'success' => true,
            'type'    => 'text',
            'text'    => $text,
            'name'    => $name,
        ]);
    } else {
        // Intentar como texto plano
        $text = file_get_contents($tmp);
        if ($text === false) {
            throw new RuntimeException('No se pudo leer el archivo.');
        }
        echo json_encode([
            'success' => true,
            'type'    => 'text',
            'text'    => $text,
            'name'    => $name,
        ]);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

/**
 * Sube un PDF a la Files API de Anthropic y devuelve el file_id.
 */
function upload_pdf_to_files_api(string $tmp_path, string $filename): ?string {
    $binary = file_get_contents($tmp_path);
    if (!$binary) {
        error_log("Failed to read PDF file: $tmp_path");
        return null;
    }

    $boundary = '----FormBoundary' . bin2hex(random_bytes(8));

    $body  = "--$boundary\r\n";
    $body .= "Content-Disposition: form-data; name=\"file\"; filename=\"$filename\"\r\n";
    $body .= "Content-Type: application/pdf\r\n\r\n";
    $body .= $binary . "\r\n";
    $body .= "--$boundary--\r\n";

    $ch = curl_init('https://api.anthropic.com/v1/files');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_HTTPHEADER     => [
            'x-api-key: '        . ANTHROPIC_API_KEY,
            'anthropic-version: 2023-06-01',
            'anthropic-beta: files-api-2025-04-14',
            "Content-Type: multipart/form-data; boundary=$boundary",
        ],
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err  = curl_error($ch);
    curl_close($ch);

    if ($curl_err) {
        error_log("Files API upload error: $curl_err");
        return null;
    }

    $result = json_decode($response, true);
    if ($http_code !== 200 || empty($result['id'])) {
        error_log("Files API upload failed: HTTP $http_code — " . substr($response, 0, 300));
        return null;
    }

    error_log("PDF uploaded to Files API: {$result['id']} ($filename)");
    return $result['id'];
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

    $xml  = preg_replace('/<w:p[ >\/]/', "\n\n", $xml);
    $xml  = preg_replace('/<w:br[^>]*\/>/', "\n", $xml);
    $text = strip_tags($xml);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');

    return trim(preg_replace('/\n{3,}/', "\n\n", $text));
}

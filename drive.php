<?php
require_once __DIR__ . '/config.php';
session_start();

define('GOOGLE_AUTH_URL',    'https://accounts.google.com/o/oauth2/v2/auth');
define('GOOGLE_TOKEN_URL',   'https://oauth2.googleapis.com/token');
define('GOOGLE_DRIVE_UPLOAD','https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart');

function redirect_uri(): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'];
    $dir    = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
    return $scheme . '://' . $host . $dir . '/drive.php';
}

// ── OAuth callback (GET ?code=...) ──────────────────────────────────────────
if (isset($_GET['code'])) {
    $token = exchange_code($_GET['code']);
    if ($token) {
        $_SESSION['drive_token']         = $token;
        $_SESSION['drive_token_expires'] = time() + ($token['expires_in'] ?? 3600);
        echo "<script>window.opener && window.opener.postMessage({type:'drive_auth_ok'},'*'); window.close();</script>";
    } else {
        echo "<script>window.opener && window.opener.postMessage({type:'drive_auth_fail'},'*'); window.close();</script>";
    }
    exit;
}

if (isset($_GET['error'])) {
    echo "<script>window.opener && window.opener.postMessage({type:'drive_auth_fail',error:" . json_encode($_GET['error']) . "},'*'); window.close();</script>";
    exit;
}

// ── JSON API (POST) ──────────────────────────────────────────────────────────
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$action = $_POST['action'] ?? '';

if ($action === 'get_auth_url') {
    if (!GOOGLE_CLIENT_ID) {
        echo json_encode(['success' => false, 'error' => 'Google Client ID no configurado.']);
        exit;
    }
    $url = GOOGLE_AUTH_URL . '?' . http_build_query([
        'client_id'     => GOOGLE_CLIENT_ID,
        'redirect_uri'  => redirect_uri(),
        'response_type' => 'code',
        'scope'         => 'https://www.googleapis.com/auth/drive.file https://www.googleapis.com/auth/drive.metadata.readonly',
        'access_type'   => 'offline',
        'prompt'        => 'select_account consent',
    ]);
    echo json_encode(['success' => true, 'url' => $url]);
    exit;
}

if ($action === 'check_auth') {
    $ok = !empty($_SESSION['drive_token']) && ($_SESSION['drive_token_expires'] ?? 0) > time();
    echo json_encode(['success' => true, 'authenticated' => $ok]);
    exit;
}

if ($action === 'get_token') {
    if (empty($_SESSION['drive_token'])) {
        echo json_encode(['success' => false, 'error' => 'No autenticado.']);
        exit;
    }
    if (($_SESSION['drive_token_expires'] ?? 0) <= time()) {
        $refresh = $_SESSION['drive_token']['refresh_token'] ?? '';
        if ($refresh) {
            $new = refresh_access_token($refresh);
            if ($new) {
                $_SESSION['drive_token']['access_token'] = $new['access_token'];
                $_SESSION['drive_token_expires']         = time() + ($new['expires_in'] ?? 3600);
            } else {
                unset($_SESSION['drive_token'], $_SESSION['drive_token_expires']);
                echo json_encode(['success' => false, 'error' => 'Sesión expirada. Volvé a conectar Drive.']);
                exit;
            }
        }
    }
    echo json_encode([
        'success' => true,
        'token'   => $_SESSION['drive_token']['access_token'],
        'api_key' => defined('GOOGLE_PICKER_API_KEY') ? GOOGLE_PICKER_API_KEY : '',
    ]);
    exit;
}

if ($action === 'save') {
    if (empty($_SESSION['drive_token'])) {
        echo json_encode(['success' => false, 'error' => 'No autenticado con Google Drive.']);
        exit;
    }

    // Refresh si el token expiró
    if (($_SESSION['drive_token_expires'] ?? 0) <= time()) {
        $refresh = $_SESSION['drive_token']['refresh_token'] ?? '';
        if ($refresh) {
            $new = refresh_access_token($refresh);
            if ($new) {
                $_SESSION['drive_token']['access_token'] = $new['access_token'];
                $_SESSION['drive_token_expires']         = time() + ($new['expires_in'] ?? 3600);
            } else {
                unset($_SESSION['drive_token'], $_SESSION['drive_token_expires']);
                echo json_encode(['success' => false, 'error' => 'Sesión expirada. Volvé a conectar Drive.']);
                exit;
            }
        }
    }

    $content   = $_POST['content']   ?? '';
    $filename  = $_POST['filename']  ?? ('content-' . date('Y-m-d-His'));
    $folder_id = $_POST['folder_id'] ?? '';
    $format    = $_POST['format']    ?? 'txt';
    $token     = $_SESSION['drive_token']['access_token'];

    if ($format === 'docx') {
        $binary = generate_docx($content);
        $mime   = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
        echo json_encode(upload_binary_to_drive($token, $filename . '.docx', $binary, $mime, $folder_id));
    } elseif ($format === 'pdf') {
        $binary = base64_decode($content);
        echo json_encode(upload_binary_to_drive($token, $filename . '.pdf', $binary, 'application/pdf', $folder_id));
    } else {
        echo json_encode(upload_to_drive($token, $filename . '.txt', $content, $folder_id));
    }
    exit;
}

if ($action === 'list_folders') {
    if (empty($_SESSION['drive_token'])) {
        echo json_encode(['success' => false, 'error' => 'No autenticado.']);
        exit;
    }
    if (($_SESSION['drive_token_expires'] ?? 0) <= time()) {
        $refresh = $_SESSION['drive_token']['refresh_token'] ?? '';
        if ($refresh) {
            $new = refresh_access_token($refresh);
            if ($new) {
                $_SESSION['drive_token']['access_token'] = $new['access_token'];
                $_SESSION['drive_token_expires']         = time() + ($new['expires_in'] ?? 3600);
            } else {
                unset($_SESSION['drive_token'], $_SESSION['drive_token_expires']);
                echo json_encode(['success' => false, 'error' => 'Sesión expirada. Volvé a conectar Drive.']);
                exit;
            }
        }
    }
    $token   = $_SESSION['drive_token']['access_token'];
    $folders = list_drive_folders($token);
    echo json_encode(['success' => true, 'folders' => $folders]);
    exit;
}

if ($action === 'logout') {
    unset($_SESSION['drive_token'], $_SESSION['drive_token_expires']);
    session_destroy();
    echo json_encode(['success' => true]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'Acción desconocida.']);

// ── Funciones ────────────────────────────────────────────────────────────────

function exchange_code(string $code): array|false {
    return post_form(GOOGLE_TOKEN_URL, [
        'code'          => $code,
        'client_id'     => GOOGLE_CLIENT_ID,
        'client_secret' => GOOGLE_CLIENT_SECRET,
        'redirect_uri'  => redirect_uri(),
        'grant_type'    => 'authorization_code',
    ]);
}

function refresh_access_token(string $refresh_token): array|false {
    return post_form(GOOGLE_TOKEN_URL, [
        'refresh_token' => $refresh_token,
        'client_id'     => GOOGLE_CLIENT_ID,
        'client_secret' => GOOGLE_CLIENT_SECRET,
        'grant_type'    => 'refresh_token',
    ]);
}

function post_form(string $url, array $fields): array|false {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($fields),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT        => 15,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($response, true);
    return (!empty($data['access_token'])) ? $data : false;
}

function list_drive_folders(string $token): array {
    $url = 'https://www.googleapis.com/drive/v3/files?' . http_build_query([
        'q'       => "mimeType='application/vnd.google-apps.folder' and trashed=false",
        'fields'  => 'files(id,name)',
        'orderBy' => 'name',
        'pageSize'=> 100,
    ]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token],
        CURLOPT_TIMEOUT        => 15,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($response, true);
    return $data['files'] ?? [];
}

function upload_to_drive(string $token, string $filename, string $content, string $folder_id = ''): array {
    $meta = ['name' => $filename, 'mimeType' => 'text/plain'];
    if ($folder_id) $meta['parents'] = [$folder_id];
    $metadata = json_encode($meta);
    $boundary = 'boundary_' . bin2hex(random_bytes(8));

    $body = "--{$boundary}\r\n"
          . "Content-Type: application/json; charset=UTF-8\r\n\r\n"
          . $metadata . "\r\n"
          . "--{$boundary}\r\n"
          . "Content-Type: text/plain; charset=UTF-8\r\n\r\n"
          . $content . "\r\n"
          . "--{$boundary}--";

    $ch = curl_init(GOOGLE_DRIVE_UPLOAD);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
            'Content-Type: multipart/related; boundary=' . $boundary,
        ],
    ]);
    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($response, true);
    if ($http_code === 200) {
        return ['success' => true, 'id' => $data['id'] ?? '', 'name' => $data['name'] ?? $filename];
    }
    return ['success' => false, 'error' => $data['error']['message'] ?? 'Error al guardar en Drive (HTTP ' . $http_code . ').'];
}

function upload_binary_to_drive(string $token, string $filename, string $binary, string $mime, string $folder_id = ''): array {
    $meta = ['name' => $filename, 'mimeType' => $mime];
    if ($folder_id) $meta['parents'] = [$folder_id];
    $metadata = json_encode($meta);
    $boundary = 'boundary_' . bin2hex(random_bytes(8));

    $body = "--{$boundary}\r\n"
          . "Content-Type: application/json; charset=UTF-8\r\n\r\n"
          . $metadata . "\r\n"
          . "--{$boundary}\r\n"
          . "Content-Type: {$mime}\r\n"
          . "Content-Transfer-Encoding: binary\r\n\r\n"
          . $binary . "\r\n"
          . "--{$boundary}--";

    $ch = curl_init(GOOGLE_DRIVE_UPLOAD);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
            'Content-Type: multipart/related; boundary=' . $boundary,
        ],
    ]);
    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($response, true);
    if ($http_code === 200) {
        return ['success' => true, 'id' => $data['id'] ?? '', 'name' => $data['name'] ?? $filename];
    }
    return ['success' => false, 'error' => $data['error']['message'] ?? 'Error al guardar en Drive (HTTP ' . $http_code . ').'];
}

function generate_docx(string $text): string {
    $tmp = tempnam(sys_get_temp_dir(), 'docx_');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);

    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml"  ContentType="application/xml"/>
  <Override PartName="/word/document.xml"
    ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>
</Types>');

    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument"
    Target="word/document.xml"/>
</Relationships>');

    $zip->addFromString('word/_rels/document.xml.rels', '<?xml version="1.0" encoding="UTF-8"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
</Relationships>');

    $paragraphs = preg_split('/\r\n|\r|\n/', $text);
    $body = '';
    foreach ($paragraphs as $line) {
        $escaped = htmlspecialchars($line, ENT_XML1, 'UTF-8');
        $body .= '<w:p><w:r><w:t xml:space="preserve">' . $escaped . '</w:t></w:r></w:p>' . "\n";
    }
    $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"
  xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <w:body>
' . $body . '
    <w:sectPr>
      <w:pgSz w:w="12240" w:h="15840"/>
      <w:pgMar w:top="1440" w:right="1440" w:bottom="1440" w:left="1440"/>
    </w:sectPr>
  </w:body>
</w:document>');

    $zip->close();
    $content = file_get_contents($tmp);
    unlink($tmp);
    return $content;
}

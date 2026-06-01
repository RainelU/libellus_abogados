<?php
require_once __DIR__ . '/config.php';

function call_claude(string $skill_id, string $user_input, array $pdf_attachments = []): array {
    // Configuración de límites
    ini_set('memory_limit', '2G');
    set_time_limit(0);
    ini_set('max_execution_time', 0);
    ini_set('default_socket_timeout', 0);
    
    error_log("=== Claude API Call Started ===");
    error_log("Timestamp: " . date('Y-m-d H:i:s'));
    error_log("Skill ID: $skill_id");
    error_log("PDF attachments: " . count($pdf_attachments));
    
    // Construir contenido con PDFs
    $content = [];
    foreach ($pdf_attachments as $idx => $pdf) {
        $content[] = [
            'type'   => 'document',
            'source' => [
                'type'       => 'base64',
                'media_type' => 'application/pdf',
                'data'       => $pdf['data'],
            ],
        ];
    }
    
    // Headers de la API
    $beta = ['skills-2025-10-02', 'code-execution-2025-08-25', 'files-api-2025-04-14'];
    if (!empty($pdf_attachments)) {
        $beta[] = 'pdfs-2024-09-25';
    }
    
    $headers = [
        'x-api-key: ' . ANTHROPIC_API_KEY,
        'anthropic-version: 2023-06-01',
        'content-type: application/json',
        'anthropic-beta: ' . implode(',', $beta),
    ];
    
    // Configuración del skill y herramientas
    $container = [
        'skills' => [
            ['type' => 'custom', 'skill_id' => $skill_id, 'version' => 'latest'],
        ],
    ];
    
    $tools = [
        ['type' => 'code_execution_20260120', 'name' => 'code_execution'],
    ];
    
    // Construir payload
    $payload = json_encode([
        'model'      => CLAUDE_MODEL,
        'max_tokens' => 100000,
        'container'  => $container,
        'tools'      => $tools,
        'messages'   => [['role' => 'user', 'content' => $content]],
    ]);
    
    $payload_size = strlen($payload);
    error_log("Payload size: " . number_format($payload_size) . " bytes (" . round($payload_size/1024/1024, 2) . " MB)");
    error_log("Sending request to Claude API...");
    
    $start_time = microtime(true);
    
    // Configurar CURL
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_TIMEOUT        => 0,
        CURLOPT_TIMEOUT_MS     => 0,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_ENCODING       => '',
        CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
        CURLOPT_TCP_KEEPALIVE  => 1,
        CURLOPT_TCP_KEEPIDLE   => 60,
        CURLOPT_TCP_KEEPINTVL  => 30,
        CURLOPT_NOSIGNAL       => 1,
        CURLOPT_BUFFERSIZE     => 16384,
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    $curl_errno = curl_errno($ch);
    $curl_info = curl_getinfo($ch);
    curl_close($ch);
    
    $elapsed_time = round(microtime(true) - $start_time, 2);
    $response_size = strlen($response);
    
    error_log("Response received in {$elapsed_time}s");
    error_log("HTTP Code: $http_code");
    error_log("Response size: " . number_format($response_size) . " bytes (" . round($response_size/1024, 2) . " KB)");
    
    // Manejar errores de CURL
    if ($curl_error) {
        error_log("CURL Error ($curl_errno): $curl_error");
        throw new RuntimeException('Connection error: ' . $curl_error);
    }
    
    // Decodificar respuesta
    $data = json_decode($response, true);
    if ($data === null) {
        error_log("JSON decode error: " . json_last_error_msg());
        error_log("Response preview: " . substr($response, 0, 500));
        throw new RuntimeException('Invalid JSON response from API');
    }
    
    // Manejar errores HTTP
    if ($http_code !== 200) {
        $error_msg = $data['error']['message'] ?? 'API error';
        error_log("API Error: $error_msg");
        throw new RuntimeException($error_msg);
    }
    
    $stop_reason = $data['stop_reason'] ?? '';
    error_log("Stop reason: $stop_reason");
    
    // Buscar archivos generados
    $files = extract_generated_files($data['content'] ?? []);
    
    if (!empty($files)) {
        error_log("Files detected: " . count($files));
        
        // Descargar archivos .docx
        $downloaded = [];
        foreach ($files as $file) {
            $ext = strtolower(pathinfo($file['filename'], PATHINFO_EXTENSION));
            if ($ext === 'docx') {
                error_log("Downloading: {$file['filename']}");
                $local_path = download_file_from_claude($file['file_id'], $file['filename']);
                if ($local_path) {
                    $downloaded[] = [
                        'filename' => $file['filename'],
                        'path' => $local_path,
                        'url' => 'download.php?file=' . urlencode(basename($local_path))
                    ];
                    error_log("Downloaded successfully: {$file['filename']}");
                } else {
                    error_log("Download failed: {$file['filename']}");
                }
            } else {
                error_log("Skipping non-DOCX file: {$file['filename']}");
            }
        }
        
        if (!empty($downloaded)) {
            error_log("=== SUCCESS: All files downloaded in {$elapsed_time}s ===");
            return [
                'success' => true,
                'type' => 'files',
                'files' => $downloaded,
                'message' => 'Documento generado exitosamente'
            ];
        } else {
            error_log("WARNING: Files detected but none downloaded");
        }
    } else {
        error_log("No files detected in response");
    }
    
    // Si no hay archivos, extraer texto
    $text = '';
    foreach ($data['content'] as $block) {
        if (($block['type'] ?? '') === 'text') {
            $text .= $block['text'];
        }
    }
    
    if ($text) {
        error_log("Text response received (no files generated)");
        error_log("=== COMPLETED in {$elapsed_time}s ===");
        return [
            'success' => true,
            'type' => 'text',
            'content' => $text
        ];
    }
    
    // Si no hay archivos ni texto
    error_log("=== FAILURE: No files or text in response ===");
    throw new RuntimeException('Claude no generó ningún documento ni texto en la respuesta.');
}

function extract_generated_files(array $content): array {
    $files = [];
    
    foreach ($content as $block) {
        $block_type = $block['type'] ?? '';
        
        // Buscar bash_code_execution_tool_result (respuesta de ejecución de código)
        if ($block_type === 'bash_code_execution_tool_result') {
            // La estructura es: block -> content -> content (array)
            if (isset($block['content']['content']) && is_array($block['content']['content'])) {
                foreach ($block['content']['content'] as $item) {
                    if (($item['type'] ?? '') === 'bash_code_execution_output' && !empty($item['file_id'])) {
                        $file_id = $item['file_id'];
                        
                        // Intentar extraer nombre de archivo del stdout
                        $stdout = $block['content']['stdout'] ?? '';
                        $filename = extract_filename_from_stdout($stdout, $file_id);
                        
                        $files[] = [
                            'file_id' => $file_id,
                            'filename' => $filename
                        ];
                        
                        error_log("Found file: $filename (ID: $file_id)");
                    }
                }
            }
        }
    }
    
    error_log("Total files found: " . count($files));
    return $files;
}

function extract_filename_from_stdout(string $stdout, string $file_id): string {
    // Buscar archivos .docx en el stdout
    if (preg_match('/([^\s\/]+\.docx)/i', $stdout, $matches)) {
        return $matches[1];
    }
    
    // Nombre por defecto
    return 'documento_' . substr($file_id, -8) . '.docx';
}

function download_file_from_claude(string $file_id, string $filename): ?string {
    error_log("Downloading file: $file_id");
    
    $headers = [
        'x-api-key: ' . ANTHROPIC_API_KEY,
        'anthropic-version: 2023-06-01',
        'anthropic-beta: files-api-2025-04-14'
    ];
    
    // Descargar contenido
    $ch = curl_init('https://api.anthropic.com/v1/files/' . $file_id . '/content');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    
    $file_content = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);
    
    if ($curl_error) {
        error_log("Download error: $curl_error");
        return null;
    }
    
    if ($http_code !== 200 || !$file_content) {
        error_log("Download failed: HTTP $http_code");
        return null;
    }
    
    // Guardar archivo
    $upload_dir = __DIR__ . '/downloads';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    
    $safe_filename = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $filename);
    $local_path = $upload_dir . '/' . time() . '_' . $safe_filename;
    
    if (file_put_contents($local_path, $file_content) === false) {
        error_log("Failed to save file");
        return null;
    }
    
    error_log("File saved: $local_path (" . strlen($file_content) . " bytes)");
    return $local_path;
}

<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/config_override.php';
require_once __DIR__ . '/generation_log.php';

/**
 * Wrapper para job queue — formato simplificado
 * 
 * @param array $params ['skill_id' => string, 'pdf_file_ids' => array]
 * @return array ['success', 'filename', 'usage', 'model', 'error']
 */
function claude_generate_demand(array $params): array {
    try {
        $result = call_claude(
            $params['skill_id'] ?? '',
            $params['pdf_file_ids'] ?? [],
            ''
        );
        
        return [
            'success'  => true,
            'filename' => $result['files'][0]['filename'] ?? '',
            'usage'    => $result['usage'] ?? [],
            'model'    => $result['model_used'] ?? CLAUDE_MODEL,
        ];
    } catch (Throwable $e) {
        return [
            'success' => false,
            'error'   => $e->getMessage(),
        ];
    }
}

/**
 * Llama a Claude con los file_ids de los PDFs ya subidos a la Files API.
 * Claude devuelve SOLO JSON (sin markdown, sin explicaciones).
 * El .docx se genera localmente ejecutando generar_demanda.js con Node.js.
 *
 * @param  string $skill_id      ID del skill seleccionado en la UI
 * @param  array  $pdf_file_ids  IDs de la Files API de Anthropic
 * @param  string $user_email    Email del usuario que genera (para el log)
 * @return array  ['success', 'type', 'files', 'json_saved', 'message', 'usage', 'elapsed', 'model_used']
 */
function call_claude(string $skill_id, array $pdf_file_ids, string $user_email = ''): array {
    ini_set('memory_limit', '2G');
    set_time_limit(0);
    ini_set('max_execution_time', 0);
    ini_set('default_socket_timeout', 0);

    error_log("=== Claude API Call Started ===");
    error_log("Timestamp: " . date('Y-m-d H:i:s'));
    error_log("Skill ID: $skill_id");
    error_log("PDF file_ids recibidos: " . count($pdf_file_ids));

    if (empty($pdf_file_ids)) {
        throw new RuntimeException('Se requiere al menos un PDF para generar la demanda.');
    }

    // ── Construir content ────────────────────────────────────────────────────
    $content = [];

    // 1. PDFs del caso (ya subidos por upload.php)
    foreach ($pdf_file_ids as $fid) {
        $content[] = [
            'type'   => 'document',
            'source' => ['type' => 'file', 'file_id' => $fid],
        ];
    }

    // 2. JSON de referencia canónico (schema fijo en la Files API)
    $content[] = [
        'type'   => 'document',
        'source' => ['type' => 'file', 'file_id' => JSON_FILE_REFERENCE],
    ];

    // 3. Prompt — usa el global de admin si está definido, si no el por defecto
    $admin_prompt = defined('ADMIN_CLAUDE_PROMPT') ? trim(ADMIN_CLAUDE_PROMPT) : '';

    $default_prompt = <<<'PROMPT'
You must output ONLY valid JSON. No markdown. No explanations. No reasoning. No comments. No text before or after the JSON.

Use the last document (the JSON reference) as the canonical schema. Build a new JSON for the case described in the PDF documents. Follow these STRICT REQUIREMENTS:

1. Preserve every key, array, nesting level and field name exactly as in the reference.
2. Never remove fields. Never invent new top-level fields.
3. Replace only the case-specific values using the data from the PDF documents.
4. Maintain full legal consistency across all sections.
5. Set _meta.output_filename to a safe filename like "demanda_<apellido_demandante>_vs_<apellido_demandado>.docx".

Especially expand with dense legal argumentation (target ~8 printed pages):
- Deficiencias de la comunicación del despido (all 5 sub-requirements: completa, precisa, específica, clara, circunstanciada)
- Motivo causal económico, técnico, organizativo o productivo
- Necesidad empresarial objetiva
- Externalidad
- Gravedad
- Permanencia
- Relación de causalidad
- Ultima ratio
- Conclusión. Output ONLY the raw JSON object. Start your response with { and end with }.
PROMPT;

    $active_prompt = $admin_prompt ?: $default_prompt;
    error_log("Using prompt source: " . ($admin_prompt ? 'admin override' : 'default'));

    $content[] = [
        'type' => 'text',
        'text' => $active_prompt,
    ];

    // ── Headers ──────────────────────────────────────────────────────────────
    $headers = [
        'x-api-key: '        . ANTHROPIC_API_KEY,
        'anthropic-version: 2023-06-01',
        'content-type: application/json',
        'anthropic-beta: skills-2025-10-02,code-execution-2025-08-25,files-api-2025-04-14',
    ];

    // ── Payload ──────────────────────────────────────────────────────────────
    $payload_data = [
        'model'      => defined('ADMIN_CLAUDE_MODEL') && ADMIN_CLAUDE_MODEL ? ADMIN_CLAUDE_MODEL : CLAUDE_MODEL,
        'max_tokens' => 20000,
        'messages'   => [['role' => 'user', 'content' => $content]],
    ];

    // Incluir el skill si se proporcionó uno
    if ($skill_id) {
        $payload_data['container'] = [
            'skills' => [
                ['type' => 'custom', 'skill_id' => $skill_id, 'version' => 'latest'],
            ],
        ];

        $payload_data['tools'] = [
            ['type' => 'code_execution_20250825', "name" => "code_execution"],
        ];
    }

    $payload = json_encode($payload_data);

    error_log("Payload size: " . number_format(strlen($payload)) . " bytes");
    error_log("Sending request to Claude API...");

    $start_time = microtime(true);

    // ── cURL ─────────────────────────────────────────────────────────────────
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

    $response   = curl_exec($ch);
    $http_code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    $curl_errno = curl_errno($ch);
    curl_close($ch);

    $elapsed = round(microtime(true) - $start_time, 2);
    error_log("Response in {$elapsed}s — HTTP $http_code — " . number_format(strlen($response ?? '')) . " bytes");

    if ($curl_error) {
        error_log("CURL Error ($curl_errno): $curl_error");
        throw new RuntimeException('Error de conexión: ' . $curl_error);
    }

    $data = json_decode($response, true);
    if ($data === null) {
        error_log("JSON decode error: " . json_last_error_msg());
        error_log("Response preview: " . substr($response, 0, 500));
        throw new RuntimeException('Respuesta inválida de la API de Claude.');
    }

    if ($http_code !== 200) {
        $error_msg = $data['error']['message'] ?? 'API error';
        error_log("API Error: $error_msg");
        throw new RuntimeException($error_msg);
    }

    // Loguear y capturar tokens
    $usage_data = [];
    if (!empty($data['usage'])) {
        $u = $data['usage'];
        $usage_data = [
            'input_tokens'      => $u['input_tokens']            ?? 0,
            'output_tokens'     => $u['output_tokens']           ?? 0,
            'cache_read_tokens' => $u['cache_read_input_tokens'] ?? 0,
        ];
        error_log("Tokens — input: " . $usage_data['input_tokens'] .
                  ", output: " . $usage_data['output_tokens'] .
                  ", cache_read: " . $usage_data['cache_read_tokens']);
    }

    error_log("Stop reason: " . ($data['stop_reason'] ?? 'unknown'));

    // ── Extraer texto de la respuesta ────────────────────────────────────────
    $raw_text = '';
    foreach ($data['content'] ?? [] as $block) {
        if (($block['type'] ?? '') === 'text') {
            $raw_text .= $block['text'];
        }
    }

    if (!$raw_text) {
        throw new RuntimeException('Claude no devolvió ningún texto en la respuesta.');
    }

    // ── Limpiar markdown si Claude lo incluyó de todas formas ───────────────
    $json_text = trim($raw_text);

    // Quitar bloque ```json ... ``` o ``` ... ```
    if (preg_match('/```(?:json)?\s*([\s\S]+?)\s*```/i', $json_text, $m)) {
        $json_text = trim($m[1]);
    }

    // Asegurarse de que empieza con { (descartar texto previo si lo hay)
    $brace_pos = strpos($json_text, '{');
    if ($brace_pos === false) {
        error_log("No JSON object found in response: " . substr($json_text, 0, 300));
        throw new RuntimeException('Claude no devolvió un objeto JSON válido.');
    }
    if ($brace_pos > 0) {
        $json_text = substr($json_text, $brace_pos);
    }

    // ── Validar JSON ─────────────────────────────────────────────────────────
    $json_decoded = json_decode($json_text, true);
    if ($json_decoded === null) {
        error_log("Invalid JSON from Claude: " . substr($json_text, 0, 500));
        throw new RuntimeException('Claude devolvió JSON malformado. Detalle: ' . json_last_error_msg());
    }

    // ── Guardar JSON en /casos/ ──────────────────────────────────────────────
    $casos_dir = __DIR__ . '/casos';
    if (!is_dir($casos_dir)) {
        mkdir($casos_dir, 0755, true);
    }

    $raw_name      = $json_decoded['_meta']['output_filename'] ?? 'demanda.docx';
    $safe_base     = preg_replace('/[^a-zA-Z0-9_\-]/', '_', pathinfo($raw_name, PATHINFO_FILENAME));
    $json_basename = time() . '_' . strtolower($safe_base) . '.json';
    $json_path     = $casos_dir . '/' . $json_basename;

    file_put_contents($json_path, $json_text);
    error_log("JSON saved: $json_path (" . strlen($json_text) . " bytes)");

    // ── Generar .docx con Node.js ────────────────────────────────────────────
    $docx_path = generar_docx_desde_json($json_path);

    if (!$docx_path) {
        throw new RuntimeException('Error al generar el documento .docx con Node.js. Revisa el log del servidor.');
    }

    $docx_filename = basename($docx_path);
    $active_model  = defined('ADMIN_CLAUDE_MODEL') && ADMIN_CLAUDE_MODEL ? ADMIN_CLAUDE_MODEL : CLAUDE_MODEL;
    error_log("=== SUCCESS: DOCX generated in {$elapsed}s — $docx_filename ===");

    // Logging is handled by worker.php when called from queue
    // For direct calls, log here
    if ($user_email) {
        log_generation([
            'filename'      => $docx_filename,
            'email'         => $user_email,
            'model'         => $active_model,
            'input_tokens'  => $usage_data['input_tokens']  ?? 0,
            'output_tokens' => $usage_data['output_tokens'] ?? 0,
            'elapsed'       => $elapsed,
            'generated_at'  => (new DateTime('now', new DateTimeZone('America/Santiago')))->format('c'),
        ]);
    }

    return [
        'success'    => true,
        'type'       => 'files',
        'files'      => [[
            'filename' => $docx_filename,
            'path'     => $docx_path,
            'url'      => 'download.php?file=' . urlencode($docx_filename),
        ]],
        'json_saved' => $json_basename,
        'message'    => 'Documento generado exitosamente',
        'usage'      => $usage_data,
        'elapsed'    => $elapsed,
        'model_used' => $active_model,
    ];
}

/**
 * Ejecuta generar_demanda.js con Node.js.
 * Argumentos: <json_path> <output_dir>
 * El script imprime: "Demanda creada exitosamente: <filename>"
 *
 * @return string|null  Path absoluto del .docx generado, o null en error.
 */
function generar_docx_desde_json(string $json_path): ?string {
    $script_path   = __DIR__ . '/generar_demanda.js';
    $downloads_dir = __DIR__ . '/downloads';

    if (!is_dir($downloads_dir)) {
        mkdir($downloads_dir, 0755, true);
    }

    // Rutas candidatas de node según sistema operativo
    // SiteGround y hosting compartido Linux suelen tener node en /usr/local/bin o ~/.nvm
    $is_windows = PHP_OS_FAMILY === 'Windows';

    $node_candidates = $is_windows ? [
        'C:\\Program Files\\nodejs\\node.exe',
        'C:\\Program Files (x86)\\nodejs\\node.exe',
        'node',
        'C:\\nvm4w\\nodejs\\node.exe',
    ] : [
        '/home/u2686-msfhcggc1qfs/.nvm/versions/node/v20.20.2/bin/node',
        '/usr/bin/node',
        '/usr/local/bin/node',
        '/usr/local/nodejs/bin/node',       // SiteGround instalación manual
        '/opt/nodejs/bin/node',             // SiteGround alternativo
        '/opt/alt/node20/usr/bin/node',     // CloudLinux / cPanel
        '/opt/alt/node18/usr/bin/node',
        '/opt/alt/node16/usr/bin/node',
        '/home/' . get_current_user() . '/.nvm/versions/node/v20/bin/node',  // nvm usuario
        '/home/' . get_current_user() . '/.nvm/versions/node/v18/bin/node',
        'node',                             // si está en PATH del proceso PHP
    ];

    // Encontrar el primer ejecutable disponible
    $node_bin = null;
    foreach ($node_candidates as $candidate) {
        // Verificar existencia directa del archivo (más fiable que which/where)
        if (file_exists($candidate) && is_executable($candidate)) {
            $node_bin = $candidate;
            break;
        }
    }

    // Fallback: intentar resolverlo con which/where
    if (!$node_bin) {
        $which_cmd = $is_windows ? 'where node 2>NUL' : 'which node 2>/dev/null';
        $which_out = shell_exec($which_cmd);
        if ($which_out && trim($which_out) !== '') {
            $node_bin = trim(explode("\n", $which_out)[0]);
        }
    }

    if (!$node_bin) {
        error_log("Node.js not found. Tried: " . implode(', ', $node_candidates));
        return null;
    }

    error_log("Using node: $node_bin");

    // Extender el PATH para que node pueda encontrar sus propios módulos
    $node_dir  = dirname($node_bin);
    $extra_paths = [
        $node_dir,
        '/usr/bin',
        '/usr/local/bin',
        '/opt/nodejs/bin',
        '/opt/alt/node20/usr/bin',
    ];
    $current_path = getenv('PATH') ?: '/usr/bin:/bin';
    $env_path = implode(':', array_unique(array_merge($extra_paths, explode(':', $current_path))));

    $env = array_merge($_ENV ?: [], [
        'PATH'     => $env_path,
        'HOME'     => getenv('HOME') ?: '/tmp',
        'NODE_ENV' => 'production',
    ]);

    // Construir el comando con paths absolutos
    $cmd = sprintf(
        '%s %s %s %s',
        escapeshellarg($node_bin),
        escapeshellarg($script_path),
        escapeshellarg($json_path),
        escapeshellarg($downloads_dir)
    );

    error_log("Running: $cmd (cwd: " . __DIR__ . ")");

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    // proc_open con CWD = __DIR__ para que require('docx') encuentre node_modules/
    $process = proc_open($cmd, $descriptors, $pipes, __DIR__, $env);

    if (!is_resource($process)) {
        error_log("proc_open failed");
        return null;
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit_code = proc_close($process);

    $output = trim($stdout . "\n" . $stderr);
    error_log("Node.js exit code: $exit_code");
    error_log("Node.js output: " . ($output ?: '(empty)'));

    if ($exit_code !== 0) {
        error_log("Node.js failed with exit code $exit_code");
        // No retornar null todavía — puede que el archivo igual se haya generado
    }

    // Buscar el nombre del archivo en el output del script
    // generar_demanda.js imprime: "Demanda creada exitosamente: <filename>"
    if (preg_match('/Demanda creada exitosamente:\s*(.+\.docx)/i', $output, $m)) {
        $reported_name = trim(basename($m[1]));

        $candidate = $downloads_dir . '/' . $reported_name;
        if (file_exists($candidate)) {
            return $candidate;
        }

        // El script podría haber escrito en el CWD (__DIR__)
        $alt = __DIR__ . '/' . $reported_name;
        if (file_exists($alt)) {
            $dest = $downloads_dir . '/' . $reported_name;
            rename($alt, $dest);
            return $dest;
        }
    }

    // Fallback: el .docx más reciente en downloads_dir (creado en los últimos 90s)
    $files = glob($downloads_dir . '/*.docx');
    if ($files) {
        usort($files, fn($a, $b) => filemtime($b) - filemtime($a));
        if (time() - filemtime($files[0]) < 90) {
            return $files[0];
        }
    }

    error_log("Could not locate generated .docx — Node output was: " . substr($output, 0, 500));
    return null;
}

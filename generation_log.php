<?php
/**
 * generation_log.php
 * Funciones para registrar y leer el historial de generaciones.
 * El log se guarda en downloads/generation_log.json (fuera del webroot ideal,
 * pero protegido por .htaccess para que no sea descargable directamente).
 */
if (php_sapi_name() !== 'cli' && basename($_SERVER['PHP_SELF']) === 'generation_log.php') {
    http_response_code(403);
    exit;
}

define('GENERATION_LOG_FILE', __DIR__ . '/downloads/generation_log.json');

/**
 * Registra una generación completada.
 *
 * @param array $entry {
 *   filename    string   Nombre del .docx generado
 *   email       string   Email del usuario que generó
 *   model       string   Modelo Claude utilizado
 *   input_tokens  int
 *   output_tokens int
 *   elapsed     float    Segundos que tardó Claude
 *   generated_at string  Fecha ISO 8601
 * }
 */
function log_generation(array $entry): void {
    $log = read_generation_log();

    // Fecha en zona horaria de Santiago de Chile (UTC-4 / UTC-3 según DST)
    $tz_santiago = new DateTimeZone('America/Santiago');
    $now_santiago = new DateTime('now', $tz_santiago);

    // Asegurar campos mínimos
    $entry = array_merge([
        'filename'      => '',
        'email'         => '',
        'model'         => '',
        'input_tokens'  => 0,
        'output_tokens' => 0,
        'elapsed'       => 0,
        'generated_at'  => $now_santiago->format('c'),
    ], $entry);

    array_unshift($log, $entry); // más reciente primero

    // Limitar a 500 entradas para no crecer indefinidamente
    $log = array_slice($log, 0, 500);

    file_put_contents(
        GENERATION_LOG_FILE,
        json_encode($log, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        LOCK_EX
    );
}

/**
 * Lee el historial completo.
 * @return array
 */
function read_generation_log(): array {
    if (!file_exists(GENERATION_LOG_FILE)) {
        return [];
    }
    $raw = file_get_contents(GENERATION_LOG_FILE);
    if (!$raw) return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

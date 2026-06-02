<?php
/**
 * Job Status Endpoint — Polling para frontend
 * 
 * Retorna el estado actual de un job:
 * - pending: esperando procesamiento
 * - running: procesando activamente
 * - done: completado exitosamente
 * - error: falló
 * - not_found: job_id no existe
 */

session_start();

// Solo usuarios autenticados pueden consultar estado
if (!isset($_SESSION['authorized_email'])) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'error' => 'No autenticado']);
    exit;
}

require_once __DIR__ . '/job_queue.php';

$job_id = $_GET['id'] ?? '';

if (!$job_id) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'error' => 'Falta job_id']);
    exit;
}

$job = job_read($job_id);

if (!$job) {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'not_found']);
    exit;
}

// Construir respuesta según estado
$response = [
    'status' => $job['status'],
];

if ($job['status'] === 'running') {
    $response['elapsed'] = job_elapsed($job_id);
}

if ($job['status'] === 'done') {
    $response['result'] = $job['result'];
}

if ($job['status'] === 'error') {
    $response['error'] = $job['error'] ?? 'Error desconocido';
}

header('Content-Type: application/json');
echo json_encode($response, JSON_UNESCAPED_UNICODE);

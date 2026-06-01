<?php
require_once __DIR__ . '/config.php';

session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: login.php');
    exit;
}

$email = strtolower(trim($_POST['email'] ?? ''));

if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    header('Location: login.php?error=invalid');
    exit;
}

// Leer el Sheet publicado como CSV
$csv = @file_get_contents(SHEET_CSV_URL);

if ($csv === false) {
    header('Location: login.php?error=sheet');
    exit;
}

/**
 * Parsear CSV: fila 1 = cabecera (CORREO, TIPO), ignorar.
 * Fila 2 en adelante: col A = email, col B = rol (ADMIN | USUARIO).
 * Construir mapa email => rol.
 */
$user_map = [];
$rows = str_getcsv($csv, "\n");

foreach ($rows as $i => $row) {
    if ($i === 0) continue; // saltar cabecera

    $cols  = str_getcsv($row);
    $mail  = strtolower(trim($cols[0] ?? ''));
    $role  = strtoupper(trim($cols[1] ?? 'USUARIO'));

    if ($mail && filter_var($mail, FILTER_VALIDATE_EMAIL)) {
        // Normalizar: solo ADMIN o USUARIO
        $user_map[$mail] = ($role === 'ADMIN') ? 'ADMIN' : 'USUARIO';
    }
}

if (isset($user_map[$email])) {
    $_SESSION['authorized_email'] = $email;
    $_SESSION['user_role']        = $user_map[$email];
    header('Location: index.php');
} else {
    header('Location: login.php?error=unauthorized');
}
exit;

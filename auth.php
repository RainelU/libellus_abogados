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

// Parsear todos los correos del sheet (una columna, una por fila)
$authorized = [];
foreach (str_getcsv($csv, "\n") as $row) {
    $cell = strtolower(trim(str_getcsv($row)[0] ?? ''));
    if ($cell) {
        $authorized[] = $cell;
    }
}

if (in_array($email, $authorized, true)) {
    $_SESSION['authorized_email'] = $email;
    header('Location: index.php');
} else {
    header('Location: login.php?error=unauthorized');
}
exit;

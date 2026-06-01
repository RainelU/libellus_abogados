<?php
require_once __DIR__ . '/login_config.php';
session_start();
if (isset($_SESSION['authorized_email'])) {
    header('Location: gate.php');
    exit;
}

$error = $_GET['error'] ?? '';
$messages = [
    'unauthorized' => 'Este correo no tiene acceso. Contactá al administrador.',
    'invalid'      => 'Ingresá una dirección de correo válida.',
    'sheet'        => 'No se pudo verificar el acceso. Intentá más tarde.',
];
$error_msg = $messages[$error] ?? '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <title><?= APP_NAME ?> — Iniciar sesión</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=EB+Garamond:wght@400;500&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    body {
      font-family: 'Inter', sans-serif;
      display: flex;
      min-height: 100vh;
      background: #f5f5f5;
    }

    /* ── Panel izquierdo ── */
    .panel-left {
      width: 42%;
      background: #1a2f52;
      background-image:
        linear-gradient(rgba(255,255,255,0.04) 1px, transparent 1px),
        linear-gradient(90deg, rgba(255,255,255,0.04) 1px, transparent 1px);
      background-size: 32px 32px;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      padding: 36px 40px;
      position: relative;
    }

    .badge {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      background: rgba(255,255,255,0.1);
      border: 1px solid rgba(255,255,255,0.18);
      border-radius: 100px;
      padding: 6px 14px;
      width: fit-content;
    }
    .badge-dot {
      width: 8px;
      height: 8px;
      border-radius: 50%;
      background: #c8a84b;
    }
    .badge-text {
      font-size: 11px;
      font-weight: 600;
      letter-spacing: 0.08em;
      color: rgba(255,255,255,0.85);
      text-transform: uppercase;
    }

    .brand {
      flex: 1;
      display: flex;
      align-items: center;
    }
    .brand-name {
      font-family: 'EB Garamond', serif;
      font-size: 64px;
      font-weight: 400;
      color: #ffffff;
      letter-spacing: -1px;
      line-height: 1;
    }
    .brand-line {
      width: 48px;
      height: 2px;
      background: #c8a84b;
      margin-top: 16px;
    }

    .version {
      text-align: center;
      font-size: 11px;
      letter-spacing: 0.12em;
      color: rgba(255,255,255,0.35);
      text-transform: uppercase;
    }

    /* ── Panel derecho ── */
    .panel-right {
      flex: 1;
      display: flex;
      align-items: center;
      justify-content: center;
      background: #ffffff;
      padding: 40px;
    }

    .login-box {
      width: 100%;
      max-width: 340px;
    }

    .login-title {
      font-size: 26px;
      font-weight: 600;
      color: #1a1a2e;
      margin-bottom: 8px;
    }
    .login-subtitle {
      font-size: 14px;
      color: #6b7280;
      margin-bottom: 32px;
      line-height: 1.5;
    }

    .form-group {
      margin-bottom: 16px;
    }
    .form-group label {
      display: block;
      font-size: 13px;
      font-weight: 500;
      color: #374151;
      margin-bottom: 6px;
    }
    .form-group input {
      width: 100%;
      padding: 11px 14px;
      border: 1.5px solid #d1d5db;
      border-radius: 8px;
      font-size: 14px;
      color: #111827;
      outline: none;
      transition: border-color 0.15s;
      font-family: 'Inter', sans-serif;
    }
    .form-group input:focus {
      border-color: #1a2f52;
    }

    .btn-submit {
      width: 100%;
      padding: 12px;
      background: #1a2f52;
      color: #ffffff;
      border: none;
      border-radius: 8px;
      font-size: 14px;
      font-weight: 600;
      cursor: pointer;
      margin-top: 8px;
      transition: background 0.15s;
      font-family: 'Inter', sans-serif;
    }
    .btn-submit:hover {
      background: #14243f;
    }

    .error-msg {
      background: #fef2f2;
      border: 1px solid #fecaca;
      border-radius: 8px;
      padding: 12px 14px;
      font-size: 13px;
      color: #dc2626;
      margin-bottom: 20px;
      line-height: 1.4;
    }

    .login-footer {
      margin-top: 24px;
      font-size: 12px;
      color: #9ca3af;
      text-align: center;
    }

    @media (max-width: 640px) {
      body { flex-direction: column; }
      .panel-left { width: 100%; min-height: 200px; padding: 28px 24px; }
      .brand-name { font-size: 48px; }
      .panel-right { padding: 32px 24px; }
    }
  </style>
</head>
<body>

  <div class="panel-left">
    <div class="badge">
      <span class="badge-dot"></span>
      <span class="badge-text">Acceso Privado</span>
    </div>

    <div class="brand">
      <div>
        <div class="brand-name"><?= APP_NAME ?></div>
        <div class="brand-line"></div>
      </div>
    </div>

    <div class="version"><?= APP_VERSION ?></div>
  </div>

  <div class="panel-right">
    <div class="login-box">
      <h1 class="login-title">Iniciar sesión</h1>
      <p class="login-subtitle">Accede con tu correo autorizado.</p>

      <?php if ($error_msg): ?>
        <div class="error-msg"><?= htmlspecialchars($error_msg) ?></div>
      <?php endif; ?>

      <form method="POST" action="auth.php">
        <div class="form-group">
          <label for="email">Correo electrónico</label>
          <input
            type="email"
            id="email"
            name="email"
            placeholder="tunombre@email.com"
            required
            autocomplete="email"
            autofocus
          >
        </div>
        <button type="submit" class="btn-submit">Ingresar</button>
      </form>

      <p class="login-footer">¿No tienes acceso? Contacta al administrador.</p>
    </div>
  </div>

</body>
</html>

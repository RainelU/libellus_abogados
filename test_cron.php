<?php
/**
 * Panel de monitoreo del sistema de colas
 * https://app.libellus.cl/test_cron.php
 */
session_start();
if (empty($_SESSION['authorized_email'])) { http_response_code(403); exit('Login required'); }

require_once __DIR__ . '/job_queue.php';

// Acciones POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    // Limpiar lock colgado
    if ($action === 'clear_lock') {
        $lock = __DIR__ . '/jobs/.cron.lock';
        $deleted = file_exists($lock) ? @unlink($lock) : false;
        echo json_encode(['ok' => true, 'deleted' => $deleted]);
        exit;
    }

    // Cancelar todos los pending/running atascados
    if ($action === 'cancel_stuck') {
        $cancelled = 0;
        foreach (glob(__DIR__ . '/jobs/*.json') ?: [] as $f) {
            $j = json_decode(file_get_contents($f), true);
            if (!$j) continue;
            $stuck = false;
            if ($j['status'] === 'pending') $stuck = true;
            if ($j['status'] === 'running' && (time() - ($j['started_at'] ?? time())) > 600) $stuck = true;
            if ($stuck) {
                job_update($j['job_id'], [
                    'status'      => 'error',
                    'finished_at' => time(),
                    'error'       => 'Cancelado manualmente desde panel de monitoreo',
                ]);
                $cancelled++;
            }
        }
        echo json_encode(['ok' => true, 'cancelled' => $cancelled]);
        exit;
    }

    // Reset running atascado a pending
    if ($action === 'reset_stuck_running') {
        $reset = 0;
        foreach (glob(__DIR__ . '/jobs/*.json') ?: [] as $f) {
            $j = json_decode(file_get_contents($f), true);
            if (!$j) continue;
            if ($j['status'] === 'running' && (time() - ($j['started_at'] ?? time())) > 300) {
                job_update($j['job_id'], ['status' => 'pending', 'started_at' => null]);
                $reset++;
            }
        }
        echo json_encode(['ok' => true, 'reset' => $reset]);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'unknown action']);
    exit;
}

// Datos para la vista
$lock      = __DIR__ . '/jobs/.cron.lock';
$lock_age  = file_exists($lock) ? (time() - filemtime($lock)) : null;
$lock_pid  = file_exists($lock) ? trim(file_get_contents($lock)) : null;

$all_jobs  = [];
foreach (glob(__DIR__ . '/jobs/*.json') ?: [] as $f) {
    $j = json_decode(file_get_contents($f), true);
    if ($j && !str_starts_with($j['job_id'], 'ping_test_') && !str_starts_with($j['job_id'], 'flush_test_')) {
        $all_jobs[] = $j;
    }
}
usort($all_jobs, fn($a, $b) => $b['created_at'] - $a['created_at']);

$php_bins = ['/usr/local/php83/bin/php', '/usr/local/php82/bin/php', '/usr/bin/php'];
$php_ok   = array_filter($php_bins, 'file_exists');
?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta http-equiv="refresh" content="10"> <!-- auto-refresh cada 10s -->
<title>Queue Monitor — Libellus</title>
<style>
* { box-sizing: border-box; }
body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; 
       background: #f5f5f5; margin: 0; padding: 20px; }
.container { max-width: 900px; margin: 0 auto; }
h1 { color: #1a2f52; margin-bottom: 4px; }
.refresh-note { color: #888; font-size: 12px; margin-bottom: 20px; }
.card { background: white; border-radius: 8px; padding: 16px; margin-bottom: 16px; 
        box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
.card h2 { margin: 0 0 12px; font-size: 15px; color: #333; border-bottom: 1px solid #eee; padding-bottom: 8px; }
.row { display: flex; gap: 12px; margin-bottom: 12px; }
.stat { background: #f8f9fa; border-radius: 6px; padding: 12px 16px; flex: 1; text-align: center; }
.stat .n { font-size: 28px; font-weight: 700; }
.stat .l { font-size: 12px; color: #666; }
.pending .n { color: #f59e0b; }
.running .n { color: #3b82f6; }
.done    .n { color: #22c55e; }
.error   .n { color: #ef4444; }
table { width: 100%; border-collapse: collapse; font-size: 13px; }
th { text-align: left; padding: 6px 10px; background: #f8f9fa; color: #555; font-weight: 600; }
td { padding: 6px 10px; border-bottom: 1px solid #f0f0f0; }
tr:last-child td { border-bottom: none; }
.badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 600; }
.badge-pending { background: #fef3c7; color: #92400e; }
.badge-running { background: #dbeafe; color: #1e40af; }
.badge-done    { background: #dcfce7; color: #166534; }
.badge-error   { background: #fee2e2; color: #991b1b; }
.lock-ok   { color: #22c55e; font-weight: 600; }
.lock-warn { color: #f59e0b; font-weight: 600; }
.lock-err  { color: #ef4444; font-weight: 600; }
button { padding: 6px 14px; border: none; border-radius: 5px; cursor: pointer; font-size: 13px; font-weight: 500; }
.btn-danger  { background: #ef4444; color: white; }
.btn-warning { background: #f59e0b; color: white; }
.btn-primary { background: #3b82f6; color: white; }
.actions { display: flex; gap: 8px; flex-wrap: wrap; }
.cron-cmd { background: #1e1e1e; color: #d4d4d4; padding: 10px 14px; border-radius: 5px; 
            font-family: monospace; font-size: 12px; margin-top: 8px; word-break: break-all; }
</style>
</head>
<body>
<div class="container">

<h1>🔍 Queue Monitor</h1>
<p class="refresh-note">Auto-refresh cada 10s · <?= date('H:i:s') ?> · <a href="?">Refrescar ahora</a></p>

<!-- Stats -->
<?php
$counts = ['pending'=>0,'running'=>0,'done'=>0,'error'=>0];
foreach ($all_jobs as $j) {
    $s = $j['status'] ?? 'unknown';
    if (isset($counts[$s])) $counts[$s]++;
}
?>
<div class="row">
    <div class="stat pending"><div class="n"><?= $counts['pending'] ?></div><div class="l">En cola</div></div>
    <div class="stat running"><div class="n"><?= $counts['running'] ?></div><div class="l">Procesando</div></div>
    <div class="stat done">   <div class="n"><?= $counts['done'] ?></div>   <div class="l">Completados</div></div>
    <div class="stat error">  <div class="n"><?= $counts['error'] ?></div>  <div class="l">Con error</div></div>
</div>

<!-- Lock file -->
<div class="card">
<h2>🔒 Cron Lock</h2>
<?php if ($lock_age === null): ?>
    <span class="lock-ok">✅ Sin lock — cron disponible para ejecutar</span>
<?php elseif ($lock_age < 540): ?>
    <span class="lock-warn">⚙️ Cron corriendo (PID: <?= $lock_pid ?>, hace <?= $lock_age ?>s)</span>
    <br><small style="color:#888">Normal si hay jobs procesándose</small>
<?php else: ?>
    <span class="lock-err">❌ Lock colgado (<?= $lock_age ?>s) — PID: <?= $lock_pid ?></span>
    <br><small style="color:#888">El cron anterior se colgó. Limpiarlo para desbloquear.</small>
    <br><br>
    <button class="btn-danger" onclick="doAction('clear_lock')">🗑 Limpiar lock colgado</button>
<?php endif; ?>
</div>

<!-- Acciones -->
<div class="card">
<h2>⚡ Acciones</h2>
<div class="actions">
    <button class="btn-warning" onclick="doAction('reset_stuck_running')">↩️ Reset running >5min a pending</button>
    <button class="btn-danger"  onclick="doAction('cancel_stuck')">❌ Cancelar todos los pending/stuck</button>
    <?php if ($lock_age !== null && $lock_age >= 540): ?>
    <button class="btn-danger"  onclick="doAction('clear_lock')">🗑 Limpiar lock</button>
    <?php endif; ?>
</div>
<div id="action-result" style="margin-top:10px;font-size:13px;color:#333"></div>
</div>

<!-- Cron config -->
<div class="card">
<h2>⏰ Configuración del Cron</h2>
<p style="font-size:13px;margin:0 0 6px">Comando para SiteGround cPanel → Cron Jobs (<strong>cada 1 minuto: * * * * *</strong>):</p>
<div class="cron-cmd"><?= htmlspecialchars(reset($php_ok) . ' ' . __DIR__ . '/cron_worker.php') ?></div>
<p style="font-size:12px;color:#888;margin:6px 0 0">PHP disponibles: <?= implode(', ', array_keys(array_flip($php_ok))) ?></p>
</div>

<!-- Jobs recientes -->
<div class="card">
<h2>📋 Jobs (<?= count($all_jobs) ?> total, mostrando últimos 20)</h2>
<?php if (empty($all_jobs)): ?>
    <p style="color:#888;font-size:13px">No hay jobs.</p>
<?php else: ?>
<table>
<thead><tr>
    <th>ID</th><th>Email</th><th>Estado</th><th>Creado</th><th>Duración</th><th>Info</th>
</tr></thead>
<tbody>
<?php foreach (array_slice($all_jobs, 0, 20) as $j):
    $s       = $j['status'];
    $created = date('H:i:s', $j['created_at']);
    $dur     = '';
    if ($j['started_at'] && $j['finished_at']) {
        $secs = $j['finished_at'] - $j['started_at'];
        $dur  = $secs >= 60 ? floor($secs/60).'m '.($secs%60).'s' : $secs.'s';
    } elseif ($j['started_at']) {
        $secs = time() - $j['started_at'];
        $dur  = '▶ ' . ($secs >= 60 ? floor($secs/60).'m '.($secs%60).'s' : $secs.'s');
    }
    $info = '';
    if ($s === 'done')  $info = $j['result']['files'][0]['filename'] ?? '';
    if ($s === 'error') $info = substr($j['error'] ?? '', 0, 60);
    $email_short = explode('@', $j['data']['user_email'] ?? '')[0];
?>
<tr>
    <td style="font-family:monospace;font-size:11px"><?= substr($j['job_id'],0,8) ?>...</td>
    <td><?= htmlspecialchars($email_short) ?></td>
    <td><span class="badge badge-<?= $s ?>"><?= $s ?></span></td>
    <td><?= $created ?></td>
    <td><?= $dur ?></td>
    <td style="font-size:11px;color:#666;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
        title="<?= htmlspecialchars($info) ?>"><?= htmlspecialchars($info) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<?php endif; ?>
</div>

</div>

<script>
async function doAction(action) {
    const btn = event.target;
    btn.disabled = true;
    const result = document.getElementById('action-result');
    result.textContent = 'Ejecutando...';
    
    const body = new FormData();
    body.append('action', action);
    
    try {
        const res  = await fetch('', { method: 'POST', body });
        const data = await res.json();
        result.textContent = JSON.stringify(data);
        setTimeout(() => location.reload(), 1000);
    } catch(e) {
        result.textContent = 'Error: ' + e.message;
        btn.disabled = false;
    }
}
</script>
</body>
</html>

# 🔧 Troubleshooting - Sistema de Cola Asíncrona

## Comandos de Diagnóstico Rápido

### 1. Ver logs en tiempo real
```bash
# Logs de PHP
tail -f php_errorlog

# Filtrar solo mensajes del worker
tail -f php_errorlog | grep worker

# Filtrar solo mensajes de la cola
tail -f php_errorlog | grep queue
```

### 2. Verificar jobs activos
```bash
# Listar todos los jobs
ls -lh jobs/*.json

# Ver contenido de un job específico
cat jobs/<job_id>.json | python -m json.tool

# Contar jobs por estado
grep -h '"status"' jobs/*.json | sort | uniq -c
```

### 3. Verificar permisos
```bash
# Directorio jobs debe ser 755
ls -ld jobs/
# Debe mostrar: drwxr-xr-x

# Archivos JSON deben ser 644
ls -l jobs/*.json
# Debe mostrar: -rw-r--r--
```

### 4. Verificar protección .htaccess
```bash
# Debe dar 403
curl -I https://app.libellus.cl/jobs/
curl -I https://app.libellus.cl/job_queue.php
curl -I https://app.libellus.cl/worker.php
```

### 5. Test manual del worker
```bash
# Crear job de prueba
php -r "require 'job_queue.php'; echo job_create(['test'=>true]);"
# Output: <job_id>

# Ejecutar worker manualmente
php worker.php <job_id> wk_7f3a9b2e1d4c8f6a0e5b3d7c9a2f4e8b

# Ver resultado
cat jobs/<job_id>.json
```

---

## Problemas Comunes

### ❌ Error: "apache_setenv() undefined"
**Causa**: SiteGround usa PHP-FPM, no mod_php

**Solución**: ✅ Ya resuelto en la nueva versión

**Verificar**:
```bash
grep -n "apache_setenv" *.php
# Debe devolver: (sin resultados)
```

---

### ❌ Jobs quedan en "pending" forever

**Diagnóstico**:
```bash
# Ver jobs pendientes
grep -l '"status": "pending"' jobs/*.json

# Ver edad del job
stat jobs/<job_id>.json
```

**Causas posibles**:

#### 1. Worker no se está ejecutando
```bash
# Ver logs
tail -20 php_errorlog | grep "Launched worker"

# Si no aparece, verificar curl
php -r "echo function_exists('curl_init') ? 'OK' : 'FAIL';"
```

**Solución**: Instalar php-curl
```bash
# SiteGround: activar desde PHP Manager en cPanel
```

#### 2. Worker no tiene permisos
```bash
ls -l worker.php
# Debe ser readable: -rw-r--r--
```

**Solución**:
```bash
chmod 644 worker.php
```

#### 3. WORKER_SECRET incorrecto
```bash
# Verificar en config.php
grep WORKER_SECRET config.php

# Verificar que worker.php lo use igual
grep WORKER_SECRET worker.php
```

**Solución**: Asegurar mismo valor en ambos archivos

---

### ❌ Error 504 Gateway Timeout (todavía)

**Diagnóstico**:
```bash
# ¿Hay muchos jobs en "running"?
grep -h '"status": "running"' jobs/*.json | wc -l

# ¿Cuánto tiempo tienen?
grep -h '"started_at"' jobs/*.json
```

**Causas posibles**:

#### 1. Límite de workers PHP-FPM muy bajo
```bash
# Ver configuración PHP-FPM (SiteGround)
# cPanel → PHP Manager → PHP Settings → max_children
```

**Solución**: Aumentar `pm.max_children` a mínimo 10

#### 2. Timeout de curl muy largo bloqueando index.php
```php
// En index.php, verificar:
CURLOPT_TIMEOUT_MS => 5000  // debe ser 5s máximo
```

**Solución**: Ya está en 5000ms (5s) en el código nuevo

---

### ❌ Worker falla pero no hay error en logs

**Diagnóstico**:
```bash
# Ver jobs con error
grep -l '"status": "error"' jobs/*.json

# Ver mensaje de error
cat jobs/<job_id>.json | grep -A 2 '"error"'
```

**Verificar**:
```bash
# ¿Node.js está disponible?
which node
# o en SiteGround:
/home/u2686-msfhcggc1qfs/.nvm/versions/node/v20.20.2/bin/node --version

# ¿claude.php funciona?
php -r "require 'claude.php'; echo 'OK';"
```

---

### ❌ "Unexpected non-whitespace character after JSON"

**Causa**: Output extra antes del JSON en worker.php o job_status.php

**Diagnóstico**:
```bash
# Test directo
curl "https://app.libellus.cl/job_status.php?id=<job_id>"
# Debe empezar con { sin nada antes
```

**Solución verificada**: Ya corregido con:
- `CURLOPT_RETURNTRANSFER => true` en index.php
- Headers correctos en job_status.php
- Sin echo/print antes del JSON

---

### ❌ localStorage no funciona (no recupera job)

**Diagnóstico**:
```javascript
// En consola del navegador:
console.log(localStorage.getItem('libellus_current_job'));
// Debe mostrar job_id durante generación
```

**Causas posibles**:

#### 1. localStorage bloqueado por política del navegador
**Solución**: Verificar navegador no está en modo privado estricto

#### 2. Job_id no se está guardando
**Verificar en app.js**:
```javascript
// Debe tener:
saveCurrentJob(data.job_id);
```

#### 3. recoverPendingJob() no se ejecuta
**Verificar en consola**:
```javascript
// Al cargar página, debe aparecer:
// "Recovered job: <job_id>" (si hay job pendiente)
```

---

### ❌ Polling tarda demasiado (>12 min)

**Diagnóstico**:
```bash
# Ver tiempo de ejecución del worker
grep "completed successfully in" php_errorlog | tail -5

# Ver cuánto tardó Claude
grep "Response in" php_errorlog | tail -5
```

**Solución**: Ajustar timeout en app.js:
```javascript
const MAX_POLLS = 240; // 240 × 4s = 16 minutos
```

---

### ❌ Jobs no se limpian automáticamente

**Diagnóstico**:
```bash
# ¿Hay jobs antiguos?
find jobs/ -name "*.json" -mtime +1 -ls
```

**Solución manual**:
```php
<?php
require_once 'job_queue.php';
job_cleanup();
echo "Cleanup completed\n";
```

**Solución automática**: Crear cron job
```bash
# crontab -e
0 3 * * * php /path/to/job_queue.php -r "require 'job_queue.php'; job_cleanup();"
```

---

## Monitoreo de Salud del Sistema

### Script de monitoreo (guardar como `health_check.php`)
```php
<?php
require_once 'job_queue.php';

header('Content-Type: application/json');

$jobs = glob(JOBS_DIR . '*.json');
$stats = [
    'total'   => count($jobs),
    'pending' => 0,
    'running' => 0,
    'done'    => 0,
    'error'   => 0,
    'old'     => 0, // >24h
];

foreach ($jobs as $file) {
    $job = json_decode(file_get_contents($file), true);
    if (!$job) continue;
    
    $stats[$job['status']]++;
    
    if ($job['created_at'] < time() - 86400) {
        $stats['old']++;
    }
}

echo json_encode($stats, JSON_PRETTY_PRINT);
```

**Uso**:
```bash
curl https://app.libellus.cl/health_check.php

# Output esperado:
# {
#   "total": 5,
#   "pending": 0,
#   "running": 1,
#   "done": 4,
#   "error": 0,
#   "old": 2
# }
```

---

## Logs de Ejemplo Saludables

### Generación exitosa:
```
[02-Jun-2026 00:04:58 UTC] [queue] Launched worker for job: abc123 (curl errno: 28)
[02-Jun-2026 00:05:00 UTC] [worker] Starting job: abc123
[02-Jun-2026 00:05:00 UTC] [worker] Job abc123 marked as running
[02-Jun-2026 00:05:02 UTC] === Claude API Call Started ===
[02-Jun-2026 00:07:45 UTC] Response in 163.2s — HTTP 200 — 45,230 bytes
[02-Jun-2026 00:07:46 UTC] JSON saved: /casos/1234567_demanda.json
[02-Jun-2026 00:07:47 UTC] Node.js exit code: 0
[02-Jun-2026 00:07:47 UTC] [worker] Job abc123 completed successfully in 163.2s
```

### curl errno 28 es NORMAL:
```
errno: 28 = CURLE_OPERATION_TIMEDOUT
```
Esto es ESPERADO porque configuramos timeout de 5s para que index.php no espere. El worker sigue corriendo gracias a `ignore_user_abort(true)`.

---

## Contacto y Soporte

Si después de revisar este documento el problema persiste:

1. **Recopilar información**:
```bash
# Logs recientes
tail -100 php_errorlog > debug_logs.txt

# Jobs actuales
ls -lh jobs/*.json > jobs_status.txt
cat jobs/*.json > jobs_content.txt

# Configuración
php -i > phpinfo.txt
```

2. **Verificar versiones**:
```bash
php -v
node -v
apache2 -v  # o nginx -v
```

3. **Test de conectividad**:
```bash
curl -v https://api.anthropic.com/v1/messages
```

4. **Adjuntar archivos** a ticket de soporte:
   - debug_logs.txt
   - jobs_status.txt
   - Descripción detallada del problema
   - Pasos para reproducir

---

**Última actualización**: Junio 2026
**Versión**: 1.0.0

# 🚀 Guía de Deployment - SiteGround

## Pre-Deployment Checklist

- [ ] Código testeado en local
- [ ] Backup de base de datos (si aplica)
- [ ] Backup de archivos actuales en producción

## 📦 Archivos a Subir

### Nuevos archivos (copiar a producción):
```
job_queue.php
worker.php
job_status.php
jobs/.htaccess
verify_production.php          # temporal, eliminar después
process_all_pending.php        # útil para debugging
```

### Archivos modificados (reemplazar):
```
index.php
assets/app.js
claude.php
.htaccess
.gitignore
```

### NO subir (solo local):
```
test_worker_launch.php
process_pending_jobs.php
QUEUE_SYSTEM_README.md
TESTING_CHECKLIST.md
TROUBLESHOOTING.md
DEPLOYMENT_GUIDE.md
```

## 🔧 Pasos de Deployment

### 1. Subir archivos via FTP/SFTP o File Manager

```bash
# Via SFTP (si tienes acceso SSH):
cd /home/customer/www/app.libellus.cl/public_html/

# Subir archivos nuevos
upload job_queue.php
upload worker.php
upload job_status.php
upload verify_production.php
upload process_all_pending.php

# Subir archivos modificados
upload index.php
upload assets/app.js
upload claude.php
upload .htaccess
upload .gitignore

# Crear directorio jobs si no existe
mkdir -p jobs
upload jobs/.htaccess
chmod 755 jobs/
```

### 2. Verificar permisos

```bash
# Directorios deben ser 755
chmod 755 jobs/
chmod 755 downloads/
chmod 755 casos/

# Archivos PHP deben ser 644
chmod 644 *.php
chmod 644 jobs/.htaccess
chmod 644 downloads/.htaccess
```

### 3. Verificar configuración

Asegúrate que `config.php` tiene:
```php
define('WORKER_SECRET', 'wk_7f3a9b2e1d4c8f6a0e5b3d7c9a2f4e8b');
```

### 4. Ejecutar verificación

Accede a:
```
https://app.libellus.cl/verify_production.php?secret=CHECK_SYSTEM
```

**Debe mostrar:**
- ✅ PHP Version: 8.3.x
- ✅ Server API: fpm-fcgi o cgi-fcgi
- ✅ fastcgi_finish_request(): Disponible
- ✅ ignore_user_abort(): Disponible
- ✅ curl: Instalado
- ✅ Todos los archivos existen
- ✅ jobs/ escribible
- ✅ WORKER_SECRET configurado

### 5. Test funcional

1. **Login** en https://app.libellus.cl/
2. **Subir 2 PDFs** de prueba
3. **Click "Generar Demanda"**
4. **Verificar**:
   - ✅ Responde en <2 segundos
   - ✅ Mensaje "Solicitud enviada"
   - ✅ Polling inicia (cada 4s)
   - ✅ Progreso actualizado
   - ✅ Documento se genera (2-5 min)
   - ✅ Botón descarga aparece
   - ✅ Stats se muestran

### 6. Verificar logs

```bash
# Ver logs en tiempo real
tail -f /home/customer/www/app.libellus.cl/public_html/php_errorlog

# Buscar mensajes del worker
grep worker php_errorlog | tail -20

# Buscar mensajes de la cola
grep queue php_errorlog | tail -20
```

**Logs esperados:**
```
[queue] Launched worker for job: abc123 (curl errno: 28)
[worker] Starting job: abc123
[worker] Using fastcgi_finish_request() - optimal for production
[worker] Job abc123 marked as running
=== Claude API Call Started ===
Response in 163.2s — HTTP 200 — 45,230 bytes
[worker] Job abc123 completed successfully in 163.2s
```

## 🐛 Troubleshooting en Producción

### Jobs quedan en pending

**Diagnóstico:**
```bash
# Contar jobs pendientes
grep -l '"status": "pending"' jobs/*.json | wc -l

# Ver edad del job más antiguo
ls -lt jobs/*.json | tail -1
```

**Solución:**
```bash
# Procesar manualmente
php process_all_pending.php
```

**Causa probable:**
- Worker no se lanzó automáticamente
- Curl interno falló
- WORKER_SECRET incorrecto

**Fix permanente:**
Verificar `.htaccess` no bloquea `worker.php` para requests locales.

### Error 504 (todavía)

**Causa:** El sistema de colas NO se activó

**Diagnóstico:**
1. Verificar `index.php` tiene la función `launch_worker()`
2. Verificar `assets/app.js` hace polling
3. Verificar logs para ver errores

**Solución:**
Re-subir `index.php` y `assets/app.js` completos.

### Worker responde 403

**Causa:** `.htaccess` bloqueando worker

**Verificar:**
```bash
curl -I https://app.libellus.cl/worker.php?token=WORKER_SECRET&job_id=test
# Debe dar: HTTP/2 403 (es correcto - acceso desde fuera)

# Desde SSH (interno):
curl -I http://localhost/worker.php?token=WORKER_SECRET&job_id=test
# Debe dar: HTTP/1.1 202 Accepted
```

**Solución:** 
El `.htaccess` debe permitir acceso local pero denegar externo. Ya está configurado correctamente.

## ✅ Post-Deployment

### 1. Limpiar archivos temporales

```bash
rm verify_production.php
```

### 2. Configurar monitoreo (opcional)

Crear cron job para limpiar jobs antiguos:
```bash
# cPanel → Cron Jobs → Agregar:
0 3 * * * php /home/customer/www/app.libellus.cl/public_html/process_all_pending.php > /dev/null 2>&1
```

Esto ejecuta el procesador cada madrugada a las 3 AM (por si algún job quedó pendiente).

### 3. Monitorear primeras 24h

- Ver logs cada hora
- Verificar jobs no se acumulen en pending
- Verificar generation_log.json crece normalmente

## 📊 Métricas de Éxito

| Métrica | Antes | Después (esperado) |
|---------|-------|---------------------|
| Tiempo respuesta | 2-5 min | <2s |
| Usuarios simultáneos | 1-2 | 5-10+ |
| Errores 504 | Frecuentes | 0 |
| Jobs completados | ~80% | ~100% |

## 🔄 Rollback (si falla)

Si algo sale mal y necesitas volver al sistema anterior:

### Opción 1: Rollback rápido (sin colas)

Reemplazar `index.php` con versión que llama directamente a `call_claude()`:

```php
// En index.php, reemplazar el bloque action=generate con:
if ($action === 'generate') {
    $skill_id     = trim($_POST['skill']        ?? '');
    $pdf_ids_raw  = trim($_POST['pdf_file_ids'] ?? '');
    $pdf_file_ids = json_decode($pdf_ids_raw, true) ?: [];
    
    require_once __DIR__ . '/claude.php';
    
    $result = call_claude(
        $skill_id,
        $pdf_file_ids,
        $_SESSION['authorized_email'] ?? ''
    );
    
    echo json_encode($result);
    exit;
}
```

### Opción 2: Rollback desde backup

Restaurar archivos desde backup pre-deployment.

## 📞 Soporte

Si después de seguir esta guía tienes problemas:

1. Capturar logs: `tail -100 php_errorlog > debug.log`
2. Capturar estado jobs: `ls -la jobs/ > jobs_status.txt`
3. Capturar verificación: Guardar output de `verify_production.php`
4. Describir problema específico y enviar los 3 archivos

---

**Versión:** 1.0.0  
**Fecha:** Junio 2026  
**Última actualización:** 2026-06-02

# ✅ Checklist de Testing - Sistema de Cola Asíncrona

## Pre-requisitos

- [ ] Servidor web corriendo (Apache/Nginx)
- [ ] PHP-FPM activo
- [ ] Node.js instalado y accesible
- [ ] `config.php` tiene `WORKER_SECRET` definido
- [ ] Directorio `jobs/` existe con permisos 755
- [ ] Directorio `downloads/` existe con permisos 755

## 1. Verificación de Archivos

### Archivos nuevos creados:
- [ ] `job_queue.php` existe
- [ ] `worker.php` existe
- [ ] `job_status.php` existe
- [ ] `jobs/.htaccess` existe
- [ ] `QUEUE_SYSTEM_README.md` existe

### Archivos modificados:
- [ ] `index.php` tiene función `launch_worker()`
- [ ] `assets/app.js` tiene función `startPolling()`
- [ ] `claude.php` tiene función `claude_generate_demand()`
- [ ] `.htaccess` bloquea `job_queue.php` y `worker.php`
- [ ] `.gitignore` ignora `/jobs/*.json`

## 2. Test de Seguridad

### Acceso directo bloqueado:
- [ ] `https://app.libellus.cl/job_queue.php` → 403 Forbidden
- [ ] `https://app.libellus.cl/worker.php` → 403 Forbidden
- [ ] `https://app.libellus.cl/jobs/` → 403 Forbidden

### Solo con token:
- [ ] `worker.php?token=WRONG` → 403 Forbidden
- [ ] `worker.php?token=CORRECTO&job_id=test` → 202 Accepted (pero falla porque job no existe)

## 3. Test Funcional - Usuario Único

### Generación normal:
1. [ ] Login con usuario válido
2. [ ] Seleccionar skill
3. [ ] Subir 2 PDFs
4. [ ] Click "Generar Demanda"
5. [ ] Respuesta inmediata (<2s)
6. [ ] Aparece mensaje "Solicitud enviada"
7. [ ] Polling inicia automáticamente
8. [ ] Mensaje cambia a "Generando documento..."
9. [ ] Tiempo transcurrido se actualiza cada 4s
10. [ ] Después de 2-5 min aparece botón "Descargar"
11. [ ] Stats aparecen (tokens + tiempo + modelo si ADMIN)
12. [ ] Descarga funciona correctamente

### Logs generados:
- [ ] `php_errorlog` tiene `[worker] Starting job: <job_id>`
- [ ] `php_errorlog` tiene `[worker] Job <job_id> completed successfully`
- [ ] `downloads/generation_log.json` tiene nueva entrada
- [ ] `jobs/<job_id>.json` existe con status: done

## 4. Test de Recuperación (localStorage)

### Reload durante generación:
1. [ ] Iniciar generación (esperar 30s hasta que entre en "running")
2. [ ] Recargar página (F5)
3. [ ] **Verificar**: Polling se retoma automáticamente
4. [ ] **Verificar**: Mensaje muestra "Generando documento..."
5. [ ] **Verificar**: Tiempo transcurrido continúa desde donde iba
6. [ ] **Verificar**: Al completar, muestra descarga normalmente

### Cerrar y reabrir navegador:
1. [ ] Iniciar generación
2. [ ] Cerrar pestaña/navegador
3. [ ] Reabrir `https://app.libellus.cl`
4. [ ] **Verificar**: Polling se retoma (porque localStorage persiste)

### Después de completar:
1. [ ] Job completa exitosamente
2. [ ] `localStorage` se limpia automáticamente
3. [ ] Recargar página → NO retoma polling
4. [ ] Botón vuelve a "Generar Demanda"

## 5. Test Multi-Usuario (Crítico!)

### 3-5 usuarios simultáneos:
1. [ ] Abrir 5 pestañas en modo incógnito (usuarios diferentes)
2. [ ] Login en cada una con correos distintos
3. [ ] Iniciar generación en TODAS simultáneamente (dentro de 30s)
4. [ ] **Verificar**: Todas responden inmediatamente
5. [ ] **Verificar**: NINGUNA da error 504
6. [ ] **Verificar**: Todas muestran polling activo
7. [ ] **Verificar**: Todas completan entre 2-5 min
8. [ ] **Verificar**: `generation_log.json` tiene 5 entradas nuevas

### Verificar en logs:
```bash
tail -f php_errorlog
```
Buscar:
- [ ] `[queue] Launched worker for job: <job_id>` (5 veces)
- [ ] `[worker] Starting job: <job_id>` (5 veces)
- [ ] `[worker] Job <job_id> completed successfully` (5 veces)
- [ ] NO debe haber errores 504
- [ ] NO debe haber `apache_setenv()` errors

## 6. Test de Errores

### Job no encontrado:
- [ ] `job_status.php?id=FAKE_ID` → `{"status":"not_found"}`

### Sin autenticación:
- [ ] Logout
- [ ] `job_status.php?id=REAL_ID` → 401 Unauthorized

### Claude API falla:
1. [ ] Temporalmente romper `ANTHROPIC_API_KEY` en `config.php`
2. [ ] Intentar generar
3. [ ] **Verificar**: Job termina con status: error
4. [ ] **Verificar**: Frontend muestra mensaje de error
5. [ ] **Verificar**: localStorage se limpia
6. [ ] **Verificar**: Permite reintentar
7. [ ] Restaurar API key correcta

## 7. Test de Performance

### Tiempo de respuesta:
- [ ] Request a `action=generate` completa en <1s
- [ ] Polling no aumenta carga del servidor significativamente
- [ ] Worker completa en tiempo similar al sistema anterior (2-5 min)

### Recursos del servidor:
```bash
# Durante generaciones simultáneas:
top -b -n 1 | grep php
```
- [ ] CPU uso razonable (<80% por proceso)
- [ ] Memoria no crece indefinidamente
- [ ] No hay procesos zombie

## 8. Test Admin Panel

### Historial de generaciones:
1. [ ] Login como ADMIN
2. [ ] Ir a `admin.php`
3. [ ] **Verificar**: Tabla muestra generaciones recientes
4. [ ] **Verificar**: Columnas: filename, email, fecha, tokens (in/out), tiempo, modelo
5. [ ] **Verificar**: Ordenadas por más reciente primero
6. [ ] **Verificar**: Timestamps en zona horaria Santiago

## 9. Limpieza y Mantenimiento

### Cleanup automático:
1. [ ] Crear job de prueba
2. [ ] Simular 24h+ cambiando timestamp en `jobs/<job_id>.json`
3. [ ] Ejecutar nueva generación (trigger cleanup con 10% probabilidad)
4. [ ] **Verificar**: Job antiguo eventualmente se elimina

### Manual cleanup:
```php
<?php
require_once 'job_queue.php';
job_cleanup();
```
- [ ] Jobs >24h eliminados
- [ ] Jobs recientes preservados

## 10. Rollback Plan (si algo falla)

### Si el sistema de colas no funciona:
1. [ ] Restaurar `index.php` a versión anterior (sin colas)
2. [ ] Restaurar `assets/app.js` a versión anterior
3. [ ] Sistema vuelve a funcionamiento bloqueante pero funcional

### Archivos a respaldar ANTES de desplegar:
- `index.php.backup`
- `assets/app.js.backup`
- `claude.php.backup`

## ✅ Resultado Final

### ¿Sistema funcionando correctamente?
- [ ] ✅ SÍ - Todos los tests pasaron
- [ ] ⚠️ PARCIAL - Algunos tests fallaron (documentar cuáles)
- [ ] ❌ NO - Rollback necesario

### Notas adicionales:
```
[Espacio para documentar problemas encontrados]




```

---

**Fecha de testing**: _______________
**Testeado por**: _______________
**Versión**: 1.0.0

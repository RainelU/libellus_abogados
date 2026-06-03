(function () {
    'use strict';

    // ── Referencias DOM ───────────────────────────────────────────────────────
    const skillSelect     = document.getElementById('skill-select');
    const generateBtn     = document.getElementById('generate-btn');
    const spinner         = document.getElementById('spinner');
    const btnLabel        = document.getElementById('btn-label');
    const outputSection   = document.getElementById('output-section');
    const outputText      = document.getElementById('output-text');
    const errorMsg        = document.getElementById('error-msg');
    const attachBtn       = document.getElementById('attach-btn');
    const fileInput       = document.getElementById('file-input');
    const attachedFilesEl = document.getElementById('attached-files');
    const uploadStatus    = document.getElementById('upload-status');

    let attachedFiles = [];
    let fileIdCounter = 0;
    let pollingInterval = null;

    // ── Utilidades ────────────────────────────────────────────────────────────

    function showError(msg) {
        errorMsg.textContent = msg;
        errorMsg.classList.remove('d-none');
        errorMsg.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    function hideError() {
        errorMsg.classList.add('d-none');
    }

    function setUploadStatus(msg) {
        if (!uploadStatus) return;
        if (msg) {
            uploadStatus.textContent = msg;
            uploadStatus.classList.remove('d-none');
        } else {
            uploadStatus.classList.add('d-none');
        }
    }

    function setLoading(on, success = false) {
        generateBtn.disabled = on;
        spinner.classList.toggle('d-none', !on);

        if (on) {
            btnLabel.textContent = 'Generando documento...';
            const statsEl = document.getElementById('usage-stats');
            if (statsEl) statsEl.classList.add('d-none');
            showProgressMessage('Enviando solicitud...');
            outputSection.classList.remove('d-none');
        } else {
            btnLabel.textContent = success ? 'Documento generado' : 'Generar Demanda';
        }
    }

    function showProgressMessage(msg, subMsg) {
        outputText.innerHTML = `
            <div class="p-4 text-center">
                <div class="spinner-border mb-3" role="status" style="color:var(--lib-navy,#1a2f52)">
                    <span class="visually-hidden">Cargando...</span>
                </div>
                <h6 class="mb-2">${escapeHtml(msg)}</h6>
                <p class="text-secondary small mb-0">
                    ${subMsg ? escapeHtml(subMsg) : 'Claude está analizando los documentos y redactando la demanda.<br>Esto puede tomar entre 2 y 5 minutos. No cierres esta ventana.'}
                </p>
            </div>`;
    }

    function checkReady() {
        const readyPdfs = attachedFiles.filter(f => f.type === 'pdf' && f.file_id);
        generateBtn.disabled = !skillSelect.value || readyPdfs.length === 0;
    }

    function stopPolling() {
        if (pollingInterval) {
            clearInterval(pollingInterval);
            pollingInterval = null;
        }
    }

    // ── localStorage: persistir job_id entre recargas ────────────────────────

    function saveCurrentJob(jobId) {
        if (jobId) {
            localStorage.setItem('libellus_current_job', jobId);
        }
    }

    function getCurrentJob() {
        return localStorage.getItem('libellus_current_job');
    }

    function clearCurrentJob() {
        localStorage.removeItem('libellus_current_job');
    }

    // ── Cargar skills ─────────────────────────────────────────────────────────

    async function loadSkills() {
        try {
            const body = new FormData();
            body.append('action', 'get_skills');
            const res  = await fetch('index.php', { method: 'POST', body });
            const data = await res.json();

            skillSelect.innerHTML = '';

            if (!data.skills || data.skills.length === 0) {
                skillSelect.innerHTML = '<option value="">No hay skills disponibles</option>';
                return;
            }

            skillSelect.appendChild(new Option('Seleccioná un skill...', ''));
            data.skills.forEach(({ value, label }) => {
                skillSelect.appendChild(new Option(label, value));
            });

            skillSelect.disabled = false;
            skillSelect.addEventListener('change', checkReady);
        } catch {
            skillSelect.innerHTML = '<option value="">Error al cargar skills</option>';
        }
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // ── Adjuntar archivos ─────────────────────────────────────────────────────

    attachBtn.addEventListener('click', () => fileInput.click());

    fileInput.addEventListener('change', async () => {
        const files = [...fileInput.files];
        if (!files.length) return;

        attachBtn.disabled = true;
        hideError();

        for (const file of files) {
            const ext = file.name.split('.').pop().toLowerCase();

            if (ext !== 'pdf') {
                showError(`Solo se aceptan archivos PDF. "${file.name}" fue ignorado.`);
                continue;
            }

            const id = ++fileIdCounter;
            addFileBadge(id, file.name, true);
            setUploadStatus(`Subiendo "${file.name}" a Anthropic Files API...`);

            try {
                const formData = new FormData();
                formData.append('file', file);

                const res  = await fetch('upload.php', { method: 'POST', body: formData });
                const data = await res.json();

                if (!data.success) {
                    showError(data.error || `Error al subir "${file.name}".`);
                    removeBadge(id);
                    continue;
                }

                attachedFiles.push({ id, name: data.name, type: 'pdf', file_id: data.file_id });
                finalizeBadge(id, data.name);

            } catch (err) {
                showError(`Error al subir "${file.name}": ${err.message}`);
                removeBadge(id);
            }
        }

        setUploadStatus(null);
        attachBtn.disabled = false;
        fileInput.value = '';
        checkReady();
    });

    function addFileBadge(id, name, uploading = false) {
        const badge = document.createElement('span');
        badge.className = 'file-badge' + (uploading ? ' file-badge--uploading' : '');
        badge.dataset.id = id;
        badge.innerHTML = uploading
            ? `<span class="spinner-border spinner-border-sm me-1" style="width:.75rem;height:.75rem"></span><span>${escapeHtml(name)}</span>`
            : `<span>${escapeHtml(name)}</span><button type="button" class="file-badge-remove" aria-label="Quitar archivo">&times;</button>`;

        if (!uploading) {
            badge.querySelector('.file-badge-remove').addEventListener('click', () => {
                attachedFiles = attachedFiles.filter(f => f.id !== id);
                badge.remove();
                checkReady();
            });
        }

        attachedFilesEl.appendChild(badge);
    }

    function finalizeBadge(id, name) {
        const badge = attachedFilesEl.querySelector(`[data-id="${id}"]`);
        if (!badge) return;
        badge.classList.remove('file-badge--uploading');
        badge.innerHTML = `<span>${escapeHtml(name)}</span><button type="button" class="file-badge-remove" aria-label="Quitar archivo">&times;</button>`;
        badge.querySelector('.file-badge-remove').addEventListener('click', () => {
            attachedFiles = attachedFiles.filter(f => f.id !== id);
            badge.remove();
            checkReady();
        });
    }

    function removeBadge(id) {
        const badge = attachedFilesEl.querySelector(`[data-id="${id}"]`);
        if (badge) badge.remove();
    }

    // ── Generar demanda ───────────────────────────────────────────────────────

    generateBtn.addEventListener('click', async () => {
        hideError();
        stopPolling();

        const skill = skillSelect.value;
        const pdfs  = attachedFiles.filter(f => f.type === 'pdf' && f.file_id);

        if (!skill) { showError('Seleccioná un skill antes de generar.'); return; }
        if (!pdfs.length) { showError('Adjuntá al menos un PDF antes de generar.'); return; }

        setLoading(true);

        const body = new FormData();
        body.append('action', 'generate');
        body.append('skill', skill);
        body.append('pdf_file_ids', JSON.stringify(pdfs.map(f => f.file_id)));

        try {
            const res = await fetch('index.php', { method: 'POST', body });
            if (!res.ok) throw new Error('HTTP ' + res.status);

            const data = await res.json();

            if (!data.success) {
                showError(data.error || 'Error desconocido al encolar la solicitud.');
                setLoading(false, false);
                outputSection.classList.add('d-none');
                return;
            }

            // Job encolado — guardar en localStorage y empezar polling
            saveCurrentJob(data.job_id);
            startPolling(data.job_id);

        } catch (err) {
            showError('Error de conexión: ' + err.message);
            setLoading(false, false);
            outputSection.classList.add('d-none');
        }
    });

    // ── Polling de estado del job ─────────────────────────────────────────────

    function startPolling(jobId) {
        let pollCount = 0;
        const MAX_POLLS = 450; // 450 × 4s = 30 minutos máximo

        showProgressMessage(
            'Solicitud recibida',
            'Tu documento entrará en procesamiento en menos de un minuto.'
        );

        pollingInterval = setInterval(async () => {
            pollCount++;

            if (pollCount > MAX_POLLS) {
                stopPolling();
                clearCurrentJob();
                showError('La generación está tardando más de lo esperado. Por favor intentá de nuevo.');
                setLoading(false, false);
                return;
            }

            try {
                const res  = await fetch(`job_status.php?id=${encodeURIComponent(jobId)}`);
                const data = await res.json();

                switch (data.status) {
                    case 'pending': {
                        const waitSecs = pollCount * 4;
                        const waitStr  = waitSecs >= 60
                            ? `${Math.floor(waitSecs/60)}m ${waitSecs%60}s`
                            : `${waitSecs}s`;
                        showProgressMessage(
                            'En cola...',
                            `Tu solicitud está en espera. El sistema la procesará automáticamente cuando sea tu turno. Tiempo de espera: ${waitStr}`
                        );
                        break;
                    }

                    case 'running': {
                        const secs    = data.elapsed || 0;
                        const timeStr = secs >= 60
                            ? `${Math.floor(secs / 60)}m ${Math.round(secs % 60)}s`
                            : `${secs}s`;
                        showProgressMessage(
                            'Generando documento...',
                            `Claude está trabajando en tu demanda. Tiempo transcurrido: ${timeStr}`
                        );
                        break;
                    }

                    case 'done':
                        stopPolling();
                        clearCurrentJob();
                        handleJobDone(data.result);
                        break;

                    case 'error': {
                        stopPolling();
                        clearCurrentJob();
                        // Mostrar error con botón de reintento
                        const errMsg = data.error || 'Error al generar el documento.';
                        const isConnection = errMsg.includes('Connection') || errMsg.includes('connection') 
                                          || errMsg.includes('reset') || errMsg.includes('timeout');
                        const hint = isConnection
                            ? ' El servidor tuvo un problema de conexión. Podés intentar nuevamente.'
                            : '';
                        showError(errMsg + hint);
                        setLoading(false, false);
                        outputSection.classList.add('d-none');
                        // Re-habilitar el botón para que puedan reintentar
                        generateBtn.disabled = false;
                        btnLabel.textContent = 'Generar Demanda';
                        break;
                    }

                    case 'not_found':
                        stopPolling();
                        clearCurrentJob();
                        showError('No se encontró la solicitud. Por favor generá nuevamente.');
                        setLoading(false, false);
                        outputSection.classList.add('d-none');
                        break;
                }

            } catch (err) {
                console.warn('Polling error (will retry):', err.message);
            }

        }, 4000); // cada 4 segundos
    }

    function handleJobDone(result) {
        if (result && result.type === 'files' && result.files) {
            displayDownloadLinks(result.files);
            showUsageStats(result.usage, result.elapsed, result.model);
            setLoading(false, true);
        } else {
            showError('El documento fue generado pero no se pudo obtener el enlace de descarga.');
            setLoading(false, false);
        }
    }

    // ── Mostrar stats de uso ──────────────────────────────────────────────────

    function showUsageStats(usage, elapsed, model) {
        const statsEl = document.getElementById('usage-stats');
        if (!statsEl) return;

        const inputEl   = document.getElementById('stat-input-tokens');
        const outputEl  = document.getElementById('stat-output-tokens');
        const elapsedEl = document.getElementById('stat-elapsed');
        const modelEl   = document.getElementById('stat-model');

        if (usage) {
            if (inputEl)  inputEl.textContent  = (usage.input_tokens  || 0).toLocaleString('es');
            if (outputEl) outputEl.textContent = (usage.output_tokens || 0).toLocaleString('es');
        }

        if (elapsedEl && elapsed != null) {
            const secs = parseFloat(elapsed);
            elapsedEl.textContent = secs >= 60
                ? `${Math.floor(secs / 60)}m ${Math.round(secs % 60)}s`
                : `${secs}s`;
        }

        if (modelEl && model) modelEl.textContent = model;

        statsEl.classList.remove('d-none');
        statsEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    // ── Mostrar links de descarga ─────────────────────────────────────────────

    function displayDownloadLinks(files) {
        outputText.innerHTML = '';

        const container = document.createElement('div');
        container.className = 'p-4';

        const header = document.createElement('div');
        header.className = 'd-flex align-items-center gap-2 mb-3';
        header.innerHTML = `
            <i class="bi bi-check-circle-fill" style="color:#28a745;font-size:1.5rem"></i>
            <h5 class="mb-0">Documento generado exitosamente</h5>`;
        container.appendChild(header);

        files.forEach(file => {
            const card = document.createElement('div');
            card.className = 'card mb-2';
            card.style.cssText = 'border:1px solid var(--border);border-radius:8px';

            const body = document.createElement('div');
            body.className = 'card-body d-flex align-items-center justify-content-between p-3';
            body.innerHTML = `
                <div class="d-flex align-items-center gap-3">
                    <i class="bi bi-file-earmark-word-fill" style="font-size:2rem;color:#2b579a"></i>
                    <div>
                        <div class="fw-semibold">${escapeHtml(file.filename)}</div>
                        <div class="small text-secondary">Documento Word (.docx)</div>
                    </div>
                </div>
                <a href="${escapeHtml(file.url)}" class="btn btn-primary btn-sm"
                   download="${escapeHtml(file.filename)}">
                    <i class="bi bi-download me-1"></i> Descargar
                </a>`;

            card.appendChild(body);
            container.appendChild(card);
        });

        outputText.appendChild(container);
        outputSection.classList.remove('d-none');
        outputSection.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    // ── Recuperar job en progreso (al cargar o recargar) ─────────────────────

    function recoverPendingJob() {
        const jobId = getCurrentJob();
        if (!jobId) return;

        fetch(`job_status.php?id=${encodeURIComponent(jobId)}`)
            .then(res => res.json())
            .then(data => {
                if (data.status === 'pending' || data.status === 'running') {
                    setLoading(true);
                    startPolling(jobId);
                } else if (data.status === 'done') {
                    handleJobDone(data.result);
                    clearCurrentJob();
                } else {
                    clearCurrentJob();
                }
            })
            .catch(() => clearCurrentJob());
    }

    // ── Init ──────────────────────────────────────────────────────────────────
    loadSkills();
    checkReady();
    recoverPendingJob(); // Recuperar job en progreso al cargar

})();

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

    /**
     * Estado de archivos adjuntos.
     * Cada entrada: { id: number, name: string, type: 'pdf'|'text', file_id?: string, text?: string }
     * Para PDFs: file_id es el ID devuelto por la Files API de Anthropic (via upload.php).
     */
    let attachedFiles = [];
    let fileIdCounter = 0;

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
            outputText.innerHTML = `
                <div class="p-4 text-center">
                    <div class="spinner-border text-primary mb-3" role="status">
                        <span class="visually-hidden">Cargando...</span>
                    </div>
                    <h6 class="mb-2">Generando documento</h6>
                    <p class="text-secondary small mb-0">
                        Claude está analizando los documentos y redactando la demanda.<br>
                        Esto puede tomar entre 2 y 5 minutos. No cierres esta ventana.
                    </p>
                </div>`;
            outputSection.classList.remove('d-none');
        } else {
            btnLabel.textContent = success ? 'Documento generado' : 'Generar Demanda';
        }
    }

    function checkReady() {
        // Habilitar el botón solo si hay skill seleccionado y al menos un PDF listo
        const readyPdfs = attachedFiles.filter(f => f.type === 'pdf' && f.file_id);
        generateBtn.disabled = !skillSelect.value || readyPdfs.length === 0;
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

            // Badge provisional mientras sube
            const id = ++fileIdCounter;
            addFileBadge(id, file.name, true /* uploading */);
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

                // Actualizar estado con el file_id real
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

        const skill = skillSelect.value;
        const pdfs  = attachedFiles.filter(f => f.type === 'pdf' && f.file_id);

        if (!skill) {
            showError('Seleccioná un skill antes de generar.');
            return;
        }
        if (!pdfs.length) {
            showError('Adjuntá al menos un PDF antes de generar.');
            return;
        }

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
                showError(data.error || 'Error desconocido al generar el documento.');
                setLoading(false, false);
                outputSection.classList.add('d-none');
                return;
            }

            if (data.type === 'files') {
                displayDownloadLinks(data.files);
                setLoading(false, true);
            } else {
                // Respuesta de texto inesperada
                showError('Se esperaba un archivo .docx pero el servidor devolvió texto. Revisa la configuración.');
                setLoading(false, false);
                outputSection.classList.add('d-none');
            }

        } catch (err) {
            let msg = 'Error de conexión: ' + err.message;
            if (/timeout|timed out/i.test(err.message)) {
                msg = '⏱️ Timeout: La generación está tomando más tiempo del esperado. Intenta nuevamente.';
            } else if (/Failed to fetch/i.test(err.message)) {
                msg = '🌐 Sin conexión: No se pudo conectar con el servidor.';
            } else if (/reset|Recv failure/i.test(err.message)) {
                msg = '🔌 Conexión interrumpida durante el proceso. Intenta nuevamente.';
            }
            showError(msg);
            setLoading(false, false);
            outputSection.classList.add('d-none');
        }
    });

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
                <a href="${escapeHtml(file.url)}" class="btn btn-primary btn-sm" download="${escapeHtml(file.filename)}">
                    <i class="bi bi-download me-1"></i> Descargar
                </a>`;

            card.appendChild(body);
            container.appendChild(card);
        });

        outputText.appendChild(container);
        outputSection.classList.remove('d-none');
        outputSection.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    // ── Init ──────────────────────────────────────────────────────────────────
    loadSkills();
    checkReady();

})();

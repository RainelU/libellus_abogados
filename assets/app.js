(function () {
    const skillSelect    = document.getElementById('skill-select');
    const generateBtn    = document.getElementById('generate-btn');
    const spinner        = document.getElementById('spinner');
    const btnLabel       = document.getElementById('btn-label');
    const outputSection  = document.getElementById('output-section');
    const outputText     = document.getElementById('output-text');
    const errorMsg       = document.getElementById('error-msg');
    const attachBtn      = document.getElementById('attach-btn');
    const fileInput      = document.getElementById('file-input');
    const attachedFilesEl = document.getElementById('attached-files');

    // [{id, name, type:'pdf'|'text', data|text}]
    let attachedFiles_state = [];
    let fileIdCounter = 0;

    // ── Utilidades ────────────────────────────────────────────────────────────

    function showError(msg) {
        errorMsg.textContent = msg;
        errorMsg.classList.remove('d-none');
    }

    function hideError() {
        errorMsg.classList.add('d-none');
    }

    function setLoading(on, success = false) {
        generateBtn.disabled = on;
        spinner.classList.toggle('d-none', !on);
        if (on) {
            btnLabel.textContent = 'Generando documento...';
            // Mostrar mensaje de espera
            if (outputSection.classList.contains('d-none')) {
                outputText.innerHTML = `
                    <div class="p-4 text-center">
                        <div class="spinner-border text-primary mb-3" role="status">
                            <span class="visually-hidden">Cargando...</span>
                        </div>
                        <h6 class="mb-2">Generando documento</h6>
                        <p class="text-secondary small mb-0">
                            Claude está procesando tu solicitud. Esto puede tomar entre 5-10 minutos.<br>
                            Por favor, no cierres esta ventana.
                        </p>
                    </div>
                `;
                outputSection.classList.remove('d-none');
            }
        } else {
            btnLabel.textContent = success ? 'Documento generado' : 'Generar Demanda';
        }
    }

    async function post(endpoint, data) {
        const body = new FormData();
        for (const [k, v] of Object.entries(data)) {
            body.append(k, v);
        }
        
        const res = await fetch(endpoint, { 
            method: 'POST', 
            body
        });
        
        if (!res.ok) {
            throw new Error('HTTP ' + res.status);
        }
        
        return res.json();
    }

    // ── Skills ────────────────────────────────────────────────────────────────

    async function loadSkills() {
        try {
            const data = await post('index.php', { action: 'get_skills' });
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
            checkReady();
        } catch {
            skillSelect.innerHTML = '<option value="">Error al cargar skills</option>';
        }
    }

    function checkReady() {
        generateBtn.disabled = !skillSelect.value || attachedFiles_state.length === 0;
    }

    // ── Adjuntar archivo ──────────────────────────────────────────────────────

    attachBtn.addEventListener('click', () => fileInput.click());

    fileInput.addEventListener('change', async () => {
        const files = [...fileInput.files];
        if (!files.length) return;

        attachBtn.disabled = true;
        attachBtn.title = 'Procesando...';

        for (const file of files) {
            try {
                const formData = new FormData();
                formData.append('file', file);
                const res  = await fetch('upload.php', { method: 'POST', body: formData });
                const data = await res.json();

                if (!data.success) { showError(data.error || 'Error al procesar ' + file.name); continue; }

                const id = ++fileIdCounter;
                if (data.type === 'pdf') {
                    attachedFiles_state.push({ id, name: data.name, type: 'pdf', data: data.data });
                } else {
                    attachedFiles_state.push({ id, name: data.name, type: 'text', text: data.text });
                }
                addFileBadge(id, data.name);
            } catch (err) {
                showError('Error al subir ' + file.name + ': ' + err.message);
            }
        }

        attachBtn.disabled = false;
        attachBtn.title = 'Adjuntar archivos';
        fileInput.value = '';
        checkReady();
    });

    function addFileBadge(id, name) {
        const badge = document.createElement('span');
        badge.className = 'file-badge';
        badge.dataset.id = id;
        badge.innerHTML = `<span>${name}</span><button type="button" class="file-badge-remove" aria-label="Quitar archivo">&times;</button>`;
        badge.querySelector('.file-badge-remove').addEventListener('click', () => {
            attachedFiles_state = attachedFiles_state.filter(f => f.id !== id);
            badge.remove();
            checkReady();
        });
        attachedFilesEl.appendChild(badge);
    }

    // ── Generar ───────────────────────────────────────────────────────────────

    generateBtn.addEventListener('click', async () => {
        hideError();
        const skill = skillSelect.value;
        const texts = attachedFiles_state.filter(f => f.type === 'text');
        const pdfs  = attachedFiles_state.filter(f => f.type === 'pdf');
        const input = texts.map(f => texts.length > 1 ? `[${f.name}]\n${f.text}` : f.text).join('\n\n---\n\n');

        if (!skill) { showError('Seleccioná un skill antes de generar.'); return; }
        if (!input && !pdfs.length) { showError('Adjuntá un archivo antes de generar.'); return; }

        setLoading(true);
        outputSection.classList.add('d-none');

        const payload = { action: 'generate', skill, input };
        if (pdfs.length) payload.pdf_data = JSON.stringify(pdfs.map(f => f.data));

        try {
            const data = await post('index.php', payload);
            if (!data.success) { 
                showError(data.error || 'Error desconocido.'); 
                setLoading(false, false);
                outputSection.classList.add('d-none');
                return; 
            }
            
            // Solo manejamos archivos
            if (data.type === 'files') {
                displayDownloadLinks(data.files);
                setLoading(false, true);
            } else {
                // Si Claude responde con texto, mostrar error
                showError('Se esperaba un archivo .docx pero Claude respondió con texto. Verifica la configuración del skill.');
                setLoading(false, false);
                outputSection.classList.add('d-none');
            }
        } catch (err) {
            let errorMessage = 'Error de conexión: ' + err.message;
            
            // Mensajes más específicos según el error
            if (err.message.includes('timeout') || err.message.includes('timed out')) {
                errorMessage = '⏱️ Timeout: La generación del documento está tomando más tiempo del esperado. Por favor, intenta nuevamente. Si el problema persiste, el documento puede ser muy complejo.';
            } else if (err.message.includes('Failed to fetch')) {
                errorMessage = '🌐 Sin conexión: No se pudo conectar con el servidor. Verifica tu conexión a internet e intenta nuevamente.';
            } else if (err.message.includes('reset') || err.message.includes('Recv failure')) {
                errorMessage = '🔌 Conexión interrumpida: La conexión con el servidor se perdió durante el proceso. Esto puede ocurrir con documentos muy grandes o complejos. Por favor, intenta nuevamente.';
            } else if (err.message.includes('aborted') || err.message.includes('cancelled')) {
                errorMessage = '❌ Operación cancelada: La solicitud fue cancelada. Por favor, intenta nuevamente.';
            }
            
            showError(errorMessage);
            setLoading(false, false);
            outputSection.classList.add('d-none');
        }
    });
    
    function displayDownloadLinks(files) {
        outputText.innerHTML = '';
        
        const container = document.createElement('div');
        container.className = 'p-4';
        
        const title = document.createElement('div');
        title.className = 'd-flex align-items-center gap-2 mb-3';
        title.innerHTML = `
            <i class="bi bi-check-circle-fill" style="color: #28a745; font-size: 1.5rem;"></i>
            <h5 class="mb-0">Documento generado exitosamente</h5>
        `;
        container.appendChild(title);
        
        files.forEach(file => {
            const fileCard = document.createElement('div');
            fileCard.className = 'card mb-2';
            fileCard.style.cssText = 'border: 1px solid var(--border); border-radius: 8px;';
            
            const cardBody = document.createElement('div');
            cardBody.className = 'card-body d-flex align-items-center justify-content-between p-3';
            
            const fileInfo = document.createElement('div');
            fileInfo.innerHTML = `
                <div class="d-flex align-items-center gap-3">
                    <i class="bi bi-file-earmark-word-fill" style="font-size: 2rem; color: #2b579a;"></i>
                    <div>
                        <div class="fw-semibold">${escapeHtml(file.filename)}</div>
                        <div class="small text-secondary">Documento Word (.docx)</div>
                    </div>
                </div>
            `;
            
            const downloadBtn = document.createElement('a');
            downloadBtn.href = file.url;
            downloadBtn.className = 'btn btn-primary btn-sm';
            downloadBtn.innerHTML = '<i class="bi bi-download me-1"></i> Descargar';
            downloadBtn.download = file.filename;
            
            cardBody.appendChild(fileInfo);
            cardBody.appendChild(downloadBtn);
            fileCard.appendChild(cardBody);
            container.appendChild(fileCard);
        });
        
        outputText.appendChild(container);
        outputSection.classList.remove('d-none');
        outputSection.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
    
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // ── Init ──────────────────────────────────────────────────────────────────

    loadSkills();
})();

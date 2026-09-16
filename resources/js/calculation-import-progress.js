export function fileRowMarkup(file) {
    const error = file?.error
        ? `<span class="block text-nicon-danger">${escapeHtml(file.error)}</span>`
        : '';

    return `<li data-file-status="${escapeHtml(file?.status || '')}"><span class="font-medium">${escapeHtml(file?.name || '')}</span><span class="text-nicon-muted"> — ${escapeHtml(file?.label || '')}</span>${error}</li>`;
}

export function bindImportProgress(root, clock = globalThis) {
    if (! root) {
        return;
    }

    const url = root.getAttribute('data-status-url');
    if (! url) {
        return;
    }

    const fill = root.querySelector('[data-progress-fill]');
    const bar = root.querySelector('[data-progress-bar]');
    const status = root.querySelector('[data-progress-status]');
    const filesEl = root.querySelector('[data-progress-files]');
    let timer = null;

    const apply = (payload) => {
        if (fill) {
            fill.style.width = `${Number(payload.percent) || 0}%`;
        }
        if (bar) {
            bar.setAttribute('aria-valuenow', String(Number(payload.percent) || 0));
        }
        if (status) {
            status.textContent = payload.label || '';
        }
        if (filesEl && Array.isArray(payload.files)) {
            filesEl.innerHTML = payload.files.map(fileRowMarkup).join('');
        }
        if (payload.redirect) {
            if (timer) {
                clock.clearInterval(timer);
            }
            clock.location.assign(payload.redirect);
        }
    };

    const poll = () => {
        clock.fetch(url, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        }).then((response) => {
            if (! response.ok) {
                return null;
            }

            return response.json();
        }).then((payload) => {
            if (payload) {
                apply(payload);
            }
        }).catch(() => {});
    };

    poll();
    timer = clock.setInterval(poll, 1500);
}

function escapeHtml(value) {
    return String(value || '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;');
}

if (typeof document !== 'undefined') {
    bindImportProgress(document.querySelector('[data-calculation-import]'));
}

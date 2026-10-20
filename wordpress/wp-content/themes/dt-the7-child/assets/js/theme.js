/* Petish appearance: light/dark/system, persistent where browser storage permits. */
(function () {
    'use strict';
    const root = document.documentElement;
    const media = window.matchMedia('(prefers-color-scheme: dark)');
    let preference = root.dataset.themePreference || 'system';
    const valid = value => ['light', 'dark', 'system'].includes(value);
    function syncLogos(node) {
        const images = [];
        if (node.nodeType === 1 && node.matches('[data-petish-logo]')) images.push(node);
        if (node.querySelectorAll) images.push(...node.querySelectorAll('[data-petish-logo]'));
        const controls = [];
        if (node.nodeType === 1 && node.matches('[data-petish-theme]')) controls.push(node);
        if (node.querySelectorAll) controls.push(...node.querySelectorAll('[data-petish-theme]'));
        controls.forEach(select => { select.value = preference; });
        images.forEach(img => {
            const next = root.dataset.theme === 'dark' ? img.dataset.dark : img.dataset.light;
            if (next && img.getAttribute('src') !== next) img.setAttribute('src', next);
        });
    }
    function apply() {
        root.dataset.themePreference = preference;
        root.dataset.theme = preference === 'dark' || (preference === 'system' && media.matches) ? 'dark' : 'light';
        document.querySelectorAll('[data-petish-theme]').forEach(select => { select.value = preference; });
        syncLogos(document);
        document.querySelectorAll('[data-petish-icon]').forEach(icon => {
            icon.media = icon.dataset.mode === root.dataset.theme ? 'all' : 'not all';
        });
    }
    function ready() {
        apply();
        document.addEventListener('change', event => {
            if (!event.target.matches('[data-petish-theme]') || !valid(event.target.value)) return;
            preference = event.target.value;
            try { localStorage.setItem('petish-theme', preference); } catch (_) {}
            apply();
        });
        media.addEventListener('change', () => { if (preference === 'system') apply(); });
        window.addEventListener('storage', event => {
            if (event.key === 'petish-theme' || event.key === null) {
                preference = valid(event.newValue) ? event.newValue : 'system';
                apply();
            }
        });
        // The7 can insert a floating logo after scrolling.
        new MutationObserver(records => records.forEach(record =>
            record.addedNodes.forEach(node => syncLogos(node))
        )).observe(document.body, { childList: true, subtree: true });

        const dialog = document.getElementById('petish-preview-dialog');
        const opener = document.querySelector('[data-preview-dialog-open]');
        if (dialog && opener && typeof dialog.showModal === 'function') {
            opener.addEventListener('click', () => dialog.showModal());
            dialog.querySelector('[data-preview-dialog-close]').addEventListener('click', () => dialog.close());
        }
        const previewForm = document.querySelector('[data-preview-form]');
        if (previewForm) {
          previewForm.addEventListener('submit', event => {
            event.preventDefault();
            const status = document.getElementById('petish-preview-status');
            status.hidden = false;
            status.textContent = 'Preview complete. This demonstration does not save or send information.';
            status.focus();
          });
          previewForm.querySelector('[data-preview-fields]').disabled = false;
        }
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', ready);
    else ready();
})();

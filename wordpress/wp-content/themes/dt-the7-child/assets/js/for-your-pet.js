/* Personal results stay outside the cacheable homepage and browser storage. */
(() => {
  'use strict';

  const section = document.querySelector('[data-petish-for-your-pet]');
  if (!section || !window.fetch || !window.AbortController) return;

  const body = section.querySelector('[data-petish-for-your-pet-body]');
  const status = section.querySelector('[data-petish-for-your-pet-status]');
  const error = section.querySelector('[data-petish-for-your-pet-error]');
  const retry = section.querySelector('[data-petish-for-your-pet-retry]');
  if (!body || !status || !error || !retry) return;

  let endpoint;
  try {
    endpoint = new URL(section.dataset.endpoint, window.location.href);
    if (endpoint.origin !== window.location.origin || !/^https?:$/.test(endpoint.protocol)) return;
    endpoint.searchParams.set('action', 'petish_for_your_pet');
  } catch (_) {
    return;
  }

  const fallback = body.innerHTML;
  let active = null;

  async function load() {
    if (active) return;
    const controller = new AbortController();
    active = controller;
    const timeout = window.setTimeout(() => controller.abort(), 15000);
    body.setAttribute('aria-busy', 'true');
    error.hidden = true;
    retry.disabled = true;
    status.textContent = section.dataset.loadingLabel || '';

    try {
      const response = await fetch(endpoint.href, {
        method: 'GET',
        credentials: 'same-origin',
        cache: 'no-store',
        redirect: 'error',
        headers: { Accept: 'application/json' },
        signal: controller.signal,
      });
      if (!response.ok) throw new Error('Unavailable');
      const result = await response.json();
      if (!result || result.success !== true || !result.data || typeof result.data.html !== 'string' || !result.data.html.trim()) {
        throw new Error('Invalid response');
      }
      if (active !== controller) return;
      // Only this same-origin endpoint supplies the server-escaped HTML fragment.
      body.innerHTML = result.data.html;
      status.textContent = section.dataset.loadedLabel || '';
    } catch (_) {
      if (active !== controller) return;
      error.hidden = false;
      status.textContent = '';
    } finally {
      window.clearTimeout(timeout);
      if (active === controller) {
        active = null;
        body.removeAttribute('aria-busy');
        retry.disabled = false;
      }
    }
  }

  retry.addEventListener('click', load);
  // History restoration must not reuse picks from an earlier signed-in session.
  window.addEventListener('pagehide', () => {
    const pending = active;
    active = null;
    if (pending) pending.abort();
    body.innerHTML = fallback;
    body.removeAttribute('aria-busy');
    error.hidden = true;
    retry.disabled = false;
    status.textContent = '';
  });
  window.addEventListener('pageshow', event => {
    if (event.persisted) load();
  });
  load();
})();

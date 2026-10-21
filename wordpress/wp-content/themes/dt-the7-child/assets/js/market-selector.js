/* The7 header market chooser. All personal state comes from uncached Woo AJAX. */
(() => {
  'use strict';
  if (!window.petishMarket) return;
  const selectors = new WeakSet();
  let state;
  let pending;
  let active;
  let counter = 0;

  const flagCodes = new Set(Array.isArray(petishMarket.flagCodes) ? petishMarket.flagCodes : []);
  const flag = country => `${petishMarket.flagSprite}#flag-${typeof country === 'string' && /^[A-Z]{2}$/.test(country) && flagCodes.has(country) ? country.toLowerCase() : 'globe'}`;
  const request = async (url, options = {}) => {
    const response = await fetch(url, {credentials: 'same-origin', cache: 'no-store', ...options});
    const result = await response.json();
    if (!response.ok || !result.success) throw new Error(result.data?.message || 'Please refresh and try again.');
    return result.data;
  };
  const fill = root => {
    if (!state) return;
    const flagImage = root.querySelector('.petish-market__flag use');
    if (flagImage && typeof petishMarket.flagSprite === 'string') flagImage.setAttribute('href', flag(state.country));
    root.querySelector('.petish-market__code').textContent = state.currency;
    const trigger = root.querySelector('.petish-market__trigger');
    trigger.disabled = false;
    trigger.setAttribute('aria-label', `Location and currency: ${state.countries[state.country]}, ${state.currency}`);
    const panel = root.querySelector('.petish-market__panel') || (active?.root === root ? active.panel : null);
    if (!panel) return;
    ['country', 'currency'].forEach(name => {
      const select = panel.querySelector(`[name="${name}"]`);
      const choices = name === 'country' ? state.countries : state.currencies;
      select.replaceChildren(...Object.entries(choices).map(([code, label]) => {
        const option = document.createElement('option');
        option.value = code;
        option.textContent = name === 'country' ? label : `${code} — ${label}`;
        return option;
      }));
      select.value = state[name];
    });
  };
  const initialize = root => {
    if (selectors.has(root)) return;
    selectors.add(root);
    const trigger = root.querySelector('.petish-market__trigger');
    const panel = root.querySelector('.petish-market__panel');
    panel.id = `petish-market-panel-${++counter}`;
    trigger.setAttribute('aria-controls', panel.id);
    fill(root);
  };
  const close = (restoreFocus = false) => {
    if (!active) return;
    const {root, panel, trigger} = active;
    panel.hidden = true;
    trigger.setAttribute('aria-expanded', 'false');
    root.append(panel);
    active = null;
    if (restoreFocus) trigger.focus();
  };
  const position = () => {
    if (!active) return;
    const {panel, trigger} = active;
    const rect = trigger.getBoundingClientRect();
    const width = Math.min(360, window.innerWidth - 24);
    panel.style.width = `${width}px`;
    panel.style.left = `${Math.max(12, Math.min(rect.right - width, window.innerWidth - width - 12))}px`;
    panel.style.top = `${Math.max(12, Math.min(rect.bottom + 10, window.innerHeight - panel.offsetHeight - 12))}px`;
    panel.style.maxHeight = `${window.innerHeight - 24}px`;
  };
  const load = () => {
    if (!pending) pending = request(petishMarket.stateUrl).then(data => {
      state = data;
      document.querySelectorAll('[data-petish-market]').forEach(root => {initialize(root); fill(root);});
      return data;
    }).catch(error => {
      document.querySelectorAll('[data-petish-market]').forEach(root => {
        const trigger = root.querySelector('.petish-market__trigger');
        trigger.disabled = false;
        trigger.setAttribute('aria-label', 'Location and currency');
      });
      pending = null;
      throw error;
    });
    return pending;
  };

  document.addEventListener('click', async event => {
    const trigger = event.target.closest('.petish-market__trigger');
    if (trigger) {
      const root = trigger.closest('[data-petish-market]');
      initialize(root);
      if (active?.root === root) {close(); return;}
      close();
      const panel = root.querySelector('.petish-market__panel');
      active = {root, panel, trigger};
      document.body.append(panel); // Avoid transformed/clipped sticky-header ancestors.
      panel.hidden = false;
      trigger.setAttribute('aria-expanded', 'true');
      position();
      const status = panel.querySelector('.petish-market__status');
      status.textContent = state ? '' : 'Loading your choices…';
      try {
        await load();
        if (active?.root !== root) return;
        // The active panel is temporarily portalled outside the header root.
        ['country', 'currency'].forEach(name => {
          const select = panel.querySelector(`[name="${name}"]`);
          if (!select.options.length) {
            const choices = name === 'country' ? state.countries : state.currencies;
            select.replaceChildren(...Object.entries(choices).map(([code, label]) => new Option(name === 'country' ? label : `${code} — ${label}`, code)));
          }
          select.value = state[name];
        });
        status.textContent = '';
        panel.querySelector('[name="country"]').focus();
        position();
      } catch (error) {
        status.textContent = error.message;
        panel.querySelector('.petish-market__close').focus();
      }
      return;
    }
    if (event.target.closest('.petish-market__close')) {close(true); return;}
    if (active && !active.panel.contains(event.target)) close();
  });
  document.addEventListener('keydown', event => {
    if (active && event.key === 'Escape') {event.preventDefault(); close(true);}
  });
  document.addEventListener('focusin', event => {
    if (active && !active.panel.contains(event.target) && event.target !== active.trigger) close();
  });
  document.addEventListener('submit', async event => {
    if (!event.target.matches('.petish-market__form')) return;
    event.preventDefault();
    const form = event.target;
    const status = form.querySelector('.petish-market__status');
    const submit = form.querySelector('[type="submit"]');
    if (!state || submit.disabled) return;
    submit.disabled = true;
    status.textContent = 'Updating your choices…';
    const data = new FormData(form);
    data.set('nonce', state.nonce);
    try {
      await request(petishMarket.saveUrl, {method: 'POST', body: data});
      const url = new URL(window.location.href);
      url.searchParams.delete('min_price');
      url.searchParams.delete('max_price');
      // Reassigning the same fragment URL only jumps to the anchor. Reload so
      // prices and the header use the newly persisted market on that page too.
      if (url.href === window.location.href) window.location.reload();
      else window.location.assign(url.href);
    } catch (error) {
      status.textContent = error.message;
      submit.disabled = false;
    }
  });
  window.addEventListener('resize', position, {passive: true});
  window.addEventListener('scroll', () => close(), {passive: true});
  document.querySelectorAll('[data-petish-market]').forEach(initialize);
  new MutationObserver(records => {
    records.forEach(record => record.addedNodes.forEach(node => {
      if (node.nodeType !== 1) return;
      if (node.matches('[data-petish-market]')) initialize(node);
      node.querySelectorAll('[data-petish-market]').forEach(initialize);
    }));
  }).observe(document.body, {childList: true, subtree: true});
  load().catch(() => {});
})();

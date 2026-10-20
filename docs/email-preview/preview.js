(() => {
  'use strict';
  const emails = {
    processing: { file: 'processing.html?v=3', label: 'Processing order' },
    completed: { file: 'completed.html?v=3', label: 'Completed order' },
    refunded: { file: 'refunded.html?v=3', label: 'Refund confirmation' },
    note: { file: 'note.html?v=3', label: 'Order update' },
    'new-account': { file: 'new-account.html?v=3', label: 'New account' },
    'verify-email': { file: 'verify-email.html?v=3', label: 'Email verification', description: 'Confirms email ownership before linking previous guest orders.' },
    'reset-password': { file: 'reset-password.html?v=3', label: 'Password reset' },
  };
  const frame = document.querySelector('#email-frame');
  const title = document.querySelector('#email-title');
  const description = document.querySelector('#email-description');
  const panel = document.querySelector('#email-panel');
  const status = document.querySelector('#preview-status');
  const directLink = document.querySelector('#open-email');
  const surface = document.querySelector('.preview-surface');
  const tabs = [...document.querySelectorAll('[data-email]')];
  const widths = [...document.querySelectorAll('[data-width]')];
  let selected = 'processing';
  let display = 'desktop';

  function announce() {
    status.textContent = `${emails[selected].label} email, ${display} width.`;
  }

  function fitFrame() {
    try {
      const document = frame.contentDocument;
      if (!document?.body) return;
      frame.style.height = '1px';
      frame.style.height = `${Math.max(640, document.documentElement.scrollHeight, document.body.scrollHeight)}px`;
    } catch {
      frame.style.height = '980px';
    }
  }

  function selectEmail(key, focus = false) {
    if (!Object.hasOwn(emails, key)) return;
    selected = key;
    const email = emails[key];
    for (const tab of tabs) {
      const active = tab.dataset.email === key;
      tab.classList.toggle('is-active', active);
      tab.setAttribute('aria-selected', String(active));
      tab.tabIndex = active ? 0 : -1;
      if (active && focus) tab.focus();
    }
    panel.setAttribute('aria-labelledby', `tab-${key}`);
    title.textContent = email.label;
    description.textContent = email.description || '';
    description.hidden = !email.description;
    frame.title = `Petish ${email.label.toLowerCase()} email preview`;
    frame.src = email.file;
    directLink.href = email.file;
    announce();
  }

  for (const tab of tabs) {
    tab.addEventListener('click', () => selectEmail(tab.dataset.email));
    tab.addEventListener('keydown', event => {
      const current = tabs.indexOf(tab);
      let next;
      if (event.key === 'ArrowRight') next = (current + 1) % tabs.length;
      if (event.key === 'ArrowLeft') next = (current + tabs.length - 1) % tabs.length;
      if (event.key === 'Home') next = 0;
      if (event.key === 'End') next = tabs.length - 1;
      if (next === undefined) return;
      event.preventDefault();
      selectEmail(tabs[next].dataset.email, true);
    });
  }

  for (const button of widths) {
    button.addEventListener('click', () => {
      if (!['desktop', 'mobile'].includes(button.dataset.width)) return;
      display = button.dataset.width;
      surface.dataset.display = display;
      for (const item of widths) {
        const active = item === button;
        item.classList.toggle('is-active', active);
        item.setAttribute('aria-pressed', String(active));
      }
      requestAnimationFrame(fitFrame);
      announce();
    });
  }

  frame.addEventListener('load', fitFrame);
  window.addEventListener('resize', () => requestAnimationFrame(fitFrame));
})();

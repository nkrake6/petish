/* Preview interactions only. No live account, external requests or stored data. */
(() => {
  'use strict';
  document.documentElement.classList.add('js');
  const theme = document.querySelector('[data-theme-toggle]');
  theme?.addEventListener('click', () => {
    const dark = document.documentElement.dataset.theme !== 'dark';
    document.documentElement.dataset.theme = dark ? 'dark' : 'light';
    theme.setAttribute('aria-pressed', String(dark));
    theme.querySelector('span').textContent = dark ? 'Dark mode' : 'Light mode';
    document.querySelectorAll('[data-light-src]').forEach(image => { image.src = dark ? image.dataset.darkSrc : image.dataset.lightSrc; });
  });
  const menu = document.querySelector('.account-menu');
  if (!menu) return;
  const toggle = menu.querySelector('.menu-toggle');
  const list = menu.querySelector('.menu-list');
  const mobile = matchMedia('(max-width: 991px)');
  const close = restore => { menu.classList.remove('is-open'); toggle.setAttribute('aria-expanded', String(!mobile.matches)); if (restore) toggle.focus(); };
  toggle.addEventListener('click', () => { const open = menu.classList.toggle('is-open'); toggle.setAttribute('aria-expanded', String(open)); });
  menu.addEventListener('keydown', event => { if (event.key === 'Escape' && mobile.matches && menu.classList.contains('is-open')) { event.preventDefault(); close(true); } });
  document.addEventListener('pointerdown', event => { if (!menu.contains(event.target)) close(false); });
  menu.addEventListener('focusout', () => { setTimeout(() => { if (!menu.contains(document.activeElement)) close(false); }, 0); });
  const copy = {
    dashboard: ['Your little corner of Petish.', 'Keep your account, orders and pet profiles together in one familiar place.'],
    orders: ['Every little adventure, in one place.', 'Follow your orders and revisit the products you’ve chosen for your pets.'],
    downloads: ['Your useful little extras.', 'Find digital items available through your Petish account.'],
    addresses: ['The right place for every delivery.', 'Keep your billing and delivery addresses up to date.'],
    'payment-methods': ['Ready for their next discovery.', 'Manage the saved payment methods associated with your account.'],
    'account-details': ['Make yourself at home.', 'Keep your name, email address and password up to date.'],
    'my-pets': ['Little pets. Big personalities.', 'A little space for every small member of your family.'],
    'for-my-pet': ['Thoughtful finds for your companion.', 'Explore the collection for your active pet.'],
    'log-out': ['You’re still in the preview.', 'On the live site, this item would securely sign you out of your account.']
  };
  function select(link, announce) {
    const key = link.getAttribute('href').slice(1);
    if (!copy[key]) return;
    list.querySelectorAll('a').forEach(item => item.removeAttribute('aria-current'));
    link.setAttribute('aria-current', 'page');
    const label = link.querySelector('.menu-label').textContent;
    menu.querySelector('.menu-current').textContent = label;
    toggle.setAttribute('aria-label', 'Account menu. Current section: ' + label);
    document.querySelector('[data-sample-title]').textContent = label;
    document.querySelector('[data-sample-heading]').textContent = copy[key][0];
    document.querySelector('[data-sample-copy]').textContent = copy[key][1];
    if (announce) document.querySelector('[data-preview-status]').textContent = label + ' selected in this preview.';
    close(announce && mobile.matches);
  }
  list.addEventListener('click', event => { const link = event.target.closest('a'); if (!link) return; event.preventDefault(); history.replaceState(null, '', link.getAttribute('href')); select(link, true); });
  const initial = [...list.querySelectorAll('a')].find(link => link.getAttribute('href') === location.hash) || list.querySelector('[aria-current="page"]');
  select(initial, false);
  mobile.addEventListener('change', () => {
    const focused = document.activeElement;
    close(false);
    if (mobile.matches && list.contains(focused)) toggle.focus();
    else if (!mobile.matches && focused === toggle) list.querySelector('[aria-current="page"]').focus();
  });
})();

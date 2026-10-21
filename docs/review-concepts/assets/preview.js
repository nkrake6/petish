/* Isolated design-preview interactions. No requests, uploads or storage. */
(() => {
  'use strict';
  const all = (selector, root = document) => [...root.querySelectorAll(selector)];
  const announce = message => {
    let status = document.querySelector('[data-preview-status]');
    if (!status) {
      status = document.createElement('div');
      status.dataset.previewStatus = '';
      status.setAttribute('role', 'status');
      status.style.cssText = 'position:fixed;left:50%;bottom:20px;transform:translateX(-50%);z-index:100;width:max-content;max-width:calc(100% - 32px);padding:12px 18px;border:1px solid #a8dfb8;border-radius:12px;background:#19263d;color:#fff;font:14px/1.5 sans-serif;box-shadow:0 8px 40px #0003';
      document.body.append(status);
    }
    status.textContent = message;
    clearTimeout(announce.timer);
    announce.timer = setTimeout(() => status.remove(), 5500);
  };

  document.addEventListener('click', event => {
    const button = event.target.closest('button, [data-open-review], [data-close-review], [data-video-preview]');
    if (!button) return;

    if (button.matches('[data-theme-toggle]')) {
      const dark = document.documentElement.dataset.theme !== 'dark';
      document.documentElement.dataset.theme = dark ? 'dark' : 'light';
      button.setAttribute('aria-pressed', String(dark));
      const label = button.querySelector('[data-theme-label]');
      if (label) label.textContent = dark ? 'Light mode' : 'Dark mode';
    }
    if (button.matches('[data-open-review]')) {
      event.preventDefault();
      const dialog = document.querySelector('#review-dialog');
      if (dialog) dialog.showModal();
      else document.querySelector('[data-review-form]')?.scrollIntoView({ block: 'center', behavior: 'auto' });
    }
    if (button.matches('[data-close-review], [data-close-video]')) button.closest('dialog')?.close();

    if (button.matches('[data-video-preview]')) {
      event.preventDefault();
      let dialog = document.querySelector('#video-dialog');
      if (!dialog) {
        dialog = document.createElement('dialog');
        dialog.id = 'video-dialog';
        dialog.setAttribute('aria-label', 'Sample video review');
        dialog.style.cssText = 'width:min(520px,calc(100% - 32px));padding:28px;border:1px solid #a8dfb8;border-radius:22px;background:#14213b;color:white;font:16px/1.6 sans-serif';
        dialog.innerHTML = '<p style="color:#bcd5ff;font-size:12px;letter-spacing:.12em">VIDEO REVIEW PREVIEW</p><h2 style="font-size:26px;line-height:1.2">A little moment, in motion.</h2><p>This shows where a customer’s short product-review video would play. The mockup uses a sample photo; no video has been uploaded.</p><button data-close-video type="button" style="padding:12px 20px;background:#a8dfb8;color:#182c23;border:0;border-radius:10px;font-weight:700">Back to the design</button>';
        document.body.append(dialog);
      }
      dialog.showModal();
    }

    if (button.matches('[data-vote]')) {
      const group = button.closest('[data-vote-group]') || button.closest('article') || button.parentElement;
      const wasPressed = button.getAttribute('aria-pressed') === 'true';
      for (const peer of all('[data-vote]', group)) {
        const count = peer.querySelector('[data-vote-count]');
        if (peer.getAttribute('aria-pressed') === 'true' && count) count.textContent = String(Math.max(0, Number(count.textContent) - 1));
        peer.setAttribute('aria-pressed', 'false');
      }
      if (!wasPressed) {
        button.setAttribute('aria-pressed', 'true');
        const count = button.querySelector('[data-vote-count]');
        if (count) count.textContent = String(Number(count.textContent) + 1);
      }
      announce('Preview reaction updated. No live review was changed.');
    }

    if (button.matches('[data-filter]')) {
      const filter = button.dataset.filter;
      all('[data-filter]').forEach(peer => {
        const selected = peer.dataset.filter === filter;
        peer.classList.toggle('active', selected);
        peer.setAttribute('aria-pressed', String(selected));
      });
      let count = 0;
      all('[data-review-kind]').forEach(card => {
        const show = filter === 'all' || (card.dataset.reviewKind || '').split(/\s+/).includes(filter);
        card.hidden = !show;
        if (show) count++;
      });
      const status = document.querySelector('[data-filter-status]');
      if (status) status.textContent = `${count} sample ${count === 1 ? 'review' : 'reviews'} shown`;
    }

    if (button.matches('[data-tab]')) {
      const tab = button.dataset.tab;
      all('[data-tab]').forEach(peer => {
        const selected = peer.dataset.tab === tab;
        peer.classList.toggle('active', selected);
        if (peer.getAttribute('role') === 'tab') peer.setAttribute('aria-selected', String(selected));
        else peer.setAttribute('aria-pressed', String(selected));
      });
      all('[data-tab-panel]').forEach(panel => panel.hidden = panel.dataset.tabPanel !== tab);
    }

    if (button.matches('[data-star-value]')) {
      const picker = button.closest('[data-star-picker]');
      if (!picker) return;
      const rating = Number(button.dataset.starValue);
      picker.dataset.rating = String(rating);
      all('[data-star-value]', picker).forEach(star => {
        const value = Number(star.dataset.starValue);
        star.setAttribute('aria-pressed', String(value === rating));
        star.classList.toggle('selected', value <= rating);
        star.classList.toggle('is-selected', value <= rating);
        star.dataset.selected = String(value <= rating);
        star.style.color = value <= rating ? '#c98600' : '';
      });
      const form = picker.closest('form') || document;
      const label = form.querySelector('[data-star-label]');
      if (label) label.textContent = `${rating} out of 5 — ${['', 'Poor', 'Fair', 'Good', 'Very good', 'Excellent'][rating]} quality`;
      const input = form.querySelector('[name="rating"]');
      if (input) input.value = String(rating);
    }

    if (button.matches('[data-upload-preview]')) {
      const form = button.closest('form') || document;
      const sample = form.querySelector('[data-upload-sample]');
      if (sample) {
        sample.hidden = !sample.hidden;
        button.setAttribute('aria-expanded', String(!sample.hidden));
      }
      announce('Sample attachments only. This preview does not select or upload your files.');
    }
    if (button.matches('[data-demo-action]')) announce(button.dataset.demoAction || 'This is a design preview; your account is unchanged.');
    if (button.matches('[data-report]')) announce('Preview: a report would be sent privately to the moderation team. Nothing was submitted.');
  });

  document.addEventListener('change', event => {
    if (!event.target.matches('[data-sort-reviews], [data-sort]')) return;
    const mode = event.target.value;
    const cards = all('[data-review-kind][data-rating]');
    cards.sort((a, b) => mode === 'highest' ? Number(b.dataset.rating) - Number(a.dataset.rating)
      : mode === 'lowest' ? Number(a.dataset.rating) - Number(b.dataset.rating)
        : (b.dataset.date || '').localeCompare(a.dataset.date || ''));
    const columns = all('.wall-column');
    if (columns.length) {
      // Keep each column's original capacity; the approved-note card stays last.
      const slots = columns.map(column => column.querySelectorAll('[data-review-kind]').length);
      let offset = 0;
      columns.forEach((column, index) => {
        const note = column.querySelector('.community-note');
        for (const card of cards.slice(offset, offset + slots[index])) column.insertBefore(card, note);
        offset += slots[index];
      });
    } else {
      const parent = cards[0]?.parentElement;
      if (parent) cards.forEach(card => parent.append(card));
    }
  });

  all('form').forEach(form => form.addEventListener('submit', event => {
    event.preventDefault();
    let confirmation = form.querySelector('[data-review-confirmation]');
    if (!confirmation) {
      confirmation = document.createElement('p');
      confirmation.dataset.reviewConfirmation = '';
      confirmation.setAttribute('role', 'status');
      form.append(confirmation);
    }
    confirmation.hidden = false;
    confirmation.textContent = 'Preview: your review would now enter moderation. Nothing has been uploaded or published.';
  }));
  all('dialog').forEach(dialog => dialog.addEventListener('click', event => {
    if (event.target !== dialog) return;
    const rect = dialog.getBoundingClientRect();
    if (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom) dialog.close();
  }));
})();

/* WordPress Media Library controls; selection is persisted only by Save changes. */
(() => {
  'use strict';
  const config = window.petishHeroImages || {};
  document.addEventListener('click', (event) => {
    const choose = event.target.closest('[data-petish-hero-choose]');
    const reset = event.target.closest('[data-petish-hero-reset]');
    if (!choose && !reset) return;
    const field = (choose || reset).closest('[data-petish-hero-setting]');
    if (!field) return;
    event.preventDefault();
    const input = field.querySelector('[data-petish-hero-id]');
    const preview = field.querySelector('[data-petish-hero-preview]');
    if (!input || !preview) return;
    if (reset) {
      input.value = '0';
      preview.src = preview.dataset.defaultSrc;
      return;
    }
    if (!window.wp || !window.wp.media) return;
    const frame = window.wp.media({
      title: config.title || 'Choose a homepage image',
      button: {text: config.button || 'Use this image'},
      library: {type: 'image'}, multiple: false
    });
    frame.on('open', () => {
      const id = Number(input.value);
      if (Number.isSafeInteger(id) && id > 0) frame.state().get('selection').add(window.wp.media.attachment(id));
    });
    frame.on('select', () => {
      const attachment = frame.state().get('selection').first();
      if (!attachment) return;
      const image = attachment.toJSON();
      if (!Number.isSafeInteger(image.id) || image.id <= 0 || image.type !== 'image') return;
      const url = image.sizes?.large?.url || image.sizes?.full?.url || image.url;
      try {
        const parsed = new URL(url, window.location.href);
        if (!['https:', 'http:'].includes(parsed.protocol) || parsed.username || parsed.password) return;
        input.value = String(image.id);
        preview.src = parsed.href;
      } catch (_) { /* Keep the prior selection when the media response is incomplete. */ }
    });
    frame.open();
  });
})();

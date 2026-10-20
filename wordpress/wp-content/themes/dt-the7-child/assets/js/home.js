/* Manual homepage photographs. No autoplay, timers or changes to pet context. */
(() => {
  'use strict';

  const initialize = () => {
    document.querySelectorAll('[data-petish-hero]').forEach((hero) => {
      const slides = Array.from(hero.querySelectorAll('[data-petish-hero-slide]'));
      const buttons = Array.from(hero.querySelectorAll('[data-petish-hero-button]'));
      const controls = hero.querySelector('[data-petish-hero-controls]');
      const status = hero.querySelector('[data-petish-hero-status]');
      if (!controls || slides.length < 2 || slides.length !== buttons.length) return;
      if (!buttons.every((button, index) => button.getAttribute('aria-controls') === slides[index].id)) return;

      let current = 0;
      const select = (index, announce = true) => {
        slides.forEach((slide, slideIndex) => { slide.hidden = slideIndex !== index; });
        buttons.forEach((button, buttonIndex) => {
          button.setAttribute('aria-pressed', String(buttonIndex === index));
        });
        current = index;
        if (announce && status) status.textContent = `${buttons[index].textContent.trim()} · ${index + 1} / ${slides.length}`;
      };

      buttons.forEach((button, index) => {
        button.addEventListener('click', () => { if (index !== current) select(index); });
      });
      select(0, false);
      hero.setAttribute('aria-roledescription', 'carousel');
      controls.hidden = false;
    });
  };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize, { once: true });
  else initialize();
})();

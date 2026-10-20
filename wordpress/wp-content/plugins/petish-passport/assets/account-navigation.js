/* Progressive disclosure of WooCommerce's existing account links on mobile. */
(function () {
  'use strict';

  function initialise() {
    var strings = window.petishAccountNavigation || {};
    var menuLabel = strings.menuLabel || 'Account menu';
    var currentSection = strings.currentSection || 'Current section: %s';
    var sequence = 0;

    document.querySelectorAll('.woocommerce-MyAccount-navigation').forEach(function (navigation) {
      if (navigation.dataset.ppAccountNavigation === 'true') return;
      var links = navigation.querySelector('ul');
      var current = navigation.querySelector('li.is-active a') || navigation.querySelector('a[aria-current="page"]') || navigation.querySelector('a');
      if (!links || !current) return;

      var label = current.textContent.trim();
      if (!label) return;
      if (!links.id) {
        do { sequence += 1; } while (document.getElementById('pp-account-links-' + sequence));
        links.id = 'pp-account-links-' + sequence;
      }

      var button = document.createElement('button');
      button.type = 'button';
      button.className = 'pp-account-navigation-toggle';
      button.setAttribute('aria-controls', links.id);
      button.setAttribute('aria-label', menuLabel + '. ' + currentSection.replace('%s', label));
      var text = document.createElement('span');
      text.className = 'pp-account-navigation-text';
      var caption = document.createElement('span');
      caption.className = 'pp-account-navigation-caption';
      caption.textContent = menuLabel;
      var selected = document.createElement('span');
      selected.className = 'pp-account-navigation-current';
      selected.textContent = label;
      text.append(caption, selected);
      var chevron = document.createElement('span');
      chevron.className = 'pp-account-navigation-chevron';
      chevron.setAttribute('aria-hidden', 'true');
      button.append(text, chevron);

      var mobile = window.matchMedia('(max-width: 991px)');
      var open = mobile.matches && links.contains(document.activeElement);
      var lastFocused = document.activeElement;
      function update() {
        navigation.classList.toggle('pp-account-navigation-open', mobile.matches && open);
        button.setAttribute('aria-expanded', String(!mobile.matches || open));
      }
      function close(restoreFocus) {
        if (!mobile.matches || !open) return;
        open = false;
        update();
        if (restoreFocus) button.focus({ preventScroll: true });
      }
      button.addEventListener('click', function () {
        if (!mobile.matches) return;
        open = !open;
        update();
      });
      navigation.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && mobile.matches && open) {
          event.preventDefault();
          close(true);
        }
      });
      links.addEventListener('click', function (event) {
        if (event.target.closest('a')) close(false);
      });
      document.addEventListener('pointerdown', function (event) {
        if (!navigation.contains(event.target)) {
          lastFocused = event.target;
          close(false);
        }
      });
      document.addEventListener('focusin', function (event) {
        lastFocused = event.target;
      });
      navigation.addEventListener('focusout', function () {
        window.setTimeout(function () {
          if (!navigation.contains(document.activeElement)) close(false);
        }, 0);
      });
      function resize() {
        var focused = document.activeElement;
        // Responsive CSS can blur a hidden control before the media event fires.
        if (focused === document.body && navigation.contains(lastFocused)) focused = lastFocused;
        open = false;
        update();
        if (mobile.matches && links.contains(focused)) button.focus({ preventScroll: true });
        else if (!mobile.matches && focused === button) current.focus({ preventScroll: true });
      }
      if (mobile.addEventListener) mobile.addEventListener('change', resize);
      else mobile.addListener(resize);

      navigation.insertBefore(button, links);
      navigation.classList.add('pp-account-navigation');
      navigation.dataset.ppAccountNavigation = 'true';
      update();
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialise, { once: true });
  else initialise();
}());

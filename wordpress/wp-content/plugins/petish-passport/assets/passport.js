/* Progressive enhancement only: ownership, publication and hearts stay server-side. */
(function () {
  'use strict';
  var root = document.querySelector('.petish-passport');
  if (!root) return;
  var species = root.querySelector('#pp-species');
  var variety = root.querySelector('[data-pp-variety]');
  var otherField = root.querySelector('[data-pp-variety-other]');
  if (species && variety && otherField) {
    var otherInput = otherField.querySelector('input');
    var groups = Array.from(variety.querySelectorAll('optgroup')).map(function (group) { return group.cloneNode(true); });
    var blank = variety.querySelector('option[value=""]').cloneNode(true);
    function showOther() {
      var isOther = variety.value === 'other';
      otherField.hidden = !isOther;
      otherInput.disabled = !isOther;
      otherInput.required = isOther;
    }
    function filterVarieties(changedSpecies) {
      var selected = changedSpecies ? '' : variety.value;
      while (variety.firstChild) variety.removeChild(variety.firstChild);
      variety.appendChild(blank.cloneNode(true));
      groups.forEach(function (group) {
        if (group.dataset.species === species.value) variety.appendChild(group.cloneNode(true));
      });
      variety.value = Array.from(variety.options).some(function (option) { return option.value === selected; }) ? selected : '';
      if (changedSpecies) otherInput.value = '';
      showOther();
    }
    species.addEventListener('change', function () { filterVarieties(true); });
    variety.addEventListener('change', showOther);
    filterVarieties(false);
  }
  var upload = root.querySelector('[data-pp-photo]');
  var feedback = root.querySelector('[data-pp-photo-feedback]');
  if (upload && feedback) upload.addEventListener('change', function () {
    var file = upload.files && upload.files[0];
    upload.setCustomValidity('');
    upload.removeAttribute('aria-invalid');
    feedback.textContent = '';
    if (!file) return;
    if (file.size > 5 * 1024 * 1024) {
      feedback.textContent = 'This photo is larger than 5 MB. Please choose a smaller file.';
      upload.setCustomValidity(feedback.textContent);
      upload.setAttribute('aria-invalid', 'true');
    } else if (file.type && ['image/jpeg', 'image/png', 'image/webp'].indexOf(file.type) === -1) {
      feedback.textContent = 'Please choose a JPEG, PNG or WebP photo.';
      upload.setCustomValidity(feedback.textContent);
      upload.setAttribute('aria-invalid', 'true');
    } else {
      feedback.textContent = 'Selected: ' + file.name + '. Saved privately; sharing is a separate choice.';
    }
  });
  var tablist = root.querySelector('[data-pc-tabs]');
  if (tablist) {
    var tabs = Array.from(tablist.querySelectorAll('[data-pc-tab]'));
    var panels = tabs.map(function (tab) { return root.querySelector('#pc-panel-' + tab.dataset.pcTab); });
    if (tabs.length && panels.every(Boolean)) {
      tablist.setAttribute('role', 'tablist');
      function activate(tab, focus) {
        tabs.forEach(function (item, i) {
          var active = item === tab;
          item.setAttribute('aria-selected', String(active));
          item.tabIndex = active ? 0 : -1;
          panels[i].hidden = !active;
        });
        if (focus) tab.focus();
      }
      tabs.forEach(function (tab, i) {
        tab.setAttribute('role', 'tab');
        tab.setAttribute('aria-controls', panels[i].id);
        panels[i].setAttribute('role', 'tabpanel');
        panels[i].tabIndex = 0;
        tab.addEventListener('click', function (event) { event.preventDefault(); activate(tab, false); });
        tab.addEventListener('keydown', function (event) {
          var target;
          if (event.key === 'ArrowRight') target = tabs[(i + 1) % tabs.length];
          else if (event.key === 'ArrowLeft') target = tabs[(i + tabs.length - 1) % tabs.length];
          else if (event.key === 'Home') target = tabs[0];
          else if (event.key === 'End') target = tabs[tabs.length - 1];
          if (target) { event.preventDefault(); activate(target, true); }
        });
      });
      var initial = tabs.find(function (tab) { return tab.getAttribute('href') === window.location.hash; }) || tabs[0];
      activate(initial, false);
    }
  }
  var dialog = root.querySelector('[data-pc-lightbox]');
  if (dialog && typeof dialog.showModal === 'function') {
    var image = dialog.querySelector('[data-pc-lightbox-image]');
    var caption = dialog.querySelector('[data-pc-lightbox-caption]');
    var lastPhoto;
    root.querySelectorAll('[data-pc-photo]').forEach(function (link) {
      link.addEventListener('click', function (event) {
        if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        event.preventDefault();
        var source = link.querySelector('img');
        image.src = link.href;
        image.alt = source ? source.alt : '';
        caption.textContent = link.dataset.caption || '';
        lastPhoto = link;
        dialog.showModal();
      });
    });
    dialog.querySelector('[data-pc-close]').addEventListener('click', function () { dialog.close(); });
    dialog.addEventListener('click', function (event) {
      if (event.target !== dialog) return;
      var rect = dialog.getBoundingClientRect();
      if (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom) dialog.close();
    });
    dialog.addEventListener('close', function () {
      image.removeAttribute('src');
      image.alt = '';
      caption.textContent = '';
      if (lastPhoto && lastPhoto.isConnected) lastPhoto.focus();
    });
  }
  if (navigator.clipboard && navigator.clipboard.writeText) root.querySelectorAll('[data-pc-copy]').forEach(function (button) {
    button.hidden = false;
    button.addEventListener('click', function () {
      var status = root.querySelector('[data-pc-copy-status]');
      navigator.clipboard.writeText(button.dataset.pcCopy).then(function () {
        if (status) status.textContent = 'Profile link copied.';
      }).catch(function () {
        if (status) status.textContent = 'Copy the address from your browser to share this profile.';
      });
    });
  });
  var error = document.querySelector('.woocommerce-error');
  if (error) { error.setAttribute('tabindex', '-1'); error.focus(); }
})();

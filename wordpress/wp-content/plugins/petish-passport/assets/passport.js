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
  var directoryFilters = root.querySelector('[data-pc-directory-filters]');
  if (directoryFilters) {
    var filterSpecies = directoryFilters.querySelector('[data-pc-filter-species]');
    var filterVariety = directoryFilters.querySelector('[data-pc-filter-variety]');
    var suggestions = directoryFilters.querySelector('[data-pc-filter-suggestions]');
    if (filterSpecies && filterVariety && suggestions) {
      var choices = Array.from(suggestions.options).map(function (option) { return option.cloneNode(true); });
      function suggestVarieties(changedSpecies) {
        while (suggestions.firstChild) suggestions.removeChild(suggestions.firstChild);
        choices.forEach(function (option) {
          if (!filterSpecies.value || option.dataset.species === filterSpecies.value) suggestions.appendChild(option.cloneNode(true));
        });
        if (changedSpecies) filterVariety.value = '';
      }
      filterSpecies.addEventListener('change', function () { suggestVarieties(true); });
      suggestVarieties(false);
    }
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
  var videoUpload = root.querySelector('[data-pp-video]');
  var videoFeedback = root.querySelector('[data-pp-video-feedback]');
  var videoPreview = root.querySelector('[data-pp-video-preview]');
  if (videoUpload && videoFeedback && videoPreview) {
    var previewVideo = videoPreview.querySelector('video');
    var previewURL = '';
    function clearVideoPreview() {
      previewVideo.pause();
      previewVideo.onloadedmetadata = null;
      previewVideo.onerror = null;
      previewVideo.removeAttribute('src');
      previewVideo.load();
      videoPreview.hidden = true;
      if (previewURL) URL.revokeObjectURL(previewURL);
      previewURL = '';
    }
    function videoError(message) {
      videoFeedback.textContent = message;
      videoUpload.setCustomValidity(message);
      videoUpload.setAttribute('aria-invalid', 'true');
      clearVideoPreview();
    }
    videoUpload.addEventListener('change', function () {
      clearVideoPreview();
      videoUpload.setCustomValidity('');
      videoUpload.removeAttribute('aria-invalid');
      videoFeedback.textContent = '';
      var file = videoUpload.files && videoUpload.files[0];
      if (!file) return;
      if (file.size > 20 * 1024 * 1024) { videoError('This video is larger than 20 MB. Please choose a smaller file.'); return; }
      if ((file.type && file.type !== 'video/mp4') || !/\.mp4$/i.test(file.name)) { videoError('Please choose an MP4 video with H.264 video and optional AAC audio.'); return; }
      if (!window.URL || !URL.createObjectURL) return;
      videoFeedback.textContent = 'Checking the video’s duration…';
      videoUpload.setCustomValidity('Please wait while the video duration is checked.');
      previewURL = URL.createObjectURL(file);
      previewVideo.onloadedmetadata = function () {
        if (previewVideo.currentSrc !== previewURL) return;
        if (!Number.isFinite(previewVideo.duration) || previewVideo.duration <= 0 || previewVideo.duration > 20) {
          videoError('Choose a video that is no longer than 20 seconds.'); return;
        }
        videoUpload.setCustomValidity('');
        videoUpload.removeAttribute('aria-invalid');
        videoFeedback.textContent = 'Selected: ' + file.name + '. Save privately when you’re ready.';
        videoPreview.hidden = false;
      };
      previewVideo.onerror = function () { videoError('This browser could not preview the video. Try an MP4 with H.264 video and optional AAC audio.'); };
      previewVideo.src = previewURL;
    });
    window.addEventListener('pagehide', function () { clearVideoPreview(); });
  }
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
          if (!active) panels[i].querySelectorAll('video').forEach(function (video) { video.pause(); });
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

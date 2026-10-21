/* Progressive enhancement only: all submissions and permission checks stay server-side. */
(function () {
  'use strict';
  function initialise() {
    if (document.getElementById('petish-customer-reviews')) {
      document.querySelectorAll('a.woocommerce-review-link').forEach(function (link) {
        link.setAttribute('href', '#petish-customer-reviews');
      });
    }
    document.querySelectorAll('[data-pcr-form]').forEach(function (form) {
      if (form.dataset.pcrReady) return;
      form.dataset.pcrReady = 'true';
      var choices = Array.from(form.querySelectorAll('[name="rating"]'));
      var ratingLabel = form.querySelector('[data-pcr-rating-label]');
      function updateStars() {
        var checked = choices.find(function (input) { return input.checked; });
        var rating = checked ? Number(checked.value) : 0;
        choices.forEach(function (input) { input.closest('label').classList.toggle('is-filled', Number(input.value) <= rating); });
        if (ratingLabel && rating) ratingLabel.textContent = rating + ' / 5 — ' + ['', 'Poor', 'Fair', 'Good', 'Very good', 'Excellent'][rating] + ' quality';
      }
      choices.forEach(function (input) { input.addEventListener('change', updateStars); });
      updateStars();
      var photoInput = form.querySelector('[data-pcr-photo-files]');
      var avatarInput = form.querySelector('[data-pcr-avatar-file]');
      var videoInput = form.querySelector('[data-pcr-video-file]');
      var error = form.querySelector('[data-pcr-file-error]');
      function validateFiles() {
        var message = '';
        [photoInput, avatarInput, videoInput].forEach(function (input) { if (input) input.setCustomValidity(''); });
        var photos = photoInput ? Array.from(photoInput.files || []) : [];
        var avatar = avatarInput ? Array.from(avatarInput.files || []) : [];
        var video = videoInput ? Array.from(videoInput.files || []) : [];
        if (photos.length > 4) { message = 'Please choose no more than 4 product photos.'; photoInput.setCustomValidity(message); }
        photos.concat(avatar).forEach(function (file) {
          if (file.size > 5 * 1024 * 1024) { message = 'Each product photo or reviewer photo must be 5 MB or smaller.'; (avatar.indexOf(file) >= 0 ? avatarInput : photoInput).setCustomValidity(message); }
        });
        if (video.some(function (file) { return file.size > 20 * 1024 * 1024; })) { message = 'Your video must be 20 MB or smaller.'; videoInput.setCustomValidity(message); }
        if (error) { error.textContent = message; error.hidden = !message; }
        return !message;
      }
      [photoInput, avatarInput, videoInput].forEach(function (input) { if (input) input.addEventListener('change', validateFiles); });
      form.addEventListener('submit', function (event) {
        if (!validateFiles()) { event.preventDefault(); form.reportValidity(); }
      });
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialise);
  else initialise();
})();

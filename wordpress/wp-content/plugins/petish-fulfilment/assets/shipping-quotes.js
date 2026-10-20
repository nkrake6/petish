/* Native Woo owns country/state fields; this file only enhances form feedback. */
(function () {
  'use strict';
  var forms = [];

  function restore(form) {
    var state = form.petishQuoteSubmitState;
    if (!state) return;
    state.submitting = false;
    form.removeAttribute('aria-busy');
    if (state.button) state.button.disabled = state.wasDisabled;
    if (state.status) state.status.textContent = state.initialStatus;
  }

  function init() {
    document.querySelectorAll('form#petish-shipping-quote-form, .petish-shipping-quote form.petish-quote-form').forEach(function (form) {
      if (form.petishQuoteSubmitState) return;
      var button = form.querySelector('button[type="submit"], input[type="submit"]');
      var status = form.querySelector('[data-petish-quote-submit-status]');
      if (!status) {
        status = document.createElement('span');
        status.className = 'petish-quote-submit-status';
        status.setAttribute('data-petish-quote-submit-status', '');
        status.setAttribute('role', 'status');
        status.setAttribute('aria-live', 'polite');
        (form.querySelector('.petish-quote-actions') || form).appendChild(status);
      }
      var state = {
        submitting: false,
        button: button,
        wasDisabled: button ? button.disabled : false,
        status: status,
        initialStatus: status.textContent
      };
      form.petishQuoteSubmitState = state;
      forms.push(form);
      form.addEventListener('submit', function (event) {
        if (event.defaultPrevented) return;
        if (state.submitting) {
          event.preventDefault();
          return;
        }
        if (!form.noValidate && typeof form.checkValidity === 'function' && !form.checkValidity()) {
          event.preventDefault();
          if (typeof form.reportValidity === 'function') form.reportValidity();
          return;
        }
        state.submitting = true;
        form.setAttribute('aria-busy', 'true');
        if (button) button.disabled = true;
        status.textContent = 'Sending request…';
      });
    });

    // SelectWoo appends native dropdowns to body; mark only quote-owned dropdowns.
    if (window.jQuery) {
      window.jQuery(document).on('select2:open.petishQuote', '.petish-shipping-quote select', function () {
        var dropdown = document.querySelector('.select2-container--open .select2-dropdown');
        if (dropdown) dropdown.classList.add('petish-quote-select-dropdown');
      });
    }
  }

  window.addEventListener('pageshow', function () { forms.forEach(restore); });
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
}());

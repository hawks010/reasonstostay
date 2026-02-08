/**
 * RTS Subscribers: Front-end subscription form handler
 *
 * Works with the new subscription form structure:
 *  - form.rts-subscribe-form
 *  - input[name="action"] (hidden, value="rts_handle_subscription")
 *  - input[name="security"] (hidden, nonce)
 *  - input[name="email"]
 *  - select[name="frequency"]
 *  - input[name="prefs[]"] checkboxes
 *  - input[name="privacy_consent"] checkbox
 *  - .rts-form-message (message container)
 *
 * Expects a global `rtsSubscribe` localized object:
 *  - ajax_url
 */

(function () {
  'use strict';

  function showMessage(el, text, type) {
    if (!el) return;
    el.textContent = text;
    el.className = 'rts-form-message ' + type;
  }

  function setFormState(form, disabled) {
    var els = form.querySelectorAll('input, button, textarea, select');
    for (var i = 0; i < els.length; i++) {
      els[i].disabled = !!disabled;
    }
  }

  function init() {
    document.addEventListener('submit', async function (e) {
      var form = e.target && e.target.closest ? e.target.closest('form.rts-subscribe-form') : null;
      if (!form) return;

      e.preventDefault();

      var msgEl = form.querySelector('.rts-form-message');
      var submitBtn = form.querySelector('.rts-form-submit');
      var originalBtnText = submitBtn ? submitBtn.textContent : '';

      // Basic client-side validation
      var emailInput = form.querySelector('input[name="email"]');
      if (!emailInput || !emailInput.value.trim()) {
        showMessage(msgEl, 'Please enter your email address.', 'error');
        return;
      }

      var consent = form.querySelector('input[name="privacy_consent"]');
      if (consent && !consent.checked) {
        showMessage(msgEl, 'Please agree to the privacy policy to subscribe.', 'error');
        return;
      }

      // Disable form and show loading state
      setFormState(form, true);
      if (submitBtn) submitBtn.textContent = 'Sending\u2026';
      showMessage(msgEl, '', '');
      if (msgEl) msgEl.style.display = 'none';

      try {
        // Build payload from form data
        var formData = new FormData(form);
        var ajaxUrl = (window.rtsSubscribe && window.rtsSubscribe.ajax_url)
          ? window.rtsSubscribe.ajax_url
          : '/wp-admin/admin-ajax.php';

        var res = await fetch(ajaxUrl, {
          method: 'POST',
          credentials: 'same-origin',
          body: new URLSearchParams(formData),
        });

        var text = await res.text();
        var json;
        try {
          json = JSON.parse(text);
        } catch (parseErr) {
          json = { success: false, data: { message: 'Unexpected response from server.' } };
        }

        if (json && json.success) {
          var successMsg = (json.data && json.data.message)
            ? json.data.message
            : 'You\'re subscribed. Thank you!';
          showMessage(msgEl, successMsg, 'success');
          form.reset();
        } else {
          var errMsg = (json && json.data && json.data.message)
            ? json.data.message
            : 'Subscription failed. Please try again.';
          showMessage(msgEl, errMsg, 'error');
        }
      } catch (err) {
        showMessage(msgEl, 'Network error. Please try again.', 'error');
      } finally {
        setFormState(form, false);
        if (submitBtn) submitBtn.textContent = originalBtnText;
      }
    }, { passive: false });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();

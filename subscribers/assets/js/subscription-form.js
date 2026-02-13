/**
 * RTS Subscribers: Front-end subscription form handler
 *
 * Handles submission, loading states, and messaging for the subscription form.
 */

(function () {
  'use strict';

  function showMessage(el, text, type) {
    if (!el) return;
    el.textContent = text;
    el.className = 'rts-form-message ' + type;
    el.style.display = text ? 'block' : 'none';
    el.setAttribute('aria-live', type === 'error' ? 'assertive' : 'polite');
    
    // Focus management for accessibility
    if (text) {
      el.setAttribute('tabindex', '-1');
      setTimeout(function() {
        // For accessibility: focus errors immediately.
        // For success: focus the message so screenreaders announce it.
        if (type === 'error' || type === 'success') {
          el.focus();
          try { el.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); } catch (e) {}
        }
      }, 80);
    }
  }

  function setFormState(form, disabled) {
    var interactiveSelectors = 'input:not([type="hidden"]), button, textarea, select';
    var els = form.querySelectorAll(interactiveSelectors);
    for (var i = 0; i < els.length; i++) {
      els[i].disabled = !!disabled;
      if (disabled) {
        els[i].classList.add('rts-disabled');
      } else {
        els[i].classList.remove('rts-disabled');
      }
    }
  }

  function validateForm(form) {
    // Email validation
    var emailInput = form.querySelector('input[name="email"]');
    if (!emailInput || !emailInput.value.trim()) {
      return 'Please enter your email address.';
    }
    
    var email = emailInput.value.trim();
    var emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!emailRegex.test(email)) {
      return 'Please enter a valid email address.';
    }
    
    // Privacy consent
    var consent = form.querySelector('input[name="privacy_consent"]');
    if (consent && !consent.checked) {
      return 'Please agree to the privacy policy to subscribe.';
    }
    
    // Honeypot checks
    var honeypots = form.querySelectorAll('.rts-honeypot input[type="text"]');
    for (var i = 0; i < honeypots.length; i++) {
        if (honeypots[i].value.trim() !== '') {
            return 'Invalid submission.';
        }
    }
    
    // Preferences check
    var prefs = form.querySelectorAll('input[name="prefs[]"]');
    var checkedPrefs = form.querySelectorAll('input[name="prefs[]"]:checked');
    if (prefs.length > 0 && checkedPrefs.length === 0) {
      return 'Please select at least one preference.';
    }
    
    return null; // No errors
  }

  function encodeFormData(formData) {
    var pairs = [];
    if (!formData || typeof formData.forEach !== 'function') {
      return '';
    }
    formData.forEach(function (value, key) {
      pairs.push(encodeURIComponent(String(key)) + '=' + encodeURIComponent(String(value)));
    });
    return pairs.join('&');
  }

  function requestWithXHR(url, body, timeoutMs) {
    return new Promise(function (resolve, reject) {
      var xhr = new XMLHttpRequest();
      xhr.open('POST', url, true);
      xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
      xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
      xhr.timeout = timeoutMs;

      xhr.onreadystatechange = function () {
        if (xhr.readyState === 4) {
          resolve(xhr.responseText || '');
        }
      };

      xhr.onerror = function () {
        reject(new Error('Network request failed'));
      };
      xhr.ontimeout = function () {
        var timeoutErr = new Error('Request timeout');
        timeoutErr.name = 'AbortError';
        reject(timeoutErr);
      };
      xhr.onabort = function () {
        var abortErr = new Error('Request aborted');
        abortErr.name = 'AbortError';
        reject(abortErr);
      };

      xhr.send(body);
    });
  }

  function requestSubscription(ajaxUrl, body, timeoutMs) {
    if (typeof window.fetch === 'function') {
      if (typeof window.AbortController === 'function') {
        var controller = new AbortController();
        var timeoutId = setTimeout(function () {
          controller.abort();
        }, timeoutMs);
        return fetch(ajaxUrl, {
          method: 'POST',
          body: body,
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
          },
          signal: controller.signal
        }).then(function (res) {
          clearTimeout(timeoutId);
          return res.text();
        }).catch(function (err) {
          clearTimeout(timeoutId);
          throw err;
        });
      }

      return fetch(ajaxUrl, {
        method: 'POST',
        body: body,
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
          'X-Requested-With': 'XMLHttpRequest'
        }
      }).then(function (res) {
        return res.text();
      });
    }

    return requestWithXHR(ajaxUrl, body, timeoutMs);
  }

  function syncCheckboxVisualState(form) {
    var checkboxes = form.querySelectorAll('input[type="checkbox"]');
    for (var i = 0; i < checkboxes.length; i++) {
      var ev;
      if (typeof Event === 'function') {
        ev = new Event('change', { bubbles: true });
      } else {
        ev = document.createEvent('Event');
        ev.initEvent('change', true, true);
      }
      checkboxes[i].dispatchEvent(ev);
    }
  }

  function handleSubmit(e) {
    var form = e.target && e.target.closest ? e.target.closest('form.rts-subscribe-form') : null;
    if (!form) return;
    
    // Prevent double submission
    if (form.dataset.submitting === 'true') return;
    
    e.preventDefault();
    
    var msgEl = form.querySelector('.rts-form-message');
    var submitBtn = form.querySelector('.rts-form-submit');
    var originalBtnText = submitBtn ? submitBtn.textContent : 'Submit';
    var wasSuccessful = false;
    
    // Clear previous messages
    showMessage(msgEl, '', '');
    
    // Validate form
    var validationError = validateForm(form);
    if (validationError) {
      showMessage(msgEl, validationError, 'error');
      return;
    }
    
    // IMPORTANT: Build payload BEFORE disabling controls.
    // Disabled controls are excluded from FormData, which previously caused the
    // server to receive an empty email and throw a validation error.
    var formData = new FormData(form);

    // Lock form
    form.dataset.submitting = 'true';
    setFormState(form, true);
    form.classList.add('rts-submitting');
    if (submitBtn) submitBtn.textContent = 'Sending\u2026';
 
    var ajaxUrl = (window.rtsSubscribe && window.rtsSubscribe.ajax_url)
      ? window.rtsSubscribe.ajax_url
      : '/wp-admin/admin-ajax.php';
    var body = encodeFormData(formData);

    if (typeof Promise === 'undefined') {
      showMessage(msgEl, 'This browser is not supported. Please update your browser.', 'error');
      setFormState(form, false);
      form.classList.remove('rts-submitting');
      if (submitBtn) submitBtn.textContent = originalBtnText;
      delete form.dataset.submitting;
      return;
    }

    var finalizeSubmit = function () {
      setFormState(form, false);
      form.classList.remove('rts-submitting');
      if (submitBtn && !wasSuccessful) submitBtn.textContent = originalBtnText;
      delete form.dataset.submitting;
    };

    requestSubscription(ajaxUrl, body, 30000)
      .then(function (text) {
        var json;
        try {
          json = JSON.parse(text);
        } catch (parseError) {
          console.error('JSON parse error:', parseError, text);
          throw new Error('Invalid server response');
        }

        if (json && json.success) {
          var successMsg = (json.data && json.data.message)
            ? json.data.message
            : 'You\'re subscribed. Thank you!';
          wasSuccessful = true;
          showMessage(msgEl, successMsg, 'success');

          if (submitBtn) {
            submitBtn.textContent = "You're subscribed ✓";
          }

          form.reset();
          syncCheckboxVisualState(form);

          setTimeout(function () {
            if (submitBtn) submitBtn.textContent = originalBtnText;
          }, 4000);
          return;
        }

        var errMsg = (json && json.data && json.data.message)
          ? json.data.message
          : 'Subscription failed. Please try again.';
        showMessage(msgEl, errMsg, 'error');
      })
      .catch(function (err) {
        console.error('Submission error:', err);
        var userMsg = err && err.name === 'AbortError'
          ? 'Request timeout. Please try again.'
          : 'Network error. Please try again.';
        showMessage(msgEl, userMsg, 'error');
      })
      .then(function () {
        finalizeSubmit();
      });
  }

  function init() {
    // Attach submit handler to all subscription forms
    document.addEventListener('submit', handleSubmit);
    
    // Add global change listener for checkbox visual states
    document.body.addEventListener('change', function(e) {
        if (e.target && e.target.matches('.rts-subscribe-form input[type="checkbox"]')) {
            var label = e.target.closest('label') || e.target.parentElement;
            if (label) {
                if (e.target.checked) {
                    label.classList.add('rts-checked');
                } else {
                    label.classList.remove('rts-checked');
                }
            }
        }
    });

    // Robust toggle for the custom checkbox cards.
    // Important: prevent the browser's default label-toggle *and* do our own toggle,
    // otherwise it can double-toggle and look "stuck" (especially on themes that also
    // bind click handlers).
    document.addEventListener('click', function(e) {
      var card = e.target && e.target.closest ? e.target.closest('.rts-subscribe-form .rts-subscribe-checkbox-label') : null;
      if (!card) return;
      if (e.target && (e.target.tagName === 'A' || e.target.closest('a'))) return;

      var cb = card.querySelector('input[type="checkbox"]');
      if (!cb) return;

      // If the user actually clicked the (hidden) input itself, let the browser handle it.
      if (e.target === cb) return;

      // Stop the default label behaviour so we don't toggle twice.
      e.preventDefault();

      cb.checked = !cb.checked;
      var ev;
      if (typeof Event === 'function') {
        ev = new Event('change', { bubbles: true });
      } else {
        ev = document.createEvent('Event');
        ev.initEvent('change', true, true);
      }
      cb.dispatchEvent(ev);
    }, false);

    // Initialize checkboxes on load (handle cached values)
    var initialCbs = document.querySelectorAll('.rts-subscribe-form input[type="checkbox"]');
    for (var i = 0; i < initialCbs.length; i++) {
      var cb = initialCbs[i];
      var label = cb.closest('label') || cb.parentElement;
      if (label && cb.checked) {
        label.classList.add('rts-checked');
      }
    }
  }

  // Initialize
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();

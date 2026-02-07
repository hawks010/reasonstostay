/**
 * RTS Subscribers: Front-end subscription form handler (jQuery-free)
 *
 * Why: Cloudflare Rocket Loader or perf plugins can alter script order.
 * A hard jQuery dependency can throw "jQuery is not defined" and derail UX.
 *
 * Expects a global `rtsSubscribe` localized object:
 *  - ajaxUrl
 *  - nonce
 *
 * Markup expectations:
 *  - form.rts-subscribe-form
 *  - input[name="email"]
 *  - input[name="preferences[]"] checkboxes
 *  - .rts-form-message or .rts-msg (message container)
 */

(function () {
  'use strict';

  function qs(root, sel) {
    return (root || document).querySelector(sel);
  }

  function qsa(root, sel) {
    return Array.prototype.slice.call((root || document).querySelectorAll(sel));
  }

  function setMsg(form, html, isError) {
    // Theme markup uses .rts-form-message; older builds used .rts-msg
    const msg = qs(form, '.rts-form-message') || qs(form, '.rts-msg');
    if (!msg) return;
    msg.innerHTML = html;
    msg.style.display = 'block';

    // Normalise state classes for both markup variants
    msg.classList.remove('error', 'success');
    msg.classList.toggle('rts-msg--error', !!isError); // legacy styling hook
    msg.classList.add(isError ? 'error' : 'success');  // preferred styling hook
  }

  function disableForm(form, disabled) {
    qsa(form, 'input, button, textarea, select').forEach((el) => {
      el.disabled = !!disabled;
    });
  }

  function buildPayload(form) {
    const emailEl = qs(form, 'input[name="email"]');
    const email = emailEl ? String(emailEl.value || '').trim() : '';

    const prefs = qsa(form, 'input[name="preferences[]"]:checked').map((el) => el.value);

    const payload = new URLSearchParams();
    payload.set('action', 'rts_subscribe');
    payload.set('nonce', (window.rtsSubscribe && window.rtsSubscribe.nonce) ? window.rtsSubscribe.nonce : '');
    payload.set('email', email);
    // WP/AJAX parsers can accept repeated keys
    prefs.forEach((p) => payload.append('preferences[]', p));

    return { email, prefs, payload };
  }

  async function postForm(payload) {
    const ajaxUrl = (window.rtsSubscribe && window.rtsSubscribe.ajaxUrl) ? window.rtsSubscribe.ajaxUrl : (window.ajaxurl || '/wp-admin/admin-ajax.php');

    const res = await fetch(ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
      },
      body: payload.toString(),
    });

    // PHP may return non-200 with HTML error pages. Handle gracefully.
    const text = await res.text();
    try {
      return JSON.parse(text);
    } catch (e) {
      return { success: false, data: { message: 'Unexpected response from server.' }, _raw: text };
    }
  }

  function init() {
    // Delegate submit handler (survives dynamic DOM updates)
    document.addEventListener('submit', async (e) => {
      const form = e.target && e.target.closest ? e.target.closest('form.rts-subscribe-form') : null;
      if (!form) return;

      e.preventDefault();

      const { email, prefs, payload } = buildPayload(form);

      if (!email) {
        setMsg(form, '<span>Enter your email address to subscribe.</span>', true);
        return;
      }

      // Preferences are optional, but if none chosen we confirm intention
      // (No modal, just a gentle inline nudge)
      if (!prefs.length) {
        setMsg(form, '<span>No reminders selected. You can still subscribe, but you will not receive reminder emails.</span>', false);
      }

      disableForm(form, true);
      setMsg(form, '<span>Saving…</span>', false);

      try {
        const json = await postForm(payload);
        if (json && json.success) {
          setMsg(form, '<span>You\'re subscribed. Thank you.</span>', false);
          form.reset();
        } else {
          const msg = (json && json.data && json.data.message) ? json.data.message : 'Subscription failed.';
          setMsg(form, '<span>' + String(msg) + '</span>', true);
        }
      } catch (err) {
        setMsg(form, '<span>Network error. Please try again.</span>', true);
      } finally {
        disableForm(form, false);
      }
    }, { passive: false });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();

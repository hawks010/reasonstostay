/**
 * Reasons to Stay Embed Widget (Mini Letter)
 *
 * Renders a mini version of the site letter view as an embeddable A4-style card.
 * Uses Shadow DOM to prevent style conflicts.
 *
 * Required container:
 *   <div id="rts-widget" data-api="https://example.com/wp-json/rts/v1/embed/random"></div>
 *
 * Optional data attributes:
 *   data-site  - site URL (defaults to API response site_url)
 *   data-logo  - logo URL (defaults to API response logo_url)
 *   data-brand - brand name (defaults to "Reasons to Stay")
 */
(function () {
  'use strict';

  var DEFAULT_TITLE = 'Reasons to Stay';
  var FALLBACK_URL = 'https://www.google.com/search?q=Reasons+to+Stay';

  // Resolve API URL:
  // 1) data-api on the host element
  // 2) script origin derived from this script's src
  function resolveApiUrl(host) {
    var apiUrl = host.getAttribute('data-api');
    if (apiUrl) return apiUrl;

    var scriptEl = document.currentScript;
    if (scriptEl && scriptEl.src) {
      try {
        var u = new URL(scriptEl.src, window.location.href);
        return u.origin + '/wp-json/rts/v1/embed/random';
      } catch (e) {}
    }

    var scripts = document.querySelectorAll('script[src]');
    for (var i = 0; i < scripts.length; i++) {
      var src = scripts[i].getAttribute('src') || '';
      if (src.indexOf('rts-widget.js') !== -1) {
        try {
          var u2 = new URL(src, window.location.href);
          return u2.origin + '/wp-json/rts/v1/embed/random';
        } catch (e2) {}
      }
    }

    return '';
  }

  function resolveReportUrl(apiUrl) {
    try {
      var u = new URL(apiUrl, window.location.href);
      return u.origin + '/wp-json/rts/v1/embed/report';
    } catch (e) {
      return '';
    }
  }

  function escapeHtml(str) {
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(str || ''));
    return div.innerHTML;
  }

  function escapeAttr(str) {
    return escapeHtml(str).replace(/"/g, '&quot;');
  }

  // Shadow DOM CSS
  // Includes Special Elite for the letter feel and the full paper effect.
  var WIDGET_CSS = [
    "@import url('https://fonts.googleapis.com/css2?family=Special+Elite&family=Inter:wght@400;600;700&display=swap');",
    ':host{display:block;}',
    '.rts-embed-wrap{max-width:1180px;margin:0 auto;padding:0 14px;}',

    /* Paper effect wrapper (matches your provided CSS) */
    '.rts-paper{position:relative;}',
    '.rts-paper{background-image:radial-gradient(circle at center, rgba(255,255,255,0.95) 20%, rgba(255,255,255,0.6) 100%);background-size:cover;background-position:center;background-repeat:no-repeat;}',
    '.rts-paper{box-shadow:inset 0 0 80px rgba(160,140,100,0.15), 0 1px 2px rgba(0,0,0,0.10), 0 20px 40px -10px rgba(0,0,0,0.10);}',
    '.rts-paper{border-radius:2px;border:1px solid rgba(0,0,0,0.03);}',

    /* Tape */
    '.rts-paper:before{content:"";position:absolute;top:-15px;left:50%;transform:translateX(-50%) rotate(1deg);width:120px;height:35px;background-color:rgba(255,255,255,0.4);box-shadow:0 1px 4px rgba(0,0,0,0.1);backdrop-filter:blur(2px);border:1px solid rgba(255,255,255,0.3);z-index:10;}',

    /* Inner layout */
    '.rts-inner{position:relative;z-index:1;padding:64px 64px 56px 64px;}',
    '.rts-top{display:flex;align-items:flex-start;justify-content:space-between;gap:18px;margin-bottom:32px;}',

    /* Brand */
    '.rts-brand{display:flex;align-items:flex-start;gap:14px;text-decoration:none;color:#111827;}',
    '.rts-brand__logo{width:48px;height:48px;object-fit:contain;border-radius:8px;background:#fff;border:1px solid rgba(0,0,0,0.08);}',
    '.rts-brand__name{font-family:"Special Elite", ui-monospace, monospace;font-size:1.4rem;line-height:1.1;color:#111827;}',

    /* Right intro text */
    '.rts-intro{font-family:"Special Elite", ui-monospace, monospace;text-align:right;color:#111827;font-size:1.05rem;line-height:1.45;max-width:520px;}',

    /* Letter content */
    '.rts-letter{font-family:"Special Elite", ui-monospace, monospace;color:#111827;font-size:1.05rem;line-height:1.65;}',
    '.rts-letter p{margin:0 0 18px 0;}',
    '.rts-letter p:last-child{margin-bottom:0;}',
    '.rts-letter h1,.rts-letter h2,.rts-letter h3{font-family:"Special Elite", ui-monospace, monospace;margin:0 0 12px 0;}',

    /* Footer */
    '.rts-footer{display:flex;align-items:center;justify-content:flex-end;gap:14px;margin-top:34px;flex-wrap:wrap;}',

    /* Button styles (matches your site buttons) */
    '.rts-btn{background-color:#F1E3D3;color:#000000;padding:12px 28px;border-radius:6px;font-weight:600;font-size:15px;cursor:pointer;min-width:120px;display:inline-flex;align-items:center;justify-content:center;text-decoration:none;border:1px solid rgba(0,0,0,0.08);transition:all 0.3s cubic-bezier(0.4, 0, 0.2, 1);}',
    '.rts-btn:hover{background-color:#000000;color:#ffffff;}',
    '.rts-btn:active{transform:none;}',

    /* Report link look */
    '.rts-report{font-family:"Special Elite", ui-monospace, monospace;font-size:0.95rem;background:none;border:none;color:#111827;cursor:pointer;padding:10px 10px;border-radius:6px;}',
    '.rts-report:hover{background:rgba(0,0,0,0.06);}',

    /* Loading/Error */
    '.rts-status{font-family:"Inter", system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;color:#111827;font-size:0.98rem;}',
    '.rts-status--error{color:#b91c1c;font-weight:700;}',

    /* Modal */
    '.rts-modal[hidden]{display:none !important;}',
    '.rts-modal{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,0.55);z-index:50;padding:14px;}',
    '.rts-modal__panel{width:min(520px, 100%);background:#fff;border-radius:14px;padding:18px 18px 14px 18px;box-shadow:0 18px 60px rgba(0,0,0,0.35);border:1px solid rgba(0,0,0,0.08);}',
    '.rts-modal__top{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:10px;}',
    '.rts-modal__title{margin:0;font-family:"Special Elite", ui-monospace, monospace;font-size:1.15rem;color:#111827;}',
    '.rts-modal__close{appearance:none;border:1px solid rgba(0,0,0,0.10);background:#fff;border-radius:10px;width:38px;height:38px;cursor:pointer;font-size:22px;line-height:1;}',
    '.rts-modal__close:hover{background:#000;color:#fff;border-color:#000;}',
    '.rts-modal__label{display:block;font-family:"Inter", system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;font-size:0.95rem;color:#111827;margin:0 0 8px 0;}',
    '.rts-modal__textarea{width:100%;min-height:110px;padding:12px;border-radius:12px;border:1px solid rgba(0,0,0,0.12);font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace;font-size:13px;resize:vertical;}',
    '.rts-modal__actions{display:flex;align-items:center;justify-content:flex-end;gap:10px;margin-top:12px;flex-wrap:wrap;}',
    '.rts-small{font-family:"Inter", system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;font-size:0.9rem;color:#6b7280;margin:10px 0 0 0;}',

    /* Responsive */
    '@media (max-width:900px){.rts-inner{padding:44px 34px 38px 34px;}}',
    '@media (max-width:640px){.rts-top{flex-direction:column;align-items:flex-start;}.rts-intro{text-align:left;max-width:none;}.rts-inner{padding:30px 20px 26px 20px;}.rts-footer{justify-content:flex-start;}}'
  ].join('\n');

  function renderBase(shadow, opts, innerHtml) {
    shadow.innerHTML =
      '<style>' + WIDGET_CSS + '</style>' +
      '<div class="rts-embed-wrap">' +
      '  <div class="rts-paper">' +
      '    <div class="rts-inner">' +
      innerHtml +
      '    </div>' +
      '  </div>' +
      '</div>';
  }

  function renderHeader(opts) {
    var logo = opts.logoUrl
      ? '<img class="rts-brand__logo" src="' + escapeAttr(opts.logoUrl) + '" alt="' + escapeAttr(opts.brandName || DEFAULT_TITLE) + '">' 
      : '';

    return (
      '<div class="rts-top">' +
      '  <a class="rts-brand" href="' + escapeAttr(opts.siteUrl || FALLBACK_URL) + '" target="_blank" rel="noopener">' +
      '    ' + logo +
      '    <div class="rts-brand__name">' + escapeHtml(opts.brandName || DEFAULT_TITLE) + '.</div>' +
      '  </a>' +
      '  <div class="rts-intro">' +
      '    This letter was written by someone in the world that cares. It was delivered to you at random when you opened this page.' +
      '  </div>' +
      '</div>'
    );
  }

  function renderLoading(shadow, opts) {
    var html = renderHeader(opts) + '<div class="rts-status">Loading…</div>';
    renderBase(shadow, opts, html);
  }

  function renderError(shadow, opts) {
    var html =
      renderHeader(opts) +
      '<div class="rts-status rts-status--error">We couldn’t load a letter right now.</div>' +
      '<div class="rts-footer">' +
      '  <a class="rts-btn" href="' + escapeAttr(opts.siteUrl || FALLBACK_URL) + '" target="_blank" rel="noopener">Visit Reasons to Stay</a>' +
      '</div>';
    renderBase(shadow, opts, html);
  }

  function renderLetter(shadow, opts, data) {
    var contentHtml = (data && data.content_html) ? data.content_html : '';
    var letterLink = (data && data.link) ? data.link : (opts.siteUrl || FALLBACK_URL);

    // Footer includes Report + Read Another.
    var html =
      renderHeader(opts) +
      '<div class="rts-letter">' + contentHtml + '</div>' +
      '<div class="rts-footer">' +
      '  <button type="button" class="rts-report" data-action="report">Report</button>' +
      '  <button type="button" class="rts-btn" data-action="another">Read Another Letter</button>' +
      '  <a class="rts-btn" href="' + escapeAttr(letterLink) + '" target="_blank" rel="noopener">Read on site</a>' +
      '</div>' +

      // Modal
      '<div class="rts-modal" data-modal hidden>' +
      '  <div class="rts-modal__panel" role="dialog" aria-modal="true" aria-label="Report this letter">' +
      '    <div class="rts-modal__top">' +
      '      <h3 class="rts-modal__title">Report</h3>' +
      '      <button type="button" class="rts-modal__close" aria-label="Close" data-action="close">×</button>' +
      '    </div>' +
      '    <label class="rts-modal__label" for="rts-report-text">Tell us what’s wrong (optional)</label>' +
      '    <textarea id="rts-report-text" class="rts-modal__textarea" placeholder="e.g. spam, harmful content, personal data…"></textarea>' +
      '    <div class="rts-modal__actions">' +
      '      <button type="button" class="rts-btn" data-action="send-report">Send report</button>' +
      '      <button type="button" class="rts-btn" data-action="close">Cancel</button>' +
      '    </div>' +
      '    <p class="rts-small" data-report-status></p>' +
      '  </div>' +
      '</div>';

    renderBase(shadow, opts, html);
  }

  function fetchJson(url) {
    return fetch(url, {
      method: 'GET',
      credentials: 'omit',
      mode: 'cors'
    }).then(function (res) {
      if (!res.ok) throw new Error('HTTP ' + res.status);
      return res.json();
    });
  }

  function postJson(url, payload) {
    return fetch(url, {
      method: 'POST',
      credentials: 'omit',
      mode: 'cors',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload || {})
    }).then(function (res) {
      if (!res.ok) throw new Error('HTTP ' + res.status);
      return res.json();
    });
  }

  function init() {
    var host = document.getElementById('rts-widget');
    if (!host) return;

    var apiUrl = resolveApiUrl(host);
    if (!apiUrl) return;

    var reportUrl = resolveReportUrl(apiUrl);

    var shadow = host.attachShadow({ mode: 'open' });

    var opts = {
      siteUrl: host.getAttribute('data-site') || '',
      logoUrl: host.getAttribute('data-logo') || '',
      brandName: host.getAttribute('data-brand') || DEFAULT_TITLE
    };

    var seen = []; // excluded IDs
    var currentLetterId = null;

    function buildLetterUrl() {
      var u = new URL(apiUrl, window.location.href);

      // Analytics: tell the server who is embedding.
      // Server should sanitize and store domain only.
      try {
        u.searchParams.set('host', window.location.hostname || '');
      } catch (e) {}

      if (seen.length) {
        u.searchParams.set('exclude', seen.join(','));
      }
      return u.toString();
    }

    function loadLetter() {
      renderLoading(shadow, opts);

      fetchJson(buildLetterUrl())
        .then(function (data) {
          // Use API defaults if embedder didn't provide.
          opts.siteUrl = opts.siteUrl || (data && data.site_url) || '';
          opts.logoUrl = opts.logoUrl || (data && data.logo_url) || '';

          if (data && data.id) {
            currentLetterId = String(data.id);
            seen.push(currentLetterId);
            if (seen.length > 60) seen.shift();
          }

          // If API signals pool exhausted, reset and try once.
          if (data && data.reset) {
            seen = [];
            currentLetterId = null;
            return fetchJson(buildLetterUrl());
          }

          return data;
        })
        .then(function (data2) {
          if (!data2) throw new Error('Empty');
          renderLetter(shadow, opts, data2);
        })
        .catch(function () {
          renderError(shadow, opts);
        });
    }

    function openModal() {
      var modal = shadow.querySelector('[data-modal]');
      if (!modal) return;
      modal.hidden = false;
      var ta = shadow.getElementById('rts-report-text');
      if (ta) ta.focus();
      var status = shadow.querySelector('[data-report-status]');
      if (status) status.textContent = '';
    }

    function closeModal() {
      var modal = shadow.querySelector('[data-modal]');
      if (!modal) return;
      modal.hidden = true;
    }

    function sendReport() {
      var status = shadow.querySelector('[data-report-status]');
      if (status) status.textContent = 'Sending…';

      if (!reportUrl) {
        if (status) status.textContent = 'Report endpoint not available.';
        return;
      }

      var ta = shadow.getElementById('rts-report-text');
      var comment = ta ? (ta.value || '') : '';

      var payload = {
        letter_id: currentLetterId || '',
        host: (window.location.hostname || ''),
        comment: comment
      };

      postJson(reportUrl, payload)
        .then(function () {
          if (status) status.textContent = 'Thank you. Your report has been sent.';
          setTimeout(closeModal, 900);
        })
        .catch(function () {
          if (status) status.textContent = 'Sorry, the report could not be sent.';
        });
    }

    // Initial fetch
    loadLetter();

    // Event delegation inside Shadow DOM
    shadow.addEventListener('click', function (e) {
      var t = e.target;
      if (!t || !t.getAttribute) return;

      var action = t.getAttribute('data-action');
      if (!action) return;

      if (action === 'another') {
        e.preventDefault();
        loadLetter();
        return;
      }

      if (action === 'report') {
        e.preventDefault();
        openModal();
        return;
      }

      if (action === 'close') {
        e.preventDefault();
        closeModal();
        return;
      }

      if (action === 'send-report') {
        e.preventDefault();
        sendReport();
        return;
      }
    });

    // Close modal on ESC
    shadow.addEventListener('keydown', function (e) {
      if (e && e.key === 'Escape') {
        closeModal();
      }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();

/*! Reasons to Stay Widget v1.5.1 | (c) 2026 Sonny × Inkfire | MIT License */
/**
 * Reasons to Stay Embed Widget (Mini Letter)
 *
 * Renders a mini version of the site letter view as an embeddable A4-style card.
 * Uses Shadow DOM to prevent style conflicts.
 *
 * v1.5.1 Optimizations:
 * - Styling: Increased desktop padding for a premium "letter" feel (45px).
 * - Mobile: Retained compact padding via media queries.
 * - Removed "Read on site" button.
 * - Enforced backlink to production site.
 * - All v1.4 optimizations (SWR Cache, Lazy Load, etc.) included.
 */
(function () {
  'use strict';

  var DEFAULT_TITLE = 'Reasons to Stay';
  var PRODUCTION_URL = 'https://www.reasonstostay.co.uk/';
  var FALLBACK_URL = 'https://www.google.com/search?q=Reasons+to+Stay';
  // Versioned cache key to ensure clean slate on updates
  var CACHE_KEY = 'rts_widget_data_v1.5.1';

  // --- Caching Helpers ---
  function getAdaptiveTTL() {
    var hour = new Date().getHours();
    // Longer cache (15m) during low-traffic overnight hours (2am-6am), otherwise 5m
    return (hour >= 2 && hour <= 6) ? 15 * 60 * 1000 : 5 * 60 * 1000;
  }

  function getCachedData() {
    try {
      var record = JSON.parse(localStorage.getItem(CACHE_KEY));
      if (!record) return null;
      if (Date.now() > record.expiry) {
        localStorage.removeItem(CACHE_KEY);
        return null;
      }
      return record.data;
    } catch (e) {
      return null;
    }
  }

  function setCachedData(data) {
    try {
      var record = {
        data: data,
        expiry: Date.now() + getAdaptiveTTL()
      };
      localStorage.setItem(CACHE_KEY, JSON.stringify(record));
    } catch (e) {}
  }

  // --- URL Helpers ---
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

  // --- DOM Helpers ---
  function escapeHtml(str) {
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(str || ''));
    return div.innerHTML;
  }

  function escapeAttr(str) {
    return escapeHtml(str).replace(/"/g, '&quot;');
  }

  function sanitizeContent(html) {
    if (!html) return '';
    var div = document.createElement('div');
    div.innerHTML = html;
    var dangerous = div.querySelectorAll('script, style, link, meta, iframe, frame, object, embed, form');
    for (var i = 0; i < dangerous.length; i++) {
      dangerous[i].remove();
    }
    var all = div.querySelectorAll('*');
    for (var j = 0; j < all.length; j++) {
      var attrs = all[j].attributes;
      for (var k = attrs.length - 1; k >= 0; k--) {
        if (attrs[k].name.indexOf('on') === 0) {
          all[j].removeAttribute(attrs[k].name);
        }
      }
    }
    return div.innerHTML;
  }

  // --- CSS ---
  var WIDGET_CSS = [
    // font-display: optional for performance
    "@import url('https://fonts.googleapis.com/css2?family=Special+Elite&family=Inter:wght@400;600;700&display=optional');",
    ':host{display:block; width:100%;}',
    '.rts-embed-wrap{width:100%; max-width:var(--rts-max-width, 800px); margin:0 auto; padding:0; container-type: inline-size;}',
    '.rts-sr-only { position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0; }',
    
    /* Paper Wrapper */
    '.rts-paper{position:relative;}',
    '.rts-paper{background-image:radial-gradient(circle at center, rgba(255,255,255,0.95) 20%, rgba(255,255,255,0.6) 100%);background-size:cover;background-position:center;background-repeat:no-repeat;}',
    '.rts-paper{box-shadow:inset 0 0 80px rgba(160,140,100,0.15), 0 1px 2px rgba(0,0,0,0.10), 0 20px 40px -10px rgba(0,0,0,0.10);}',
    '.rts-paper{border-radius:2px;border:1px solid rgba(0,0,0,0.03);}',

    /* Tape */
    '.rts-paper:before{content:"";position:absolute;top:-10px;left:50%;transform:translateX(-50%) rotate(1deg);width:90px;height:25px;background-color:rgba(255,255,255,0.4);box-shadow:0 1px 4px rgba(0,0,0,0.1);backdrop-filter:blur(2px);border:1px solid rgba(255,255,255,0.3);z-index:10;}',

    /* Inner Layout - Increased padding for desktop comfort */
    '.rts-inner{position:relative;z-index:1;padding:45px 40px;}',
    /* Header Layout - Increased margin for breathing room */
    '.rts-top{display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:12px; margin-bottom:35px;}',

    /* Branding */
    '.rts-brand{display:flex;align-items:center;gap:10px;text-decoration:none;color:#111827; flex: 1 1 auto; min-width: 180px;}',
    '.rts-brand__logo{width:36px;height:36px;object-fit:contain;border-radius:6px;background:#fff;border:1px solid rgba(0,0,0,0.08); flex-shrink:0;}',
    '.rts-brand__name{font-family:"Special Elite", "Courier New", ui-monospace, monospace;font-size:1.2rem;line-height:1.1;color:#111827;}',

    /* Intro - Adjusted widths: 50% Desktop, 75% Tablet, 100% Mobile (via media query below) */
    '.rts-intro{font-family:"Special Elite", "Courier New", ui-monospace, monospace;text-align:right;color:#111827;font-size:0.85rem;line-height:1.45; margin-left: auto; max-width: 50%;}',

    /* Content */
    '.rts-letter{font-family:"Special Elite", "Courier New", ui-monospace, monospace;color:#111827;font-size:clamp(0.75rem, 2vw, 0.85rem);line-height:1.6; min-height: 100px;}',
    '.rts-letter[aria-busy="true"] { opacity: 0.7; }',
    '.rts-letter p{margin:0 0 14px 0;}',
    '.rts-letter p:last-child{margin-bottom:0;}',
    '.rts-letter h1,.rts-letter h2,.rts-letter h3{font-family:"Special Elite", "Courier New", ui-monospace, monospace;margin:0 0 10px 0;font-size:1rem;}',

    /* Footer & Buttons */
    '.rts-footer{display:flex;align-items:center;justify-content:flex-end;gap:8px;margin-top:32px;flex-wrap:wrap;}',
    '.rts-attribution { font-family:"Inter", sans-serif; font-size:0.7rem; color:#9ca3af; text-align:center; margin-top:24px; padding-top:16px; border-top:1px solid rgba(0,0,0,0.05); }',
    '.rts-attribution a { color:inherit; text-decoration:none; transition: color 0.2s; }',
    '.rts-attribution a:hover { text-decoration:underline; color:#6b7280; }',
    
    '.rts-btn{background-color:#F1E3D3;color:#000000;padding:10px 20px;border-radius:6px;font-weight:600;font-size:13px;cursor:pointer;min-width:auto;display:inline-flex;align-items:center;justify-content:center;text-decoration:none;border:1px solid rgba(0,0,0,0.08);transition:all 0.3s cubic-bezier(0.4, 0, 0.2, 1); white-space: nowrap;}',
    '.rts-btn:hover{background-color:#000000;color:#ffffff;}',
    '.rts-btn:focus-visible{outline: 2px solid #000; outline-offset: 2px;}',

    '.rts-report{font-family:"Special Elite", ui-monospace, monospace;font-size:0.85rem;background:none;border:none;color:#6b7280;cursor:pointer;padding:6px 8px;border-radius:6px;}',
    '.rts-report:hover{background:rgba(0,0,0,0.06);color:#111827;}',

    /* States */
    '.rts-status{font-family:"Inter", sans-serif;color:#111827;font-size:0.9rem;}',
    '.rts-status--error{color:#b91c1c;font-weight:700;}',

    /* Modal */
    '.rts-modal[hidden]{display:none !important;}',
    '.rts-modal{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,0.55);z-index:50;padding:14px;}',
    '.rts-modal__panel{width:min(520px, 100%);background:#fff;border-radius:14px;padding:18px 18px 14px 18px;box-shadow:0 18px 60px rgba(0,0,0,0.35);border:1px solid rgba(0,0,0,0.08);}',
    '.rts-modal__top{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:10px;}',
    '.rts-modal__title{margin:0;font-family:"Special Elite", ui-monospace, monospace;font-size:1.1rem;color:#111827;}',
    '.rts-modal__close{appearance:none;border:1px solid rgba(0,0,0,0.10);background:#fff;border-radius:8px;width:32px;height:32px;cursor:pointer;font-size:20px;line-height:1;}',
    '.rts-modal__close:hover{background:#000;color:#fff;border-color:#000;}',
    '.rts-modal__label{display:block;font-family:"Inter", sans-serif;font-size:0.85rem;color:#111827;margin:0 0 6px 0;}',
    '.rts-modal__textarea{width:100%;min-height:90px;padding:10px;border-radius:8px;border:1px solid rgba(0,0,0,0.12);font-family:ui-monospace,monospace;font-size:12px;resize:vertical;}',
    '.rts-modal__actions{display:flex;align-items:center;justify-content:flex-end;gap:8px;margin-top:12px;flex-wrap:wrap;}',
    '.rts-small{font-family:"Inter", sans-serif;font-size:0.85rem;color:#6b7280;margin:8px 0 0 0;}',

    /* Responsive & Reduced Motion */
    '@media (prefers-reduced-motion: reduce) {',
    '  .rts-btn, .rts-modal, .rts-report:hover, .rts-brand__logo, .rts-attribution a:hover { transition: none !important; }',
    '  .rts-paper:before { animation: none; }',
    '}',
    // Tablet Breakpoint (~900px) - Expand intro to 75%
    '@container (max-width: 900px) { .rts-intro{ max-width: 60%; padding-top: 30px; } }',
    '@media (max-width: 900px) { .rts-intro{ max-width: 75%; } }',
    
    // Original Mobile Logic (remains as fallback/override)
    '@container (max-width: 550px) { .rts-top{ flex-direction:column; align-items:stretch; gap:8px; } .rts-brand{ width:100%; justify-content: flex-start; } .rts-intro{ width:80%; text-align:right; margin-top:4px; border-top:1px dashed rgba(0,0,0,0.05); padding-top:30px; } .rts-footer{ justify-content:space-between; } .rts-btn{ flex:1; text-align:center; } }',
    /* Mobile Override: Restore tight padding for phones */
    '@media (max-width:550px){',
    '  .rts-inner{ padding:25px 20px; }',
    '  .rts-top{ flex-direction:column; align-items:stretch; gap:8px; margin-bottom:20px; }',
    '  .rts-brand{ width:100%; justify-content: flex-start; }',
    '  .rts-intro{ width:100%; text-align:right; margin-top:4px; border-top:1px dashed rgba(0,0,0,0.05); padding-top:8px; }',
    '  .rts-footer{ justify-content:space-between; margin-top:24px; }',
    '  .rts-btn{ flex:1; text-align:center; padding:8px 16px; }',
    '  .rts-attribution{ margin-top:16px; padding-top:12px; }',
    '}'
  ].join('\n');

  // --- Renderers ---
  function renderBase(shadow, opts, innerHtml) {
    var attributionHtml = '';
    if (opts.showAttribution) {
        attributionHtml = '<div class="rts-attribution">Built by <a href="https://inkfire.co.uk" target="_blank" rel="noopener">Sonny × Inkfire</a></div>';
    }
    shadow.innerHTML = '<style>' + WIDGET_CSS + '</style><div class="rts-embed-wrap" style="--rts-max-width:' + escapeAttr(opts.maxWidth) + '"><div class="rts-paper"><div class="rts-inner">' + innerHtml + attributionHtml + '</div></div></div>';
  }

  function renderHeader(opts) {
    var logo = opts.logoUrl
      ? '<img class="rts-brand__logo" src="' + escapeAttr(opts.logoUrl) + '" alt="' + escapeAttr(opts.brandName || DEFAULT_TITLE) + '" width="36" height="36" loading="lazy">' 
      : '';
    
    // Enforce production link
    var linkUrl = opts.siteUrl || PRODUCTION_URL;
    
    return '<header class="rts-top"><a class="rts-brand" href="' + escapeAttr(linkUrl) + '" target="_blank" rel="noopener">' + logo + '<div class="rts-brand__name">' + escapeHtml(opts.brandName || DEFAULT_TITLE) + '.</div></a><div class="rts-intro">This letter was written by someone in the world that cares. It was delivered to you at random when you opened this page.</div></header>';
  }

  function renderLoading(shadow, opts) {
    var html = renderHeader(opts) + '<div class="rts-status" role="status" aria-live="polite">Loading…</div>';
    renderBase(shadow, opts, html);
  }

  function renderError(shadow, opts) {
    var html = renderHeader(opts) + '<div class="rts-status rts-status--error" role="alert" aria-live="assertive">We couldn’t load a letter right now.</div><div class="rts-footer"><button type="button" class="rts-btn" data-action="retry">Try Again</button><a class="rts-btn" href="' + escapeAttr(opts.siteUrl || PRODUCTION_URL) + '" target="_blank" rel="noopener">Visit Reasons to Stay</a></div>';
    renderBase(shadow, opts, html);
  }

  function renderLetter(shadow, opts, data) {
    var contentHtml = (data && data.content_html) ? sanitizeContent(data.content_html) : '';
    // v1.5 NOTE: letterLink unused here as "Read on site" button is removed, but we keep calculation if needed later.
    // var letterLink = (data && data.link) ? data.link : (opts.siteUrl || FALLBACK_URL);

    var html =
      renderHeader(opts) +
      '<article class="rts-letter" aria-label="Random letter">' +
        '<span class="rts-sr-only" aria-live="polite">Loaded new letter</span>' +
        contentHtml + 
      '</article>' +
      '<footer class="rts-footer">' +
      '  <button type="button" class="rts-report" data-action="report">Report</button>' +
      '  <button type="button" class="rts-btn" data-action="another">Read Another</button>' +
      '</footer>' +
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
      '    <p class="rts-small" data-report-status role="status" aria-live="polite"></p>' +
      '  </div>' +
      '</div>';
    renderBase(shadow, opts, html);
  }

  // --- Network ---
  // Request deduplication
  var pendingRequests = {};

  function fetchJson(url) {
    if (pendingRequests[url]) return pendingRequests[url];

    var controller = new AbortController();
    var timeoutId = setTimeout(function() { controller.abort(); }, 8000);

    var request = fetch(url, {
      method: 'GET',
      credentials: 'omit',
      mode: 'cors',
      signal: controller.signal
    })
    .then(function (res) {
      clearTimeout(timeoutId);
      if (!res.ok) throw new Error('HTTP ' + res.status);
      return res.json();
    })
    .finally(function() {
      delete pendingRequests[url];
    });

    pendingRequests[url] = request;
    return request;
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

  // --- Initialization ---
  function init() {
    var host = document.getElementById('rts-widget');
    if (!host || host.shadowRoot) return; // Prevent double init

    // Analytics marker
    if (host.getAttribute('data-analytics') === 'true') {
        host.setAttribute('data-rts-loaded', new Date().toISOString());
        host.setAttribute('data-rts-version', '1.5.1');
    }

    var apiUrl = resolveApiUrl(host);
    if (!apiUrl) return;

    var reportUrl = resolveReportUrl(apiUrl);
    var shadow = host.attachShadow({ mode: 'open' });
    
    // Focus traps
    var lastFocusElement = null;
    var modalFocusables = null;
    var modalFirstFocusable = null;
    var modalLastFocusable = null;

    var opts = {
      siteUrl: host.getAttribute('data-site') || PRODUCTION_URL,
      logoUrl: host.getAttribute('data-logo') || '',
      brandName: host.getAttribute('data-brand') || DEFAULT_TITLE,
      maxWidth: host.getAttribute('data-max-width') || '800px',
      showAttribution: host.getAttribute('data-attribution') !== 'false'
    };

    var seen = [];
    var currentLetterId = null;

    function buildLetterUrl() {
      var u = new URL(apiUrl, window.location.href);
      try { u.searchParams.set('host', window.location.hostname || ''); } catch (e) {}
      if (seen.length) u.searchParams.set('exclude', seen.join(','));
      return u.toString();
    }

    function announceToScreenReader(message, priority) {
      var region = shadow.querySelector('.rts-aria-live');
      if (!region) {
        region = document.createElement('div');
        region.className = 'rts-sr-only rts-aria-live';
        region.setAttribute('aria-live', priority || 'polite');
        region.setAttribute('aria-atomic', 'true');
        shadow.appendChild(region);
      }
      region.textContent = message;
      setTimeout(function() { region.textContent = ''; }, 1000);
    }

    function fetchFreshInBackground() {
      fetchJson(buildLetterUrl())
        .then(function(data) {
          if (data && data.id) {
            setCachedData(data);
          }
        })
        .catch(function(err) {
          // Silent fail - user has cached content
        });
    }

    function loadLetter(retryCount, forceRefresh) {
      retryCount = typeof retryCount !== 'undefined' ? retryCount : 0;
      
      // Try Cache First (Stale-While-Revalidate strategy)
      if (!forceRefresh && retryCount === 0) {
        var cached = getCachedData();
        if (cached) {
          // Render cached immediately
          opts.siteUrl = opts.siteUrl || cached.site_url || '';
          opts.logoUrl = opts.logoUrl || cached.logo_url || '';
          renderLetter(shadow, opts, cached);
          
          if(cached.id) {
            currentLetterId = String(cached.id);
            seen.push(currentLetterId);
          }
          
          // Background refresh
          fetchFreshInBackground();
          return;
        }
      }

      if(retryCount === 0) {
        renderLoading(shadow, opts);
        announceToScreenReader('Loading new letter', 'polite');
      }

      // Performance Budget Monitoring
      var startTime = performance.now();

      fetchJson(buildLetterUrl())
        .then(function (data) {
          // Measure load time
          var loadTime = performance.now() - startTime;
          if (loadTime > 1000) {
            console.warn('RTS Widget: Slow load detected', loadTime.toFixed(0) + 'ms');
          }

          opts.siteUrl = opts.siteUrl || (data && data.site_url) || '';
          opts.logoUrl = opts.logoUrl || (data && data.logo_url) || '';

          if (data && data.id) {
            currentLetterId = String(data.id);
            seen.push(currentLetterId);
            if (seen.length > 60) seen.shift();
          }

          if (data && data.reset) {
            seen = [];
            currentLetterId = null;
            return fetchJson(buildLetterUrl());
          }

          // Update Cache
          setCachedData(data);
          return data;
        })
        .then(function (data2) {
          if (!data2) throw new Error('Empty');
          renderLetter(shadow, opts, data2);
        })
        .catch(function (err) {
          console.warn('RTS Widget Load Error:', err);
          if (retryCount < 2) {
            setTimeout(function() { loadLetter(retryCount + 1, forceRefresh); }, 1000 * (retryCount + 1));
          } else {
            renderError(shadow, opts);
            announceToScreenReader('Failed to load letter', 'assertive');
          }
        });
    }

    // Modal & Action Logic
    function setupFocusTrap(modal) {
        modalFocusables = modal.querySelectorAll('button, [href], textarea, select, input');
        if (modalFocusables.length > 0) {
            modalFirstFocusable = modalFocusables[0];
            modalLastFocusable = modalFocusables[modalFocusables.length - 1];
        }
    }

    function openModal() {
      var modal = shadow.querySelector('[data-modal]');
      if (!modal) return;
      lastFocusElement = shadow.activeElement;
      modal.hidden = false;
      setupFocusTrap(modal);
      var ta = shadow.getElementById('rts-report-text');
      if (ta) ta.focus();
      else if (modalFirstFocusable) modalFirstFocusable.focus();
      var status = shadow.querySelector('[data-report-status]');
      if (status) status.textContent = '';
    }

    function closeModal() {
      var modal = shadow.querySelector('[data-modal]');
      if (!modal) return;
      modal.hidden = true;
      if (lastFocusElement) {
          lastFocusElement.focus();
          lastFocusElement = null;
      }
    }

    function sendReport() {
      var status = shadow.querySelector('[data-report-status]');
      if (status) status.textContent = 'Sending…';
      if (!reportUrl) { if (status) status.textContent = 'Report endpoint not available.'; return; }
      
      var ta = shadow.getElementById('rts-report-text');
      var comment = ta ? (ta.value || '') : '';
      
      postJson(reportUrl, {
        letter_id: currentLetterId || '',
        host: (window.location.hostname || ''),
        comment: comment
      })
      .then(function () {
        if (status) status.textContent = 'Thank you. Your report has been sent.';
        setTimeout(closeModal, 1500);
      })
      .catch(function () {
        if (status) status.textContent = 'Sorry, the report could not be sent.';
      });
    }

    // Interaction Handler
    shadow.addEventListener('click', function (e) {
      var t = e.target;
      if (!t || !t.getAttribute) return;
      var action = t.getAttribute('data-action');
      if (!action) return;

      if (action === 'another' || action === 'retry') {
        e.preventDefault();
        // Button loading state
        if (t.classList.contains('rts-btn')) {
            var originalText = t.textContent;
            t.textContent = 'Loading...';
            t.setAttribute('aria-busy', 'true');
            // Force refresh bypassing cache
            loadLetter(0, true); 
            // Reset UI hook (render will replace it anyway)
            setTimeout(function() {
                t.textContent = originalText;
                t.removeAttribute('aria-busy');
            }, 2000);
        } else {
            loadLetter(0, true);
        }
        return;
      }

      if (action === 'report') { e.preventDefault(); openModal(); return; }
      if (action === 'close') { e.preventDefault(); closeModal(); return; }
      if (action === 'send-report') { e.preventDefault(); sendReport(); return; }
    });

    // Keyboard Handler
    shadow.addEventListener('keydown', function (e) {
      if (!e) return;
      if (e.key === 'Escape') { closeModal(); return; }
      var modal = shadow.querySelector('[data-modal]');
      if (modal && !modal.hidden && e.key === 'Tab') {
          if (!modalFocusables || modalFocusables.length === 0) return;
          if (e.shiftKey) { 
              if (shadow.activeElement === modalFirstFocusable) { e.preventDefault(); modalLastFocusable.focus(); }
          } else { 
              if (shadow.activeElement === modalLastFocusable) { e.preventDefault(); modalFirstFocusable.focus(); }
          }
      }
    });

    // Lazy Load Initialization
    if ('IntersectionObserver' in window) {
      var observer = new IntersectionObserver(function(entries) {
        if (entries[0].isIntersecting) {
          loadLetter();
          observer.disconnect();
        }
      }, { rootMargin: '100px' });
      observer.observe(host);
    } else {
      loadLetter(); // Fallback for older browsers
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
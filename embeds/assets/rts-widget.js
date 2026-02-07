/**
 * RTS Syndication Widget
 *
 * Drop-in embed widget for partner sites.
 * Uses Shadow DOM so the host page's CSS cannot break the widget.
 *
 * Usage:
 *   <div id="rts-widget" data-api="https://example.com/wp-json/rts/v1/embed/random"></div>
 *   <script src="https://example.com/wp-content/themes/reasonstostay/embeds/assets/rts-widget.js"></script>
 */
(function () {
  'use strict';

  var FALLBACK_URL = 'https://www.google.com/search?q=ReasonstoStay.com';

  /**
   * Inline styles (scoped inside Shadow DOM — cannot leak to host page).
   */
  var WIDGET_CSS = [
    ':host { display:block; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif; }',
    '.rts-embed { max-width:480px; margin:0 auto; padding:24px; border-radius:16px; background:#fff; box-shadow:0 4px 24px rgba(0,0,0,0.10); border:1px solid #e5e7eb; }',
    '.rts-embed__quote { font-size:1.05rem; line-height:1.65; color:#1a1a1a; margin:0 0 12px 0; }',
    '.rts-embed__author { font-size:0.9rem; color:#6b7280; margin:0 0 16px 0; }',
    '.rts-embed__link { display:inline-block; padding:10px 20px; border-radius:10px; background:#111827; color:#fff; text-decoration:none; font-weight:600; font-size:0.95rem; transition:transform .15s ease,box-shadow .15s ease; }',
    '.rts-embed__link:hover { transform:translateY(-1px); box-shadow:0 6px 16px rgba(0,0,0,0.14); }',
    '.rts-embed__fallback { text-align:center; }',
    '.rts-embed__fallback a { color:#111827; font-weight:600; text-decoration:underline; }'
  ].join('\n');

  /**
   * Render letter data into the shadow root.
   */
  function renderLetter(shadow, data) {
    shadow.innerHTML = '<style>' + WIDGET_CSS + '</style>'
      + '<div class="rts-embed">'
      + '  <p class="rts-embed__quote">&ldquo;' + escapeHtml(data.content) + '&rdquo;</p>'
      + '  <p class="rts-embed__author">&mdash; ' + escapeHtml(data.author) + '</p>'
      + '  <a class="rts-embed__link" href="' + escapeAttr(data.link) + '" target="_blank" rel="noopener">Read more letters</a>'
      + '</div>';
  }

  /**
   * Render the graceful fallback when the API is unreachable.
   */
  function renderFallback(shadow) {
    shadow.innerHTML = '<style>' + WIDGET_CSS + '</style>'
      + '<div class="rts-embed rts-embed__fallback">'
      + '  <p class="rts-embed__quote">Everyone has a reason to stay.</p>'
      + '  <a href="' + FALLBACK_URL + '" target="_blank" rel="noopener">Read more at ReasonstoStay.com</a>'
      + '</div>';
  }

  /**
   * Basic HTML escaping (prevents XSS from API data).
   */
  function escapeHtml(str) {
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(str || ''));
    return div.innerHTML;
  }

  function escapeAttr(str) {
    return escapeHtml(str).replace(/"/g, '&quot;');
  }

  /**
   * Boot the widget.
   */
  function init() {
    var host = document.getElementById('rts-widget');
    if (!host) return;

    var apiUrl = host.getAttribute('data-api');
    if (!apiUrl) {
      // Try auto-detect from script src
      var scripts = document.querySelectorAll('script[src]');
      for (var i = 0; i < scripts.length; i++) {
        var src = scripts[i].getAttribute('src') || '';
        if (src.indexOf('rts-widget.js') !== -1) {
          var base = src.replace(/\/embeds\/assets\/rts-widget\.js.*$/, '');
          apiUrl = base + '/wp-json/rts/v1/embed/random';
          break;
        }
      }
    }

    if (!apiUrl) {
      return;
    }

    // Attach Shadow DOM so the host site's CSS cannot interfere.
    var shadow = host.attachShadow({ mode: 'open' });

    // Show a minimal loading state
    shadow.innerHTML = '<style>' + WIDGET_CSS + '</style><div class="rts-embed"><p class="rts-embed__quote">Loading&hellip;</p></div>';

    // Fetch a random letter
    fetch(apiUrl)
      .then(function (res) {
        if (!res.ok) throw new Error('HTTP ' + res.status);
        return res.json();
      })
      .then(function (data) {
        if (data && data.content) {
          renderLetter(shadow, data);
        } else {
          renderFallback(shadow);
        }
      })
      .catch(function () {
        renderFallback(shadow);
      });
  }

  // Run on DOMContentLoaded or immediately if already loaded.
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();

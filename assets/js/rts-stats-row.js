/* global RTS_STATS_ROW */
(function () {
  'use strict';

  function toInt(v) {
    var cleaned = String(v === null || v === undefined ? '' : v).replace(/[^0-9\-]/g, '');
    var n = parseInt(cleaned, 10);
    return isNaN(n) ? 0 : n;
  }

  function formatNumber(v) {
    try {
      var n = toInt(String(v).replace(/[^0-9\-]/g, ''));
      return n.toLocaleString('en-GB');
    } catch (e) {
      return String(v);
    }
  }

  function setText(el, txt) {
    if (!el) return;
    el.textContent = String(txt);
  }

  function buildUrl(base, params) {
    var url;
    try {
      url = new URL(base);
    } catch (e) {
      url = new URL(base, window.location.origin);
    }

    Object.keys(params).forEach(function (k) {
      if (params[k] === null || params[k] === undefined || params[k] === '') return;
      url.searchParams.set(k, params[k]);
    });

    // cache buster
    url.searchParams.set('_ts', String(Date.now()));
    return url.toString();
  }

  function updateRow(row, data) {
    if (!row || !data) return;

    var deliveredEl = row.querySelector('[data-stat="letters_delivered"]');
    var feelbetterEl = row.querySelector('[data-stat="feel_better_percent"]');
    var submittedEl = row.querySelector('[data-stat="letters_submitted"]');

    if (typeof data.letters_delivered !== 'undefined') {
      setText(deliveredEl, formatNumber(data.letters_delivered));
    }

    if (typeof data.feel_better_percent !== 'undefined') {
      setText(feelbetterEl, data.feel_better_percent + '%');
    }

    if (typeof data.letters_submitted !== 'undefined') {
      setText(submittedEl, formatNumber(data.letters_submitted));
    }
  }

  function fetchWithTimeout(url, timeoutMs) {
    var controller = new AbortController();
    var id = window.setTimeout(function () {
      controller.abort();
    }, timeoutMs);

    return fetch(url, {
      method: 'GET',
      credentials: 'same-origin',
      cache: 'no-store',
      headers: { 'Accept': 'application/json', 'Cache-Control': 'no-cache' },
      signal: controller.signal
    }).finally(function () {
      window.clearTimeout(id);
    });
  }

  function loadRow(row) {
    if (!row) return;

    var baseUrl = (window.RTS_STATS_ROW && RTS_STATS_ROW.restUrl) ? RTS_STATS_ROW.restUrl : null;
    if (!baseUrl) return;

    var params = {
      offset_delivered: toInt(row.getAttribute('data-offset-delivered')),
      offset_feelbetter: toInt(row.getAttribute('data-offset-feelbetter')),
      offset_submitted: toInt(row.getAttribute('data-offset-submitted'))
    };

    row.setAttribute('aria-busy', 'true');

    var timeout = (window.RTS_STATS_ROW && RTS_STATS_ROW.timeout) ? toInt(RTS_STATS_ROW.timeout) : 8000;
    var url = buildUrl(baseUrl, params);

    fetchWithTimeout(url, timeout)
      .then(function (res) {
        if (!res.ok) throw new Error('HTTP ' + res.status);
        return res.json();
      })
      .then(function (json) {
        if (!json) return;
        // Accept both plain object responses and WP-style { success, data } responses.
        var payload = (json && json.success && json.data) ? json.data : json;
        if (payload && typeof payload === 'object') {
          updateRow(row, payload);
        }
      })
      .catch(function () {
        // Silent fail: keep server-rendered defaults.
      })
      .finally(function () {
        row.setAttribute('aria-busy', 'false');
      });
  }

  
  function incrementDeliveredLocal() {
    var rows = document.querySelectorAll('.rts-stats-row');
    rows.forEach(function(row){
      var deliveredEl = row.querySelector('[data-stat="letters_delivered"]');
      if (!deliveredEl) return;
      var current = toInt(deliveredEl.textContent);
      if (current < 0) return;
      setText(deliveredEl, formatNumber(current + 1));
    });
  }

  // If the letter viewer records a view, bump the displayed "delivered" count immediately.
  // We still refresh from REST periodically for accuracy.
  window.addEventListener('rts:letterViewed', function(){
    incrementDeliveredLocal();
  });

function init() {
    var rows = document.querySelectorAll('.rts-stats-row');
    if (!rows || !rows.length) return;
    rows.forEach(loadRow);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();

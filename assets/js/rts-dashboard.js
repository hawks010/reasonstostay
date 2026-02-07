/**
 * RTS Dashboard Real-time Updates
 * version: 2.43.1
 */
(function($) {
  'use strict';

  // If this file gets enqueued twice (even with a different ?ver=), don't start two pollers.
  if (window.__RTS_DASHBOARD_RT_LOADED) { return; }
  window.__RTS_DASHBOARD_RT_LOADED = true;

  // ---------------------------------------------------------
  // Capabilities (graceful degradation)
  // ---------------------------------------------------------
  const CAPS = {
    hasRAF: typeof window.requestAnimationFrame === 'function',
    hasPromise: typeof window.Promise === 'function',
    hasIntersectionObserver: typeof window.IntersectionObserver === 'function',
    hasPerformance: !!(window.performance && typeof window.performance.now === 'function'),
    hasConnectionAPI: !!(navigator && navigator.connection)
  };

  // ---------------------------------------------------------
  // Polling config (adaptive + network aware)
  // ---------------------------------------------------------
  const POLL_CONFIG = {
    NORMAL: 15000,
    FAST: 5000,
    SLOW: 30000,
    MAX: 120000,
    MIN: 3000
  };

  let currentPollMs = POLL_CONFIG.NORMAL;
  let inFlight = false;
  let pollingTimeout = null;
  let isPolling = false;
  let isTabVisible = true;
  let lastUpdateHadActivity = false;
  let consecutiveErrors = 0;

  // Performance monitoring (lightweight)
  const performanceMetrics = {
    pollTimes: [],
    logPollTime(duration) {
      this.pollTimes.push(duration);
      if (this.pollTimes.length > 20) this.pollTimes.shift();
      if (duration > 3000 && window.console && console.warn) {
        console.warn('[RTS Dashboard] Slow poll:', Math.round(duration) + 'ms');
      }
    }
  };

  // ---------------------------------------------------------
  // DOM batching (microtask -> RAF)
  // ---------------------------------------------------------
  let domUpdateQueue = [];
  let domUpdateScheduled = false;

  function queueDOMUpdate(fn) {
    domUpdateQueue.push(fn);
    if (domUpdateScheduled) return;
    domUpdateScheduled = true;

    const schedule = CAPS.hasPromise ? Promise.resolve().then.bind(Promise.resolve()) : function(cb){ setTimeout(cb, 0); };
    schedule(processDOMQueue);
  }

  function processDOMQueue() {
    if (!domUpdateQueue.length) {
      domUpdateScheduled = false;
      return;
    }

    const run = function() {
      const queue = domUpdateQueue;
      domUpdateQueue = [];
      domUpdateScheduled = false;

      for (let i = 0; i < queue.length; i++) {
        try { queue[i](); } catch (e) {
          if (window.console && console.error) {
            console.error('[RTS Dashboard] DOM update error:', e);
          }
        }
      }

      // If more came in while we ran, schedule again.
      if (domUpdateQueue.length) {
        queueDOMUpdate(function(){});
      }
    };

    if (CAPS.hasRAF) {
      requestAnimationFrame(run);
    } else {
      setTimeout(run, 0);
    }
  }

  // ---------------------------------------------------------
  // Connection quality helpers
  // ---------------------------------------------------------
  let connectionQuality = 'unknown';

  function detectConnectionQuality() {
    if (!CAPS.hasConnectionAPI) {
      connectionQuality = 'unknown';
      return;
    }
    const conn = navigator.connection;
    const effectiveType = conn.effectiveType || '4g';
    const rtt = conn.rtt || 100;

    if (effectiveType === 'slow-2g' || rtt > 1000) connectionQuality = 'poor';
    else if (effectiveType === '2g' || rtt > 300) connectionQuality = 'slow';
    else connectionQuality = 'good';
  }

  function getAdjustedPollInterval(baseInterval) {
    detectConnectionQuality();
    if (connectionQuality === 'poor') return Math.max(baseInterval * 3, 30000);
    if (connectionQuality === 'slow') return Math.max(baseInterval * 2, 20000);
    return baseInterval;
  }

  // ---------------------------------------------------------
  // Init + visibility/lazy polling
  // ---------------------------------------------------------
  function initDashboard() {
    if (!($('.rts-dashboard').length || $('.rts-letters-analytics').length)) return;

    // Visibility API
    document.addEventListener('visibilitychange', handleVisibilityChange);

    // Lazy polling (only when in view) if supported; otherwise start immediately.
    if (CAPS.hasIntersectionObserver) {
      setupLazyPolling();
    } else {
      startPolling();
    }

    setupEventListeners();
    updateStatus(); // initial run

    // Clean up when leaving page
    $(window).on('beforeunload', cleanupDashboard);
  }

  function handleVisibilityChange() {
    isTabVisible = !document.hidden;
    if (isTabVisible && isPolling) {
      scheduleNextPoll(true);
    }
  }

  function setupLazyPolling() {
    const dashboardEl = document.querySelector('.rts-dashboard, .rts-letters-analytics');
    if (!dashboardEl) {
      startPolling();
      return;
    }

    const observer = new IntersectionObserver((entries) => {
      entries.forEach(entry => {
        if (entry.isIntersecting) {
          startPolling();
        } else {
          // If tab isn't visible and dashboard isn't in view, stop polling.
          if (!isTabVisible) stopPolling();
        }
      });
    }, { threshold: 0.1 });

    observer.observe(dashboardEl);
  }

  // ---------------------------------------------------------
  // Polling control
  // ---------------------------------------------------------
  function startPolling() {
    ensureDiagnosticsPanel();
    if (isPolling) return;
    isPolling = true;
    currentPollMs = POLL_CONFIG.NORMAL;
    consecutiveErrors = 0;
    lastUpdateHadActivity = false;
    scheduleNextPoll(true);
    $('#rts-scan-status').addClass('polling-active');
  }

  function stopPolling() {
    if (!isPolling) return;
    isPolling = false;
    if (pollingTimeout) {
      clearTimeout(pollingTimeout);
      pollingTimeout = null;
    }
    $('#rts-scan-status').removeClass('polling-active');
  }

  function scheduleNextPoll(immediate) {
    if (!isPolling) return;
    if (pollingTimeout) {
      clearTimeout(pollingTimeout);
      pollingTimeout = null;
    }

    const adjusted = getAdjustedPollInterval(currentPollMs);
    const wait = immediate ? 0 : Math.max(POLL_CONFIG.MIN, adjusted);

    pollingTimeout = setTimeout(function() {
      updateStatus();
    }, wait);
  }

  // ---------------------------------------------------------
  // REST poll (batched DOM updates + adaptive intervals)
  // ---------------------------------------------------------
  function updateStatus() {
    if (!isPolling) return;

    if (!isTabVisible) {
      scheduleNextPoll(false);
      return;
    }

    // Avoid overlapping requests
    if (inFlight) {
      scheduleNextPoll(false);
      return;
    }
    inFlight = true;

    const t0 = CAPS.hasPerformance ? performance.now() : Date.now();
    const timestamp = Date.now();

    $.ajax({
      url: rtsDashboard.resturl + 'processing-status',
      method: 'GET',
      cache: false,
      data: { _t: timestamp },
      timeout: 8000,
      beforeSend: function(xhr) {
        xhr.setRequestHeader('X-WP-Nonce', rtsDashboard.nonce);
      },
      success: function(data) {
        inFlight = false;
        consecutiveErrors = 0;

        // Determine activity and tune polling
        let hasActivity = false;
        if (data && data.queue) {
          const q = data.queue;
          const pending = Number(q.pending_letter_jobs || 0);
          const importing = (data.import && data.import.status && data.import.status !== 'idle');
          hasActivity = pending > 0 || !!importing;

          if (hasActivity && !lastUpdateHadActivity) currentPollMs = POLL_CONFIG.FAST;
          else if (!hasActivity && lastUpdateHadActivity) currentPollMs = POLL_CONFIG.NORMAL;
          else if (!hasActivity) currentPollMs = Math.max(POLL_CONFIG.SLOW, currentPollMs);

          lastUpdateHadActivity = hasActivity;

          // Batch DOM updates
          queueDOMUpdate(function(){
            updateQueueStatus(q);
            updateImportStatus(data.import);
            updateScanStatus(q);
          });
        }

        if (data && data.diag) {
          // Throttle diag updates (roughly every 30 seconds)
          if (timestamp % 30000 < POLL_CONFIG.NORMAL) {
            queueDOMUpdate(function(){ updateDiagnostics(data.diag); });
          }
        }

        const t1 = CAPS.hasPerformance ? performance.now() : Date.now();
        performanceMetrics.logPollTime(t1 - t0);

        scheduleNextPoll(false);
      },
      error: function(xhr) {
        inFlight = false;
        consecutiveErrors++;

        // Smart backoff with jitter to prevent synchronized retries
        let backoffFactor = 1.5;

        if (xhr && xhr.status === 429) {
          const retryAfter = parseInt(xhr.getResponseHeader('Retry-After') || '0', 10);
          if (retryAfter && retryAfter > 0) {
            currentPollMs = Math.min(POLL_CONFIG.MAX, retryAfter * 1000);
          } else {
            backoffFactor = 1.8;
          }
        } else if (xhr && xhr.status >= 500) {
          backoffFactor = 2.0;
        }

        const jitter = 0.8 + Math.random() * 0.4; // 0.8 -> 1.2
        currentPollMs = Math.min(
          POLL_CONFIG.MAX,
          Math.max(
            POLL_CONFIG.MIN,
            Math.round(currentPollMs * backoffFactor * jitter)
          )
        );

        if (consecutiveErrors > 5) {
          showErrorStatus('Too many errors - pausing updates');
          setTimeout(function(){
            consecutiveErrors = 0;
            scheduleNextPoll(true);
          }, 60000);
        } else {
          showErrorStatus();
          scheduleNextPoll(false);
        }
      }
    });
  }

  // ---------------------------------------------------------
  // Change-only DOM helpers
  // ---------------------------------------------------------
  function setTextIfChanged($el, text) {
    if (!$el || !$el.length) return;
    const next = String(text);
    if ($el.text() !== next) $el.text(next);
  }

  function setHtmlIfChanged($el, html) {
    if (!$el || !$el.length) return;
    const next = String(html);
    if ($el.html() !== next) $el.html(next);
  }

  // Update queue status DOM elements
  function updateQueueStatus(queue) {
    queueDOMUpdate(function(){
      const queuedLetters = Number(queue && queue.pending_letter_jobs ? queue.pending_letter_jobs : 0);

      setTextIfChanged($('#rts-queued-count'), queuedLetters > 0 ? (queuedLetters + ' letter(s)') : '0');

      if (queuedLetters > 0) {
        setHtmlIfChanged(
          $('#rts-active-scan'),
          '<span class="status-processing" style="color:#2271b1; font-weight:600;">Processing ' + queuedLetters + ' items...</span>'
        );
      } else {
        setHtmlIfChanged(
          $('#rts-active-scan'),
          '<span class="status-good" style="color:#00a32a;">Idle (Ready)</span>'
        );
      }
    });
  }

  // Update import status DOM elements
  function updateImportStatus(importData) {
    queueDOMUpdate(function(){
      if (!importData || !importData.status || importData.status === 'idle') {
        setTextIfChanged($('#rts-import-progress-text'), 'No active import');
        $('#rts-import-progress-bar .rts-progress-fill').css('width', '0%');
        return;
      }

      const total = Number(importData.total || 0);
      const processed = Number(importData.processed || 0);
      const errors = Number(importData.errors || 0);
      const pct = total > 0 ? Math.round((processed / total) * 100) : 0;

      $('#rts-import-progress-bar .rts-progress-fill').css('width', pct + '%');

      setTextIfChanged(
        $('#rts-import-progress-text'),
        processed + ' of ' + total + ' processed (' + pct + '%)' + (errors > 0 ? ' - ' + errors + ' errors' : '')
      );

      const $badge = $('.rts-progress-status').first();
      if ($badge && $badge.length) setTextIfChanged($badge, importData.status);
    });
  }

  // Update scan status badge
  function updateScanStatus(queue) {
    queueDOMUpdate(function(){
      const queuedLetters = Number(queue && queue.pending_letter_jobs ? queue.pending_letter_jobs : 0);
      const $statusBadge = $('#rts-scan-status');

      if (queuedLetters > 0) {
        setTextIfChanged($statusBadge, 'Processing');
        $statusBadge.css({ background: '#f0f6ff', color: '#2271b1' });

        setTextIfChanged($('#rts-scan-progress-status'), 'Active');

        const $fill = $('#rts-scan-progress-bar .rts-progress-fill');
        // Nudge width as a visual heartbeat (no true total)
        try {
          const w = $fill && $fill.length ? $fill[0].style.width : '';
          const current = parseInt(w || '0', 10) || 0;
          if (current < 90) $fill.css('width', (current + 10) + '%');
        } catch(e) {}

        setTextIfChanged($('#rts-scan-progress-text'), 'Engine is analyzing content...');
      } else {
        setTextIfChanged($statusBadge, 'Idle');
        $statusBadge.css({ background: '#f0f0f1', color: '#646970' });

        setTextIfChanged($('#rts-scan-progress-status'), 'Idle');
        setTextIfChanged($('#rts-scan-progress-text'), 'No active scan');
        $('#rts-scan-progress-bar .rts-progress-fill').css('width', '0%');
      }
    });
  }

  // Show error state in DOM
  function showErrorStatus(msg) {
    queueDOMUpdate(function(){
      $('#rts-scan-status')
        .text(msg || 'Connection Error')
        .css({ background: '#fcf0f1', color: '#d63638' });

      setHtmlIfChanged($('#rts-active-scan'), '<span style="color:#d63638;">API Connection Failed</span>');
    });
  }

  // ---------------------------------------------------------
  // Request dedupe + priority
  // ---------------------------------------------------------
  const pendingRequests = new Map();
  const requestPriorities = {
    rts_start_scan: 'high',
    rts_process_single: 'normal',
    rts_approve_letter: 'high',
    rts_bulk_approve: 'normal',
    rts_cancel_import: 'high'
  };

  function cleanupOldPendingRequests() {
    const now = Date.now();
    for (const [key, req] of pendingRequests.entries()) {
      if (!req || !req.timestamp) {
        pendingRequests.delete(key);
        continue;
      }
      if (now - req.timestamp > 30000) {
        pendingRequests.delete(key);
      }
    }
  }

  function debouncedAjax(key, ajaxOptions, priority) {
    priority = priority || 'normal';

    if (pendingRequests.has(key)) {
      const existing = pendingRequests.get(key);
      // Don't abort high-priority requests for lower ones
      if (priority === 'low' && existing && existing.priority === 'high') {
        return existing.xhr;
      }
      try { existing.xhr.abort(); } catch(e) {}
    }

    const xhr = $.ajax(ajaxOptions);
    const request = { xhr: xhr, priority: priority, timestamp: Date.now() };

    cleanupOldPendingRequests();
    pendingRequests.set(key, request);

    xhr.always(function(){
      const current = pendingRequests.get(key);
      if (current === request) pendingRequests.delete(key);
    });

    return xhr;
  }

  // ---------------------------------------------------------
  // Event delegation
  // ---------------------------------------------------------
  function setupEventListeners() {
    $(document)
      .on('click', '#rts-scan-inbox-btn, #rts-rescan-quarantine-btn', function(e) {
        e.preventDefault();
        const type = ($(this).attr('id') === 'rts-scan-inbox-btn') ? 'inbox' : 'quarantine';
        startScan(type);
      })
      .on('click', '#rts-refresh-status-btn', function(e) {
        e.preventDefault();
        const $btn = $(this);
        $btn.prop('disabled', true);
        updateStatus();
        setTimeout(() => $btn.prop('disabled', false), 1000);
      })
      .on('click', '.rts-manual-process-btn', function(e) {
        e.preventDefault();
        const postId = $(this).data('post-id');
        processSingleLetter(postId, $(this));
      })
      .on('click', '.rts-approve-btn', function(e) {
        e.preventDefault();
        const postId = $(this).data('post-id');
        if (postId) approveSingleLetter(postId, $(this));
      })
      .on('click', '.rts-bulk-approve-btn', function(e) {
        e.preventDefault();
        const ids = [];
        $('.rts-select-letter:checked').each(function(){
          const v = parseInt($(this).val(), 10);
          if (v) ids.push(v);
        });
        if (!ids.length) {
          showToast('warning', 'Nothing selected', 'Select one or more letters first.');
          return;
        }
        bulkApproveLetters(ids, $(this));
      })
      .on('click', '#rts-cancel-import-btn', function(e) {
        e.preventDefault();
        if (!confirm('Are you sure you want to cancel the current import? This cannot be undone.')) return;
        cancelImport($(this));
      })
      .on('click', '.rts-toast-close', function() {
        const $toast = $(this).closest('.rts-toast');
        $toast.addClass('fade-out');
        setTimeout(() => $toast.remove(), 300);
      });
  }

  // ---------------------------------------------------------
  // Actions
  // ---------------------------------------------------------
  function cancelImport($btn) {
    $btn.prop('disabled', true);

    debouncedAjax('cancel-import', {
      url: rtsDashboard.ajaxurl,
      method: 'POST',
      dataType: 'json',
      data: {
        action: 'rts_cancel_import',
        nonce: rtsDashboard.dashboard_nonce
      }
    }, requestPriorities.rts_cancel_import)
    .done(function(response){
      if (response && response.success) {
        showToast('success', 'Import Canceled', response.data || 'Import has been canceled.');
        $btn.remove();
        updateStatus();
      } else {
        showToast('error', 'Error', (response && response.data) ? response.data : 'Could not cancel import.');
        $btn.prop('disabled', false);
      }
    })
    .fail(function(xhr){
      if (xhr && xhr.statusText === 'abort') return;
      showToast('error', 'Error', 'Failed to cancel import.');
      $btn.prop('disabled', false);
    });
  }

  function startScan(type) {
    const $btn = $('#' + (type === 'inbox' ? 'rts-scan-inbox-btn' : 'rts-rescan-quarantine-btn'));
    const originalText = $btn.find('.btn-text').text();

    $btn.prop('disabled', true);
    $btn.find('.btn-text').text('Starting...');
    $btn.find('.spinner').css('display', 'inline-block').addClass('is-active');

    const key = 'scan-' + type;

    debouncedAjax(key, {
      url: rtsDashboard.ajaxurl,
      method: 'POST',
      data: {
        action: 'rts_start_scan',
        scan_type: type,
        nonce: rtsDashboard.dashboard_nonce
      }
    }, requestPriorities.rts_start_scan)
    .done(function(response){
      if (response && response.success) {
        const msg = (response.data && (response.data.message || response.data.queued || response.data)) || 'Scan started';
        showToast('success', 'Scan Started', msg);
        setTimeout(updateStatus, 1000);
      } else {
        showToast('error', 'Error', (response && response.data) ? response.data : 'Could not start scan');
      }
    })
    .fail(function(xhr){
      if (xhr && xhr.statusText === 'abort') return;
      let detail = '';
      try {
        const t = (xhr && xhr.responseText) ? String(xhr.responseText) : '';
        if (t) detail = ' (' + t.substring(0, 160).replace(/\s+/g,' ').trim() + ')';
      } catch(e) {}
      showToast('error', 'Error', 'Network request failed.' + detail);
    })
    .always(function(){
      setTimeout(() => {
        $btn.prop('disabled', false);
        $btn.find('.btn-text').text(originalText);
        $btn.find('.spinner').hide().removeClass('is-active');
      }, 2000);
    });
  }

  function processSingleLetter(postId, $btn) {
    const originalText = $btn.text();
    $btn.prop('disabled', true).text('...');

    debouncedAjax('process-' + postId, {
      url: rtsDashboard.ajaxurl,
      method: 'POST',
      data: {
        action: 'rts_process_single',
        post_id: postId,
        nonce: rtsDashboard.dashboard_nonce
      }
    }, requestPriorities.rts_process_single)
    .done(function(response){
      if (response && response.success) {
        showToast('success', 'Queued', 'Letter #' + postId + ' queued for analysis.');
        setTimeout(updateStatus, 1000);
        setTimeout(() => location.reload(), 2500);
      } else {
        showToast('error', 'Error', (response && response.data) ? response.data : 'Could not queue letter');
        $btn.prop('disabled', false).text(originalText);
      }
    })
    .fail(function(xhr){
      if (xhr && xhr.statusText === 'abort') return;
      showToast('error', 'Error', 'Network failed');
      $btn.prop('disabled', false).text(originalText);
    });
  }

  function approveSingleLetter(postId, $btn) {
    const originalText = $btn.text();
    $btn.prop('disabled', true).text('...');

    debouncedAjax('approve-' + postId, {
      url: rtsDashboard.ajaxurl,
      method: 'POST',
      data: {
        action: 'rts_approve_letter',
        post_id: postId,
        nonce: rtsDashboard.dashboard_nonce
      }
    }, requestPriorities.rts_approve_letter)
    .done(function(response){
      if (response && response.success) {
        showToast('success', 'Approved', 'Letter #' + postId + ' approved and queued.');
        setTimeout(updateStatus, 1000);
        setTimeout(() => location.reload(), 1800);
      } else {
        showToast('error', 'Error', (response && response.data) ? response.data : 'Approval failed');
        $btn.prop('disabled', false).text(originalText);
      }
    })
    .fail(function(xhr){
      if (xhr && xhr.statusText === 'abort') return;
      showToast('error', 'Error', 'Network failed');
      $btn.prop('disabled', false).text(originalText);
    });
  }

  function bulkApproveLetters(ids, $btn) {
    if (!ids || !ids.length) {
      showToast('info', 'No selection', 'Select one or more letters first.');
      return;
    }

    const originalText = $btn.text();
    $btn.prop('disabled', true).text('Approving...');

    debouncedAjax('bulk-approve', {
      url: rtsDashboard.ajaxurl,
      method: 'POST',
      data: {
        action: 'rts_bulk_approve',
        post_ids: ids,
        nonce: rtsDashboard.dashboard_nonce
      }
    }, requestPriorities.rts_bulk_approve)
    .done(function(response){
      if (response && response.success) {
        const count = (response.data && response.data.approved) ? response.data.approved : ids.length;
        showToast('success', 'Bulk approved', count + ' letter(s) approved and queued.');
        setTimeout(updateStatus, 1000);
        setTimeout(() => location.reload(), 1800);
      } else {
        showToast('error', 'Error', (response && response.data) ? response.data : 'Bulk approval failed');
        $btn.prop('disabled', false).text(originalText);
      }
    })
    .fail(function(xhr){
      if (xhr && xhr.statusText === 'abort') return;
      showToast('error', 'Error', 'Network failed');
      $btn.prop('disabled', false).text(originalText);
    })
    .always(function(){
      // Ensure button restores if we didn't reload
      setTimeout(function(){
        if ($btn && $btn.length) $btn.prop('disabled', false).text(originalText);
      }, 2500);
    });
  }

  // ---------------------------------------------------------
  // Toast batching
  // ---------------------------------------------------------
  let toastQueue = [];
  let toastTimeout = null;

  function showToast(type, title, message) {
    if (message === undefined || message === null) message = '';
    if (typeof message === 'object') {
      message = message.message || message.status || message.error || JSON.stringify(message);
    }
    message = String(message || '');

    toastQueue.push({ type: type, title: title, message: message, timestamp: Date.now() });
    if (!toastTimeout) processToastQueue();
  }

  function processToastQueue() {
    if (!toastQueue.length) {
      toastTimeout = null;
      return;
    }

    const now = Date.now();
    const recent = [];
    const rest = [];

    for (let i = 0; i < toastQueue.length; i++) {
      const t = toastQueue[i];
      if (now - t.timestamp < 1000) recent.push(t);
      else rest.push(t);
    }

    if (recent.length > 1) {
      const first = recent[0];
      const msg = first.message + ' (and ' + (recent.length - 1) + ' more)';
      createSingleToast(first.type, first.title, msg);
    } else if (recent.length === 1) {
      createSingleToast(recent[0].type, recent[0].title, recent[0].message);
    }

    toastQueue = rest;
    toastTimeout = setTimeout(processToastQueue, 100);
  }

  function createSingleToast(type, title, message) {
    const icons = { success: '✅', error: '❌', warning: '⚠️', info: 'ℹ️' };
    const $container = $('#rts-toast-container');
    if (!$container.length) return;

    const html = (
      '<div class="rts-toast rts-toast-' + type + '">' +
        '<div class="rts-toast-icon">' + (icons[type] || '') + '</div>' +
        '<div class="rts-toast-content">' +
          '<div class="rts-toast-title">' + String(title || '') + '</div>' +
          '<div class="rts-toast-message">' + String(message || '') + '</div>' +
        '</div>' +
        '<button class="rts-toast-close" type="button" aria-label="Close">&times;</button>' +
      '</div>'
    );

    const $toast = $(html);
    $container.append($toast);

    setTimeout(() => {
      $toast.addClass('fade-out');
      setTimeout(() => $toast.remove(), 300);
    }, 5000);
  }

  // ---------------------------------------------------------
  // Diagnostics panel
  // ---------------------------------------------------------
  function ensureDiagnosticsPanel() {
    var wrap = document.querySelector('.wrap.rts-dashboard') || document.querySelector('.wrap');
    if (!wrap) return;
    if (document.getElementById('rts-diag-panel')) return;

    var details = document.createElement('details');
    details.id = 'rts-diag-panel';
    details.style.marginTop = '18px';
    details.style.background = '#0f1626';
    details.style.color = '#ffffff';
    details.style.border = '1px solid rgba(255,255,255,0.12)';
    details.style.borderRadius = '12px';
    details.style.padding = '12px';

    var summary = document.createElement('summary');
    summary.textContent = 'Diagnostics (scan + queue)';
    summary.style.cursor = 'pointer';
    summary.style.fontWeight = '600';
    summary.style.color = '#ffffff';

    var meta = document.createElement('div');
    meta.id = 'rts-diag-meta';
    meta.style.marginTop = '8px';
    meta.style.opacity = '0.9';
    meta.textContent = 'No state yet.';

    var pre = document.createElement('pre');
    pre.id = 'rts-diag-log';
    pre.style.marginTop = '10px';
    pre.style.maxHeight = '260px';
    pre.style.overflow = 'auto';
    pre.style.background = 'rgba(255,255,255,0.06)';
    pre.style.border = '1px solid rgba(255,255,255,0.12)';
    pre.style.borderRadius = '10px';
    pre.style.padding = '10px';
    pre.style.color = '#fff';

    details.appendChild(summary);
    details.appendChild(meta);
    details.appendChild(pre);

    wrap.appendChild(details);
  }

  function updateDiagnostics(diag) {
    queueDOMUpdate(function(){
      var meta = document.getElementById('rts-diag-meta');
      var pre  = document.getElementById('rts-diag-log');
      if (!diag) return;

      // meta (throttled by caller)
      if (meta && diag.state) {
        var s = diag.state;
        var bits = [];
        if (s.pump_last_run_gmt) bits.push('Pump: ' + s.pump_last_run_gmt);
        if (typeof s.pump_candidates !== 'undefined') bits.push('Cand: ' + s.pump_candidates);
        if (typeof s.pending_found !== 'undefined') bits.push('Pending: ' + s.pending_found);
        if (typeof s.needs_review_found !== 'undefined') bits.push('Needs review: ' + s.needs_review_found);
        if (s.updated_gmt) bits.push('Updated: ' + s.updated_gmt);
        var nextMeta = bits.join(' | ') || 'No state yet.';
        if (meta.textContent !== nextMeta) meta.textContent = nextMeta;
      }

      // log change-only + sticky scroll
      if (pre && Array.isArray(diag.log)) {
        var shouldStick = (pre.scrollHeight - pre.scrollTop - pre.clientHeight) < 50;
        var newLines = diag.log.slice(-60).map(function (row) {
          var data = row.data ? JSON.stringify(row.data) : '';
          return row.t + '  ' + row.event + (data ? '  ' + data : '');
        }).join('\n');

        if (pre.textContent !== newLines) {
          pre.textContent = newLines;
          if (shouldStick) {
            if (CAPS.hasRAF) {
              requestAnimationFrame(function(){ pre.scrollTop = pre.scrollHeight; });
            } else {
              pre.scrollTop = pre.scrollHeight;
            }
          }
        }
      }
    });
  }

  // ---------------------------------------------------------
  // Cleanup
  // ---------------------------------------------------------
  function cleanupDashboard() {
    stopPolling();

    try {
      for (const req of pendingRequests.values()) {
        try { req.xhr.abort(); } catch(e) {}
      }
    } catch(e) {}
    pendingRequests.clear();

    domUpdateQueue = [];
    domUpdateScheduled = false;

    // Remove delegated listeners
    $(document).off('click', '#rts-scan-inbox-btn, #rts-rescan-quarantine-btn');
    $(document).off('click', '#rts-refresh-status-btn');
    $(document).off('click', '.rts-manual-process-btn');
    $(document).off('click', '.rts-approve-btn');
    $(document).off('click', '.rts-bulk-approve-btn');
    $(document).off('click', '#rts-cancel-import-btn');
    $(document).off('click', '.rts-toast-close');

    document.removeEventListener('visibilitychange', handleVisibilityChange);
  }

  // Init on load
  $(document).ready(initDashboard);

})(jQuery);

// =========================================================
// AJAX Tabs: load tab content without full page refresh
// =========================================================
(function(){
  if(typeof jQuery==='undefined') return;
  jQuery(function($){
    var $wrap = $('.rts-dashboard');
    if(!$wrap.length) return;

    $wrap.on('click', '.nav-tab-wrapper a.nav-tab', function(e){
      var href = $(this).attr('href') || '';
      if(href.indexOf('rts-engine') === -1) return; // don't interfere with other tabs
      e.preventDefault();

      var url = new URL(href, window.location.origin);
      var tab = url.searchParams.get('tab') || 'status';

      // UI state
      $wrap.find('.nav-tab-wrapper a.nav-tab').removeClass('nav-tab-active');
      $(this).addClass('nav-tab-active');

      var $panel = $('#rts-tab-content');
      if(!$panel.length) return;
      $panel.attr('aria-busy','true');

      // NOTE:
      // - admin-ajax actions must use the dedicated dashboard nonce.
      // - `rtsDashboard.nonce` is the REST nonce (X-WP-Nonce) and will fail wp_verify_nonce() checks.
      $.post(ajaxurl, {
        action: 'rts_load_settings_tab',
        tab: tab,
        nonce: (window.rtsDashboard && window.rtsDashboard.dashboard_nonce) ? window.rtsDashboard.dashboard_nonce : ''
      }).done(function(resp){
        if(resp && resp.success && resp.data && resp.data.html){
          $panel.html(resp.data.html);
          // update URL without reload
          try{ window.history.replaceState({}, '', href); }catch(err){}
        } else {
          var msg = (resp && resp.data && resp.data.message) ? resp.data.message : 'Could not load tab.';
          $panel.html('<div class="notice notice-error"><p>'+msg+'</p></div>');
        }
      }).fail(function(){
        $panel.html('<div class="notice notice-error"><p>Could not load tab. Please try again.</p></div>');
      }).always(function(){
        $panel.removeAttr('aria-busy');
      });
    });
  });
})();

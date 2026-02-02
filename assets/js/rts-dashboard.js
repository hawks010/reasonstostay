/**
 * RTS Dashboard Real-time Updates
 * version: 2.40
 */
(function($) {
    'use strict';
    
    // Polling state
    let pollingInterval = null;
    let isPolling = false;
    
    // Initialize dashboard
    function initDashboard() {
        // Start polling if we are on the Moderation Dashboard or on a Letters admin screen
        // that renders the "Letters Analytics" header.
        if ($('.rts-dashboard').length || $('.rts-letters-analytics').length) {
            startPolling();
            setupEventListeners();
            updateStatus(); // Initial run
        }
    }
    
    // Start polling for updates (every 5 seconds)
    function startPolling() {
        ensureDiagnosticsPanel();
        if (isPolling) return;
        isPolling = true;
        pollingInterval = setInterval(updateStatus, 5000);
        $('#rts-scan-status').addClass('polling-active');
    }
    
    // Stop polling
    function stopPolling() {
        if (!isPolling) return;
        isPolling = false;
        clearInterval(pollingInterval);
        $('#rts-scan-status').removeClass('polling-active');
    }
    
    // Update all status indicators via REST API
    function updateStatus() {
        $.ajax({
            url: rtsDashboard.resturl + 'processing-status',
            method: 'GET',
            beforeSend: function(xhr) {
                xhr.setRequestHeader('X-WP-Nonce', rtsDashboard.nonce);
            },
            success: function(data) {
                if (data && data.queue) {
                    updateQueueStatus(data.queue);
                    updateImportStatus(data.import);
                    updateScanStatus(data.queue);
                }
                if (data && data.diag) { 
                    updateDiagnostics(data.diag); 
                }
            },
            error: function() {
                showErrorStatus();
            }
        });
    }
    
    // Update queue status DOM elements
    function updateQueueStatus(queue) {
        const queuedLetters = queue.pending_letter_jobs || 0;
        
        // Update queued count text
        $('#rts-queued-count').text(queuedLetters > 0 ? queuedLetters + ' letter(s)' : '0');
        
        // Update System Status text
        if (queuedLetters > 0) {
            $('#rts-active-scan').html('<span class="status-processing" style="color:#2271b1; font-weight:600;">Processing ' + queuedLetters + ' items...</span>');
        } else {
            $('#rts-active-scan').html('<span class="status-good" style="color:#00a32a;">Idle (Ready)</span>');
        }
    }
    
    // Update import status DOM elements
    function updateImportStatus(importData) {
        if (!importData || !importData.status || importData.status === 'idle') {
            $('#rts-import-progress-text').text('No active import');
            $('#rts-import-progress-bar .rts-progress-fill').css('width', '0%');
            return;
        }
        
        const total = Number(importData.total || 0);
        const processed = Number(importData.processed || 0);
        const errors = Number(importData.errors || 0);
        const pct = total > 0 ? Math.round((processed / total) * 100) : 0;
        
        // Update progress bar width
        $('#rts-import-progress-bar .rts-progress-fill').css('width', pct + '%');
        
        // Update text
        $('#rts-import-progress-text').text(
            processed + ' of ' + total + ' processed (' + pct + '%)' + (errors > 0 ? ' - ' + errors + ' errors' : '')
        );
        
        // Update status badge if exists
        $('.rts-progress-status').first().text(importData.status);
    }
    
    // Update scan status badge
    function updateScanStatus(queue) {
        const queuedLetters = queue.pending_letter_jobs || 0;
        const $statusBadge = $('#rts-scan-status');
        
        if (queuedLetters > 0) {
            $statusBadge
                .text('Processing')
                .css({ 'background': '#f0f6ff', 'color': '#2271b1' });
                
            $('#rts-scan-progress-status').text('Active');
            
            // Simulate visual progress for the scan bar since we don't have a specific "total" for random scans
            const $fill = $('#rts-scan-progress-bar .rts-progress-fill');
            let current = parseInt($fill.css('width')) || 0;
            if (current < 90) { 
                $fill.css('width', (current + 10) + '%'); 
            }
            $('#rts-scan-progress-text').text('Engine is analyzing content...');
            
        } else {
            $statusBadge
                .text('Idle')
                .css({ 'background': '#f0f0f1', 'color': '#646970' });
                
            $('#rts-scan-progress-status').text('Idle');
            $('#rts-scan-progress-text').text('No active scan');
            $('#rts-scan-progress-bar .rts-progress-fill').css('width', '0%');
        }
    }
    
    // Show error state in DOM
    function showErrorStatus() {
        $('#rts-scan-status')
            .text('Connection Error')
            .css({ 'background': '#fcf0f1', 'color': '#d63638' });
        
        $('#rts-active-scan').html('<span style="color:#d63638;">API Connection Failed</span>');
    }
    
    // Setup button clicks
    function setupEventListeners() {
        // Scan Inbox
        $('#rts-scan-inbox-btn').on('click', function(e) {
            e.preventDefault();
            startScan('inbox');
        });
        
        // Rescan Quarantine
        $('#rts-rescan-quarantine-btn').on('click', function(e) {
            e.preventDefault();
            startScan('quarantine');
        });
        
        // Refresh Status
        $('#rts-refresh-status-btn').on('click', function(e) {
            e.preventDefault();
            const $btn = $(this);
            $btn.prop('disabled', true);
            updateStatus();
            setTimeout(() => $btn.prop('disabled', false), 1000);
        });
        
        // Manual "Process" button in table rows
        $('.rts-manual-process-btn').on('click', function(e) {
            e.preventDefault();
            const postId = $(this).data('post-id');
            processSingleLetter(postId, $(this));
        });

        // Approve (override) a quarantined letter then re-process
        $(document).on('click', '.rts-approve-btn', function(e){
            e.preventDefault();
            const postId = $(this).data('post-id');
            approveSingleLetter(postId, $(this));
        });

        // Bulk approve selected quarantined letters
        $(document).on('click', '.rts-bulk-approve-btn', function(e){
            e.preventDefault();
            bulkApproveSelected($(this));
        });

        // Approve (override) a quarantined letter then re-process
        $(document).on('click', '.rts-approve-btn', function(e){
            e.preventDefault();
            const postId = $(this).data('post-id');
            if (!postId) return;
            approveSingleLetter(postId, $(this));
        });

        // Bulk approve
        $(document).on('click', '.rts-bulk-approve-btn', function(e){
            e.preventDefault();
            const ids = [];
            $('.rts-select-letter:checked').each(function(){
                const v = parseInt($(this).val(), 10);
                if (v) ids.push(v);
            });
            if (!ids.length) {
                showToast('Nothing selected', 'Select one or more letters first.', 'warning');
                return;
            }
            bulkApproveLetters(ids, $(this));
        });


        // Close Toast
        $(document).on('click', '.rts-toast-close', function() {
            $(this).closest('.rts-toast').addClass('fade-out');
            setTimeout(() => $(this).closest('.rts-toast').remove(), 300);
        });
    }
    
    // AJAX: Start a Bulk Scan
    function startScan(type) {
        const $btn = $('#' + (type === 'inbox' ? 'rts-scan-inbox-btn' : 'rts-rescan-quarantine-btn'));
        const originalText = $btn.find('.btn-text').text();
        
        // UI Loading State
        $btn.prop('disabled', true);
        $btn.find('.btn-text').text('Starting...');
        $btn.find('.spinner').css('display', 'inline-block').addClass('is-active');
        
        $.ajax({
            url: rtsDashboard.ajaxurl,
            method: 'POST',
            data: {
                action: 'rts_start_scan',
                scan_type: type,
                nonce: rtsDashboard.dashboard_nonce
            },
            success: function(response) {
                if (response.success) {
                    showToast('success', 'Scan Started', response.data);
                    setTimeout(updateStatus, 1000); // Force update
                } else {
                    showToast('error', 'Error', response.data || 'Could not start scan');
                }
            },
            error: function() {
                showToast('error', 'Error', 'Network request failed.');
            },
            complete: function() {
                // Reset button after short delay
                setTimeout(() => {
                    $btn.prop('disabled', false);
                    $btn.find('.btn-text').text(originalText);
                    $btn.find('.spinner').hide().removeClass('is-active');
                }, 2000);
            }
        });
    }
    
    // AJAX: Process Single Letter
    function processSingleLetter(postId, $btn) {
        const originalText = $btn.text();
        $btn.prop('disabled', true).text('...');
        
        $.ajax({
            url: rtsDashboard.ajaxurl,
            method: 'POST',
            data: {
                action: 'rts_process_single',
                post_id: postId,
                nonce: rtsDashboard.dashboard_nonce
            },
            success: function(response) {
                if (response.success) {
                    showToast('success', 'Queued', 'Letter #' + postId + ' queued for analysis.');
                    setTimeout(updateStatus, 1000);
                    // Optional: reload page to see status change if user waits
                    setTimeout(() => location.reload(), 2500); 
                } else {
                    showToast('error', 'Error', response.data);
                    $btn.prop('disabled', false).text(originalText);
                }
            },
            error: function() {
                showToast('error', 'Error', 'Network failed');
                $btn.prop('disabled', false).text(originalText);
            }
        });
    }

    // AJAX: Approve (override) a quarantined letter then re-process
    function approveSingleLetter(postId, $btn) {
        const originalText = $btn.text();
        $btn.prop('disabled', true).text('...');

        $.ajax({
            url: rtsDashboard.ajaxurl,
            method: 'POST',
            data: {
                action: 'rts_approve_letter',
                post_id: postId,
                nonce: rtsDashboard.dashboard_nonce
            },
            success: function(response) {
                if (response.success) {
                    showToast('success', 'Approved', 'Letter #' + postId + ' approved and queued.');
                    setTimeout(updateStatus, 1000);
                    setTimeout(() => location.reload(), 1800);
                } else {
                    showToast('error', 'Error', response.data || 'Approval failed');
                    $btn.prop('disabled', false).text(originalText);
                }
            },
            error: function() {
                showToast('error', 'Error', 'Network failed');
                $btn.prop('disabled', false).text(originalText);
            }
        });
    }

    // AJAX: Bulk approve selected quarantined letters
    function bulkApproveLetters(ids, $btn) {
        if (!ids || !ids.length) {
            showToast('info', 'No selection', 'Select one or more letters first.');
            return;
        }

        const originalText = $btn.text();
        $btn.prop('disabled', true).text('Approving...');

        $.ajax({
            url: rtsDashboard.ajaxurl,
            method: 'POST',
            data: {
                action: 'rts_bulk_approve',
                post_ids: ids,
                nonce: rtsDashboard.dashboard_nonce
            },
            success: function(response) {
                if (response.success) {
                    const count = (response.data && response.data.approved) ? response.data.approved : ids.length;
                    showToast('success', 'Bulk approved', count + ' letter(s) approved and queued.');
                    setTimeout(updateStatus, 1000);
                    setTimeout(() => location.reload(), 1800);
                } else {
                    showToast('error', 'Error', response.data || 'Bulk approval failed');
                    $btn.prop('disabled', false).text(originalText);
                }
            },
            error: function() {
                showToast('error', 'Error', 'Network failed');
                $btn.prop('disabled', false).text(originalText);
            }
        });
    }
    
    // Toast Notification Helper
    function showToast(type, title, message) {
        const icons = { success: '✅', error: '❌', warning: '⚠️', info: 'ℹ️' };
        const $container = $('#rts-toast-container');
        
        const html = `
            <div class="rts-toast rts-toast-${type}">
                <div class="rts-toast-icon">${icons[type]}</div>
                <div class="rts-toast-content">
                    <div class="rts-toast-title">${title}</div>
                    <div class="rts-toast-message">${message}</div>
                </div>
                <button class="rts-toast-close">&times;</button>
            </div>
        `;
        
        const $toast = $(html);
        $container.append($toast);
        
        // Auto remove
        setTimeout(() => {
            $toast.addClass('fade-out');
            setTimeout(() => $toast.remove(), 300);
        }, 5000);
    }

    // Diagnostics Panel Helpers
    function ensureDiagnosticsPanel() {
        var wrap = document.querySelector('.wrap.rts-dashboard') || document.querySelector('.wrap');
        if (!wrap) return;
        if (document.getElementById('rts-diag-panel')) return;

        var details = document.createElement('details');
        details.id = 'rts-diag-panel';
        details.style.marginTop = '18px';
                // Force readable text in dark-mode admin wrapper
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
        meta.style.marginTop = '10px';
		        meta.style.color = '#ffffff';

        var pre = document.createElement('pre');
        pre.id = 'rts-diag-log';
        pre.style.whiteSpace = 'pre-wrap';
        pre.style.marginTop = '10px';
        pre.style.maxHeight = '280px';
        pre.style.overflow = 'auto';
		pre.style.background = '#0f1626';
        pre.style.padding = '10px';
		        pre.style.color = '#ffffff';
		pre.style.border = '1px solid rgba(255,255,255,0.12)';
		pre.style.borderRadius = '10px';

        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'button';
        btn.textContent = 'Reset diagnostics';
        btn.style.marginTop = '10px';

        btn.addEventListener('click', function () {
            if (!window.rtsDashboard || !window.rtsDashboard.ajaxurl) return;
            $.post(window.rtsDashboard.ajaxurl, {
                action: 'rts_reset_diag',
                nonce: window.rtsDashboard.dashboard_nonce
            }).done(function () {
                pre.textContent = '';
                meta.textContent = 'Diagnostics reset.';
            });
        });

        details.appendChild(summary);
        details.appendChild(meta);
        details.appendChild(pre);
        details.appendChild(btn);
        wrap.appendChild(details);
    }

    function updateDiagnostics(diag) {
        var meta = document.getElementById('rts-diag-meta');
        var pre  = document.getElementById('rts-diag-log');

        try {
            if (diag && diag.state) console.debug('[RTS diag state]', diag.state);
            if (diag && diag.log) console.debug('[RTS diag log tail]', diag.log);
        } catch(e) {}

        if (meta && diag && diag.state) {
            var s = diag.state;
            var bits = [];
            if (s.pump_last_run_gmt) bits.push('Pump last run: ' + s.pump_last_run_gmt);
            if (typeof s.pump_candidates !== 'undefined') bits.push('Candidates: ' + s.pump_candidates);
            if (typeof s.pending_found !== 'undefined') bits.push('Pending found: ' + s.pending_found);
            if (typeof s.needs_review_found !== 'undefined') bits.push('Needs review found: ' + s.needs_review_found);
            if (s.updated_gmt) bits.push('State updated: ' + s.updated_gmt);
            meta.textContent = bits.join(' | ') || 'No state yet.';
        }

        if (pre && diag && Array.isArray(diag.log)) {
            var lines = diag.log.slice(-60).map(function (row) {
                var data = row.data ? JSON.stringify(row.data) : '';
                return row.t + '  ' + row.event + (data ? '  ' + data : '');
            });
            pre.textContent = lines.join('\n');
        }
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
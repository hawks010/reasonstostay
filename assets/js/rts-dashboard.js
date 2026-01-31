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
        // Start polling if we are on the dashboard page
        if ($('.rts-dashboard').length) {
            startPolling();
            setupEventListeners();
            updateStatus(); // Initial run
        }
    }
    
    // Start polling for updates (every 5 seconds)
    function startPolling() {
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
    
    // Init on load
    $(document).ready(initDashboard);
    
})(jQuery);
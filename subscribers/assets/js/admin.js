/* global RTSSubscriberAdmin */
(function ($) {
  'use strict';

  function setBusy($btn, busy, labelHtml) {
    if (!$btn || !$btn.length) return;

    var original = $btn.data('rts-original-label');
    if (!original) {
      $btn.data('rts-original-label', $btn.html());
      original = $btn.html();
    }

    if (busy) {
      $btn.prop('disabled', true);
      $btn.addClass('is-busy');
      var spinner = '<span class="rts-admin-spinner" aria-hidden="true"></span>';
      $btn.html(spinner + (labelHtml ? ' ' + labelHtml : ' Working...'));
    } else {
      $btn.prop('disabled', false);
      $btn.removeClass('is-busy');
      $btn.html(original);
    }
  }

  function getCfg() {
    return (typeof RTSSubscriberAdmin === 'object' && RTSSubscriberAdmin) ? RTSSubscriberAdmin : {};
  }

  function ajaxPost(action, data) {
    var cfg = getCfg();
    return $.ajax({
      url: cfg.ajax_url || window.ajaxurl,
      type: 'POST',
      dataType: 'json',
      data: $.extend({
        action: action,
        nonce: cfg.nonce || ''
      }, data || {}),
      timeout: 30000
    });
  }

  // Confirm prompts
  $(document).on('click', '[data-rts-confirm]', function (e) {
    var msg = $(this).attr('data-rts-confirm');
    if (msg && !window.confirm(msg)) {
      e.preventDefault();
      e.stopPropagation();
      return false;
    }
  });

  // Busy state for actions that might take a moment
  $(document).on('click', '.rts-admin-action, .rts-queue-button', function () {
    setBusy($(this), true);
  });

  // Optional AJAX buttons (future-proofing)
  // Usage: <button class="rts-ajax-button" data-action="some_action" data-payload='{"id":123}'></button>
  $(document).on('click', '.rts-ajax-button', function (e) {
    var $btn = $(this);
    var action = $btn.data('action');
    if (!action) return;

    e.preventDefault();

    var payload = {};
    try {
      var raw = $btn.attr('data-payload');
      if (raw) payload = JSON.parse(raw);
    } catch (err) {
      payload = {};
    }

    setBusy($btn, true);

    ajaxPost(action, payload)
      .done(function (res) {
        if (res && res.success && res.data && res.data.message) {
          window.alert(res.data.message);
        } else if (res && res.data && res.data.message) {
          window.alert(res.data.message);
        }
      })
      .fail(function () {
        window.alert('Request failed. Please try again.');
      })
      .always(function () {
        setBusy($btn, false);
      });
  });

  // Release busy state if navigation is cancelled (rare)
  $(window).on('pageshow', function () {
    $('.rts-admin-action.is-busy, .rts-queue-button.is-busy').each(function () {
      setBusy($(this), false);
    });
  });

  // Move the inline "Add Subscriber" card underneath the Subscribers title
  // (it is rendered via an admin notice hook, which can place it too high).
  $(function () {
    var $card = $('#rts-inline-add-subscriber');
    if (!$card.length) return;

    var $title = $('.wrap h1.wp-heading-inline').first();
    if (!$title.length) $title = $('.wrap h1').first();
    if (!$title.length) return;

    // Insert right after the title line (and before notices/search/table UI)
    $card.insertAfter($title);
  });
})(jQuery);

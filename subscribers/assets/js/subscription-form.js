jQuery(document).ready(function($) {
  function isValidEmail(email) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
  }

  $(document).on('submit', '.rts-subscribe-form', function(e) {
    e.preventDefault();

    var $form = $(this);

    if ($form.data('is-submitting')) {
      return;
    }
    $form.data('is-submitting', true);

    var $button = $form.find('.rts-form-submit');
    var $message = $form.find('.rts-form-message');

    // Store original button text so we can restore it after AJAX completes.
    var originalButtonText = $button.data('original-text');
    if (!originalButtonText) {
      originalButtonText = $button.text();
      $button.data('original-text', originalButtonText);
    }

    // Fields present in the PHP render_form()
    var $emailInput = $form.find('input[name="email"]');
    var $frequencyInput = $form.find('select[name="frequency"]');
    var $formToken = $form.find('input[name="form_token"]');
    var $fingerprint = $form.find('input[name="client_fingerprint"]');
    // Honeypot: support both legacy name (rts_website) and simplified (website)
    var $honeypot = $form.find('input[name="rts_website"]');
    if (!$honeypot.length) {
      $honeypot = $form.find('input[name="website"]');
    }

    // Optional fields (older/newer templates may include these)
    var $timestamp = $form.find('input[name="timestamp"]');
    var $formHash  = $form.find('input[name="form_hash"]');
    var $privacy = $form.find('input[name="privacy_consent"]');

    // Optional: reCAPTCHA response (if you add it)
    var $recaptcha = $form.find('textarea[name="g-recaptcha-response"], input[name="g-recaptcha-response"]');

    $message
      .hide()
      .removeClass('success error')
      .attr('aria-live', 'polite')
      .attr('role', 'alert');

    var emailValue = ($emailInput.val() || '').trim();
    if (!emailValue || !isValidEmail(emailValue)) {
      $message.addClass('error').html('Please enter a valid email address.').fadeIn();
      $emailInput.focus();
      $form.data('is-submitting', false);
      setTimeout(function() { $message.fadeOut(); }, 3000);
      return;
    }

    // If the privacy checkbox exists, enforce it (GDPR-safe)
    if ($privacy.length && !$privacy.is(':checked')) {
      $message.addClass('error').html('Please confirm you agree to the privacy policy.').fadeIn();
      $privacy.focus();
      $form.data('is-submitting', false);
      setTimeout(function() { $message.fadeOut(); }, 4000);
      return;
    }

    // Collect prefs[] checkboxes
    var prefs = [];
    $form.find('input[name="prefs[]"]:checked').each(function() {
      prefs.push($(this).val());
    });

    // Button loading state
    $button.prop('disabled', true).html('<span class="rts-spinner" aria-hidden="true"></span> Subscribing...');

    var payload = {
      action: 'rts_subscribe',
      nonce: (window.rtsSubscribe && rtsSubscribe.nonce) ? rtsSubscribe.nonce : '',
      email: emailValue,
      frequency: $frequencyInput.val() || 'weekly',
      // Server-side anti-abuse fields
      form_token: $formToken.val() || '',
      client_fingerprint: $fingerprint.val() || '',
      rts_website: $honeypot.val() || '',
      website: $honeypot.val() || '',
      timestamp: $timestamp.val() || '',
      form_hash: $formHash.val() || '',
      privacy_consent: $privacy.length ? ($privacy.is(':checked') ? '1' : '0') : '1',
      'prefs[]': prefs
    };

    if ($recaptcha.length) {
      payload['g-recaptcha-response'] = $recaptcha.val() || '';
    }

    $.ajax({
      url: (window.rtsSubscribe && rtsSubscribe.ajax_url) ? rtsSubscribe.ajax_url : '/wp-admin/admin-ajax.php',
      type: 'POST',
      data: payload,
      dataType: 'json',
      timeout: 30000,
      success: function(response) {
        if (response && response.success) {
          $message.addClass('success').html(response.data && response.data.message ? response.data.message : 'Subscribed!').fadeIn();
          $form[0].reset();
        } else {
          var msg = (response && response.data && response.data.message) ? response.data.message : 'An error occurred. Please try again.';
          $message.addClass('error').html(msg).fadeIn();
        }
      },
      error: function(xhr, status, error) {
        var errorMsg = 'Network error. Please check your connection.';
        if (status === 'timeout') {
          errorMsg = 'Request timed out. Please try again.';
        }
        $message.removeClass('success').addClass('error').html(errorMsg).fadeIn();
        console.error('Subscription error:', status, error);
      },
      complete: function() {
        $form.data('is-submitting', false);
        $button.prop('disabled', false).text(originalButtonText || 'Subscribe');

        if ($message.is(':visible') && ($message.hasClass('success') || $message.hasClass('error'))) {
          setTimeout(function() { $message.fadeOut(); }, 5000);
        }
      }
    });
  });
});

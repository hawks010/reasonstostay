jQuery(document).ready(function($) {
    $('.rts-subscribe-form').on('submit', function(e) {
        e.preventDefault();
        var $form = $(this);
        var $button = $form.find('.rts-form-submit');
        var $message = $form.find('.rts-form-message');
        
        $button.prop('disabled', true).text('Subscribing...');
        $message.hide().removeClass('success error');
        
        $.ajax({
    url: rtsSubscribe.ajax_url,
    type: 'POST',
    data: {
        action: 'rts_subscribe',
        nonce: rtsSubscribe.nonce,
        email: $form.find('input[name="email"]').val(),
        frequency: $form.find('select[name="frequency"]').val(),
        timestamp: $form.find('input[name="timestamp"]').val(),
        form_hash: $form.find('input[name="form_hash"]').val(),
        honeypot: $form.find('input[name="website"]').val()
    },
    dataType: 'json',
    timeout: 30000,
    success: function(response) {
        if (response && response.success) {
            $message.addClass('success').html(response.data.message).fadeIn();
            $form[0].reset();
        } else {
            var msg = (response && response.data && response.data.message) ? response.data.message : 'An error occurred. Please try again.';
            $message.addClass('error').html(msg).fadeIn();
        }
    },
    error: function(xhr, status, error) {
        if (status === 'timeout') {
            $message.removeClass('success').addClass('error').html('Request timed out. Please try again.').fadeIn();
        } else {
            $message.removeClass('success').addClass('error').html('Network error. Please check your connection.').fadeIn();
        }
        console.error('Subscription error:', status, error);
    },
    complete: function() {
        $button.prop('disabled', false).text('Subscribe');
        if ($message.hasClass('error')) {
            setTimeout(function() {
                $message.fadeOut();
            }, 3000);
        }
    }
});
});
});

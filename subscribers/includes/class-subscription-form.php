<?php

class RTS_Subscription_Form {

    public function __construct() {
        add_shortcode('rts_subscribe_form', array($this, 'render'));
    }

    public function render($atts) {
        // Enqueue styles/scripts if not already loaded
        wp_enqueue_style('rts-frontend-css');
        wp_enqueue_script('rts-subscription-js');

        $form_id = uniqid('rts-subscribe-');
        $nonce = wp_create_nonce('rts_subscribe_nonce');

        ob_start();
        ?>
        <div class="rts-subscribe-wrapper" id="<?php echo esc_attr($form_id); ?>">

            <form class="rts-subscribe-form" method="post" novalidate>
                <!-- Security Tokens -->
                <input type="hidden" name="action" value="rts_handle_subscription">
                <input type="hidden" name="security" value="<?php echo esc_attr($nonce); ?>">
                <input type="hidden" name="client_fingerprint" class="rts-client-fingerprint">

                <h3 class="rts-subscribe-title">A Reason to Stay, Delivered.</h3>

                <p class="rts-subscribe-intro">
                    When the world feels heavy, a kind word can make a difference.
                    Receive anonymous letters from people who have been there.
                    No pressure, no spam—just a quiet reminder that you are not alone.
                </p>

                <!-- Email Field -->
                <div class="rts-form-group">
                    <label for="<?php echo esc_attr($form_id); ?>-email" class="rts-form-label">Where should we send your letter?</label>
                    <input type="email" id="<?php echo esc_attr($form_id); ?>-email" name="email" class="rts-form-input" required aria-required="true" autocomplete="email" placeholder="name@example.com">
                </div>

                <!-- Frequency Field -->
                <div class="rts-form-group">
                    <label for="<?php echo esc_attr($form_id); ?>-frequency" class="rts-form-label">How often do you need a reminder?</label>
                    <p id="<?php echo esc_attr($form_id); ?>-freq-help" class="rts-form-help">You can change this at any time.</p>
                    <select id="<?php echo esc_attr($form_id); ?>-frequency" name="frequency" class="rts-form-select" aria-describedby="<?php echo esc_attr($form_id); ?>-freq-help">
                        <option value="weekly" selected>Weekly (Recommended)</option>
                        <option value="daily">Daily (I need support now)</option>
                        <option value="monthly">Monthly (Just checking in)</option>
                    </select>
                </div>

                <!-- Preferences Field -->
                <div class="rts-form-group">
                    <label class="rts-form-label">What would you like to receive?</label>

                    <div class="rts-checkbox-grid" role="group" aria-label="Subscription preferences">
                        <label class="rts-checkbox-label">
                            <input type="checkbox" name="prefs[]" value="letters" checked>
                            <span><strong>Letters of Hope</strong><br><small>Supportive, anonymous notes.</small></span>
                        </label>
                        <label class="rts-checkbox-label">
                            <input type="checkbox" name="prefs[]" value="newsletters" checked>
                            <span><strong>Project Updates</strong><br><small>Occasional news & features.</small></span>
                        </label>
                    </div>
                </div>

                <!-- Privacy Consent -->
                <div class="rts-form-group" style="margin-top: 2rem;">
                    <label class="rts-checkbox-label" style="align-items: center;">
                        <input type="checkbox" name="privacy_consent" required aria-required="true">
                        <span class="rts-privacy-consent-text">I consent to receive emails and agree to the <a href="/privacy-policy" target="_blank">Privacy Policy</a>.</span>
                    </label>
                </div>

                <!-- Honeypot -->
                <div class="rts-honeypot" aria-hidden="true">
                    <input type="text" name="rts_website" tabindex="-1" autocomplete="off">
                </div>

                <button type="submit" class="rts-form-submit">
                    Start Receiving Letters
                </button>

                <div class="rts-form-message" role="alert" aria-live="polite"></div>

                <p class="rts-privacy-notice">
                    We respect your inbox. Unsubscribe anytime with one click.
                </p>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }
}

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

        // Generate unique IDs for checkboxes to ensure label clicking works 100% of the time
        $id_letters = $form_id . '-pref-letters';
        $id_updates = $form_id . '-pref-updates';
        $id_privacy = $form_id . '-privacy';

        ob_start();
        ?>
        <div class="rts-subscribe-wrapper" id="<?php echo esc_attr($form_id); ?>">

            <form class="rts-subscribe-form" method="post">
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
                    <input type="email" 
                           id="<?php echo esc_attr($form_id); ?>-email" 
                           name="email" 
                           class="rts-form-input" 
                           required
                           aria-required="true"
                           autocomplete="email"
                           inputmode="email"
                           placeholder="name@example.com">
                </div>

                <!-- Frequency Field -->
                <div class="rts-form-group">
                    <label for="<?php echo esc_attr($form_id); ?>-frequency" class="rts-form-label">How often do you need a reminder?</label>
                    <p id="<?php echo esc_attr($form_id); ?>-freq-help" class="rts-form-help">You can change this at any time.</p>
                    <select id="<?php echo esc_attr($form_id); ?>-frequency" 
                            name="frequency" 
                            class="rts-form-select" 
                            aria-describedby="<?php echo esc_attr($form_id); ?>-freq-help"
                            aria-label="Select subscription frequency">
                        <option value="weekly" selected>Weekly (Recommended)</option>
                        <option value="daily">Daily (I need support now)</option>
                        <option value="monthly">Monthly (Just checking in)</option>
                    </select>
                </div>

                <!-- Preferences Field -->
                <div class="rts-form-group rts-prefs-group">
                    <label class="rts-form-label">What would you like to receive?</label>

                    <div class="rts-checkbox-grid" role="group" aria-label="Subscription preferences">
                        <label class="rts-subscribe-checkbox-label" for="<?php echo esc_attr($id_letters); ?>">
                            <input type="checkbox" id="<?php echo esc_attr($id_letters); ?>" name="prefs[]" value="letters" class="rts-real-checkbox">
                            <!-- Custom Visual Checkbox -->
                            <span class="rts-checkbox-visual" aria-hidden="true">
                                <svg width="12" height="10" viewBox="0 0 12 10" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M1 5L4.5 8.5L11 1" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </span>
                            <span class="rts-checkbox-text">
                                <strong>Letters of Hope</strong><br>
                                <small>Supportive, anonymous notes.</small>
                            </span>
                        </label>
                        
                        <label class="rts-subscribe-checkbox-label" for="<?php echo esc_attr($id_updates); ?>">
                            <input type="checkbox" id="<?php echo esc_attr($id_updates); ?>" name="prefs[]" value="newsletters" class="rts-real-checkbox">
                            <span class="rts-checkbox-visual" aria-hidden="true">
                                <svg width="12" height="10" viewBox="0 0 12 10" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M1 5L4.5 8.5L11 1" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            </span>
                            <span class="rts-checkbox-text">
                                <strong>Project updates </strong><br>
                                <small>Including occasional podcast episodes and other promos.</small>
                            </span>
                        </label>
                    </div>
                </div>

                <!-- Privacy Consent -->
                <div class="rts-form-group rts-privacy-group">
                    <label class="rts-subscribe-checkbox-label" for="<?php echo esc_attr($id_privacy); ?>">
                        <input type="checkbox" id="<?php echo esc_attr($id_privacy); ?>" name="privacy_consent" value="1" required aria-required="true" class="rts-real-checkbox">
                        <span class="rts-checkbox-visual" aria-hidden="true">
                            <svg width="12" height="10" viewBox="0 0 12 10" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M1 5L4.5 8.5L11 1" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </span>
                        <span class="rts-privacy-consent-text">I consent to receive emails and agree to the <a href="/privacy-policy" target="_blank">Privacy Policy</a>.</span>
                    </label>
                </div>

                <!-- Honeypot -->
                <div class="rts-honeypot" aria-hidden="true">
                    <input type="text" name="rts_website" tabindex="-1" autocomplete="off" value="">
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
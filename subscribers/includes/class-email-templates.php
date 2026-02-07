<?php
/**
 * RTS Email Templates
 *
 * Renders HTML email bodies with safe escaping.
 *
 * @package    RTS_Subscriber_System
 * @subpackage Templates
 * @version    1.0.2
 */

if (!defined('ABSPATH')) {
    exit;
}

class RTS_Email_Templates {

    /**
     * Render a template.
     *
     * @param string $template      The template slug (e.g., 'welcome').
     * @param int    $subscriber_id The subscriber post ID.
     * @param array  $letters       Array of WP_Post objects for digest templates.
     * @param array  $context       Optional key/value pairs for custom variable replacement.
     * @return array {subject, body}
     */
    public function render($template, $subscriber_id, $letters = array(), $context = array()) {
        $template = sanitize_key($template);

        // 0. Validate Template
        $valid_templates = array(
            'welcome', 
            'verification', 
            'daily_digest', 
            'weekly_digest', 
            'monthly_digest', 
            'reconsent', 
            'newsletter_custom',
            'all_caught_up'
        );

        if (!in_array($template, $valid_templates, true)) {
            // Return safe fallback or error state to prevent PHP warnings in caller
            return array(
                'subject' => '[' . get_bloginfo('name') . '] Error',
                'body'    => '<p>Invalid email template requested.</p>'
            );
        }

        // 1. Validate Subscriber & Fetch Data
        if (!$subscriber_id || get_post_type($subscriber_id) !== 'rts_subscriber') {
             return array(
                'subject' => '[' . get_bloginfo('name') . '] Error',
                'body'    => '<p>Invalid subscriber record.</p>'
            );
        }

        $token = get_post_meta($subscriber_id, '_rts_subscriber_token', true);
        
        $manage_url      = $this->manage_url($token);
        $unsubscribe_url = $this->unsubscribe_url($token);
        $verify_url      = $this->verification_url($subscriber_id);

        // 2. Initialize Standard Replacements
        $replacements = array(
            '{site_name}'        => esc_html(get_bloginfo('name')),
            '{manage_url}'       => esc_url($manage_url),
            '{unsubscribe_url}'  => esc_url($unsubscribe_url),
            '{verification_url}' => esc_url($verify_url),
            '{letters}'          => $this->render_letters($letters),
        );

        // 3. Merge Context Replacements
        if (is_array($context) && !empty($context)) {
            foreach ($context as $k => $v) {
                // Ensure key format is wrapped in braces
                $key = '{' . trim($k, '{}') . '}';
                // Add or overwrite standard replacements
                $replacements[$key] = is_scalar($v) ? (string) $v : '';
            }
        }

        // 4. Retrieve Template Content (Option or Default)
        $subject_opt = get_option('rts_email_subject_' . $template, '');
        $body_opt    = get_option('rts_email_body_' . $template, '');

        if (!$subject_opt) {
            $subject_opt = $this->default_subject($template);
        }
        if (!$body_opt) {
            $body_opt = $this->default_body($template);
        }

        // 5. Apply Replacements
        $subject = strtr($subject_opt, $replacements);
        $body    = strtr($body_opt, $replacements);

        // 6. Security: Ensure safe HTML output
        $body = wp_kses_post($body);

        return array(
            'subject' => $subject,
            'body'    => $this->wrap_email($body, $template, $manage_url, $unsubscribe_url),
        );
    }

    private function default_subject($template) {
        $site = get_bloginfo('name');
        switch ($template) {
            case 'welcome':
                return "Welcome to {$site}";
            case 'verification':
                return "Confirm your subscription to {$site}";
            case 'daily_digest':
                return "{$site} - Your daily letter";
            case 'weekly_digest':
                return "{$site} - Your weekly letters";
            case 'monthly_digest':
                return "{$site} - Your monthly letters";
            case 'reconsent':
                return 'Please confirm what you want to receive from {site_name}';
            case 'newsletter_custom':
                return '{newsletter_subject}';
            case 'all_caught_up':
                return "{$site} - All caught up";
            default:
                return "{$site} Updates";
        }
    }

    private function default_body($template) {
        $site = esc_html(get_bloginfo('name'));
        switch ($template) {
            case 'welcome':
                return '<h2>You\'re subscribed 🎉</h2>'
                    . '<p>Thanks for signing up to ' . $site . '. You can manage your preferences here: '
                    . '<a href="{manage_url}">{manage_url}</a>.</p>'
                    . '<p>If you ever want to unsubscribe, you can do that here: '
                    . '<a href="{unsubscribe_url}">{unsubscribe_url}</a>.</p>';
            case 'verification':
                return '<h2>One last step</h2>'
                    . '<p>Please confirm your subscription by clicking this link:</p>'
                    . '<p><a href="{verification_url}">Confirm my subscription</a></p>'
                    . '<p>If you didn\'t request this, you can ignore this email.</p>';
            case 'daily_digest':
            case 'weekly_digest':
            case 'monthly_digest':
                return '<h2>Your letters</h2>'
                    . '{letters}'
                    . '<p>Manage your email frequency here: <a href="{manage_url}">{manage_url}</a>.</p>'
                    . '<p><a href="{unsubscribe_url}">Unsubscribe</a></p>';
            case 'reconsent':
                return '<p>Hello,</p><p>We\'ve recently moved our subscriber system and we want to make sure we only email you what you\'ve asked for.</p><p><strong>Please confirm your preferences here:</strong></p><p><a href="{manage_url}" style="display:inline-block;padding:12px 18px;border-radius:10px;background:#179AD6;color:#fff;text-decoration:none;">Manage my email preferences</a></p><p>If you take no action, we\'ll pause emails until you confirm.</p>';
            case 'newsletter_custom':
                return '{newsletter_body}<p style="font-size:12px;opacity:0.8;">Manage preferences: <a href="{manage_url}">manage</a> | Unsubscribe: <a href="{unsubscribe_url}">unsubscribe</a></p>';
            
case 'all_caught_up':
    return '<h2>You\'re all caught up</h2>'
        . '<p>We\'ve already sent you every email-ready letter we have right now. We\'ll email you as soon as there\'s something new.</p>';
default:
                return "<p>Updates from {$site}.</p>";
        }
    }

    private function wrap_email($body, $template, $manage_url, $unsubscribe_url) {
        // Colors configurable via options
        $bg    = get_option('rts_email_bg_color', '#ffffff');
        $text  = get_option('rts_email_text_color', '#111111');
        $muted = get_option('rts_email_muted_color', '#666666');
        $link  = get_option('rts_email_link_color', '#DF157C');

        $html = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
        $html .= '<title>' . esc_html(get_bloginfo('name')) . '</title></head>';
        $html .= '<body style="margin:0;padding:0;background:' . esc_attr($bg) . ';color:' . esc_attr($text) . ';font-family:Arial, sans-serif;">';
        $html .= '<div style="max-width:640px;margin:0 auto;padding:24px;">';
        $html .= '<div style="border:1px solid #eee;border-radius:18px;padding:24px;">';
        $html .= $body;

        // Only add footer for templates that don't already have links in their default body.
        // Also exclude 'verification' as it is pre-subscription and shouldn't offer unsubscribe options.
        $excluded_templates = array(
            'welcome',
            'verification',
            'reconsent',
            'daily_digest',
            'weekly_digest',
            'monthly_digest',
            'newsletter_custom',
        );

        if (!in_array($template, $excluded_templates, true)) {
            $html .= '<hr style="border:none;border-top:1px solid #eee;margin:24px 0;">';
            $html .= '<p style="margin:0;color:' . esc_attr($muted) . ';font-size:13px;line-height:1.5;">';
            $html .= 'Manage: <a style="color:' . esc_attr($link) . ';" href="' . esc_url($manage_url) . '">preferences</a> · ';
            $html .= '<a style="color:' . esc_attr($link) . ';" href="' . esc_url($unsubscribe_url) . '">unsubscribe</a>';
            $html .= '</p>';
        }

        $html .= '</div></div></body></html>';

        return $html;
    }

    private function render_letters($letters) {
        if (empty($letters)) {
            return '<p>No letters available right now. Please check back soon.</p>';
        }

        $out = '<div>';
        foreach ($letters as $post) {
            if (!$post instanceof WP_Post) {
                continue;
            }

            $title     = get_the_title($post);
            $permalink = get_permalink($post);
            // Uses manual excerpt if available, otherwise safely trims content stripping tags to prevent HTML breakage in email clients.
            $content   = has_excerpt($post) ? get_the_excerpt($post) : wp_trim_words(wp_strip_all_tags($post->post_content), 55);

            $out .= '<div style="padding:16px;border:1px solid #eee;border-radius:16px;margin:0 0 12px 0;">';
            $out .= '<h3 style="margin:0 0 8px 0;font-size:16px;line-height:1.3;">' . esc_html($title) . '</h3>';
            $out .= '<p style="margin:0 0 10px 0;color:#444;line-height:1.5;">' . esc_html($content) . '</p>';
            $out .= '<p style="margin:0;"><a href="' . esc_url($permalink) . '">Read this letter</a></p>';
            $out .= '</div>';
        }
        $out .= '</div>';

        return $out;
    }

    private function manage_url($token) {
        if (!$token) return home_url('/');
        $sig = hash_hmac('sha256', $token . '|manage', wp_salt('auth'));
        return add_query_arg(array('rts_manage' => $token, 'sig' => $sig), home_url('/'));
    }

    private function unsubscribe_url($token) {
        if (!$token) return home_url('/');
        $sig = hash_hmac('sha256', $token . '|unsubscribe|' . date('Y-m-d'), wp_salt('auth'));
        return add_query_arg(array('rts_unsubscribe' => $token, 'sig' => $sig), home_url('/'));
    }

    private function verification_url($subscriber_id) {
        $token = get_post_meta($subscriber_id, '_rts_subscriber_verification_token', true);
        if (!$token) return home_url('/');
        $sig = hash_hmac('sha256', $token . '|verify|' . date('Y-m-d'), wp_salt('auth'));
        return add_query_arg(array('rts_verify' => $token, 'sig' => $sig), home_url('/'));
    }
}
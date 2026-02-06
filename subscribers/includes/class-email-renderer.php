<?php
/**
 * RTS Email Renderer (Brand Proxy)
 *
 * Programmatically renders Letters (WP_Post) into branded, accessible HTML emails.
 * - Inline CSS for Outlook compatibility
 * - Semantic HTML + table wrapper
 * - Dark mode support via prefers-color-scheme
 * - Injects header (logo/site name) + CAN-SPAM footer (unsubscribe + address)
 *
 * @package    RTS_Subscriber_System
 * @subpackage Renderer
 * @version    1.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class RTS_Email_Renderer {

    /**
     * Render a single Letter email.
     *
     * @param WP_Post $letter
     * @param array   $args {
     *   @type string $preheader
     *   @type string $unsubscribe_url
     *   @type string $recipient_email
     *   @type string $template_type
     * }
     * @return string HTML
     */
    public function render_letter($letter, $args = array()) {
        if (!($letter instanceof WP_Post)) {
            return '';
        }

        $defaults = array(
            'preheader'       => '',
            'unsubscribe_url' => home_url('/'),
            'recipient_email' => '',
            'template_type'   => 'letter',
        );
        $args = wp_parse_args($args, $defaults);

        $site_name  = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        $title      = wp_specialchars_decode(get_the_title($letter), ENT_QUOTES);
        $permalink  = get_permalink($letter);

        // Brand tokens (hardcoded)
        $bg         = '#0b1220';
        $card       = '#111c33';
        $text       = '#e8eefc';
        $muted      = '#a9b7d6';
        $accent     = '#DF157C';
        $border     = '#223055';

        $logo_html  = $this->get_logo_html($site_name, $accent);

        $content = apply_filters('the_content', $letter->post_content);
        $content = $this->sanitize_email_html($content);

        // Ensure images have alt attributes (basic pass)
        $content = preg_replace_callback('/<img\b([^>]*?)>/i', function($m) use ($title) {
            $attrs = $m[1];
            if (stripos($attrs, 'alt=') === false) {
                $attrs .= ' alt="' . esc_attr($title) . '"';
            }
            return '<img' . $attrs . '>';
        }, $content);

        $preheader = $args['preheader'] ? $args['preheader'] : wp_strip_all_tags(wp_trim_words($letter->post_content, 18, '…'));
        $preheader = esc_html($preheader);

        $unsubscribe_url = esc_url($args['unsubscribe_url']);

        $address = trim((string) get_option('rts_company_address'));
        if (!$address) {
            $address = $site_name;
        }
        $address = esc_html($address);

        $html = '<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="x-apple-disable-message-reformatting">
<title>' . esc_html($title) . '</title>
<style>
/* Dark mode support (mail clients that honor it) */
@media (prefers-color-scheme: dark) {
  body, .bg { background: ' . $bg . ' !important; }
  .card { background: ' . $card . ' !important; }
  .text { color: ' . $text . ' !important; }
  .muted { color: ' . $muted . ' !important; }
  a { color: ' . $accent . ' !important; }
}
</style>
</head>
<body class="bg" style="margin:0;padding:0;background:' . $bg . ';color:' . $text . ';font-family:Inter, Helvetica, Arial, sans-serif;">
<div style="display:none;font-size:1px;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;">' . $preheader . '</div>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:' . $bg . ';padding:24px 10px;">
  <tr>
    <td align="center">
      <table role="presentation" width="680" cellpadding="0" cellspacing="0" border="0" style="max-width:680px;width:100%;">
        <tr>
          <td style="padding:10px 8px 18px 8px;">
            ' . $logo_html . '
          </td>
        </tr>

        <tr>
          <td class="card" style="background:' . $card . ';border:1px solid ' . $border . ';border-radius:24px;padding:26px 22px;">
            <h1 class="text" style="margin:0 0 14px 0;font-size:22px;line-height:1.25;color:' . $text . ';letter-spacing:-0.01em;">' . esc_html($title) . '</h1>

            <div class="text" style="font-size:16px;line-height:1.65;color:' . $text . ';">
              ' . $content . '
            </div>

            <div style="margin-top:18px;">
              <a href="' . esc_url($permalink) . '" style="display:inline-block;background:' . $accent . ';color:#ffffff;text-decoration:none;padding:12px 16px;border-radius:14px;font-weight:700;font-size:14px;line-height:1;">Read on the website</a>
            </div>
          </td>
        </tr>

        <tr>
          <td style="padding:16px 8px 0 8px;">
            <p class="muted" style="margin:0;font-size:13px;line-height:1.55;color:' . $muted . ';">
              You are receiving this email because you subscribed to updates from ' . esc_html($site_name) . '.
              <br>
              <a href="' . $unsubscribe_url . '" style="color:' . $accent . ';text-decoration:underline;">Unsubscribe</a>
              <span style="color:' . $muted . ';"> · </span>
              <span>' . $address . '</span>
            </p>
          </td>
        </tr>

      </table>
    </td>
  </tr>
</table>
</body>
</html>';

        return $html;
    }

    /**
     * Render a digest email (multiple letters).
     *
     * @param array $letters Array of WP_Post.
     * @param array $args See render_letter().
     * @return string
     */
    public function render_digest($letters, $args = array()) {
        $letters = is_array($letters) ? $letters : array();
        $letters = array_values(array_filter($letters, function($p){ return $p instanceof WP_Post; }));

        $site_name  = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);

        $defaults = array(
            'preheader'       => '',
            'unsubscribe_url' => home_url('/'),
            'recipient_email' => '',
            'template_type'   => 'digest',
            'heading'         => 'Your latest letters',
        );
        $args = wp_parse_args($args, $defaults);

        $bg         = '#0b1220';
        $card       = '#111c33';
        $text       = '#e8eefc';
        $muted      = '#a9b7d6';
        $accent     = '#DF157C';
        $border     = '#223055';

        $logo_html  = $this->get_logo_html($site_name, $accent);

        $items_html = '';
        foreach ($letters as $letter) {
            $title = esc_html(get_the_title($letter));
            $link  = esc_url(get_permalink($letter));
            $excerpt = esc_html(wp_strip_all_tags(wp_trim_words($letter->post_content, 28, '…')));

            $items_html .= '
              <tr>
                <td style="padding:0 0 14px 0;">
                  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid ' . $border . ';border-radius:18px;background:rgba(255,255,255,0.02);">
                    <tr>
                      <td style="padding:16px 16px;">
                        <h2 style="margin:0 0 8px 0;font-size:16px;line-height:1.3;color:' . $text . ';">' . $title . '</h2>
                        <p style="margin:0 0 12px 0;font-size:14px;line-height:1.6;color:' . $muted . ';">' . $excerpt . '</p>
                        <a href="' . $link . '" style="color:' . $accent . ';text-decoration:underline;font-weight:700;">Read this letter</a>
                      </td>
                    </tr>
                  </table>
                </td>
              </tr>';
        }

        if (!$items_html) {
            $items_html = '
              <tr>
                <td style="padding:0;">
                  <p style="margin:0;font-size:14px;line-height:1.6;color:' . $muted . ';">No new letters right now.</p>
                </td>
              </tr>';
        }

        $preheader = $args['preheader'] ? $args['preheader'] : 'A fresh bundle of letters from ' . $site_name . '.';
        $preheader = esc_html($preheader);

        $unsubscribe_url = esc_url($args['unsubscribe_url']);

        $address = trim((string) get_option('rts_company_address'));
        if (!$address) {
            $address = $site_name;
        }
        $address = esc_html($address);

        $heading = esc_html($args['heading']);

        $html = '<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="x-apple-disable-message-reformatting">
<title>' . $heading . '</title>
<style>
@media (prefers-color-scheme: dark) {
  body, .bg { background: ' . $bg . ' !important; }
  .card { background: ' . $card . ' !important; }
  .text { color: ' . $text . ' !important; }
  .muted { color: ' . $muted . ' !important; }
  a { color: ' . $accent . ' !important; }
}
</style>
</head>
<body class="bg" style="margin:0;padding:0;background:' . $bg . ';color:' . $text . ';font-family:Inter, Helvetica, Arial, sans-serif;">
<div style="display:none;font-size:1px;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;">' . $preheader . '</div>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:' . $bg . ';padding:24px 10px;">
  <tr>
    <td align="center">
      <table role="presentation" width="680" cellpadding="0" cellspacing="0" border="0" style="max-width:680px;width:100%;">
        <tr>
          <td style="padding:10px 8px 18px 8px;">
            ' . $logo_html . '
          </td>
        </tr>

        <tr>
          <td class="card" style="background:' . $card . ';border:1px solid ' . $border . ';border-radius:24px;padding:22px;">
            <h1 class="text" style="margin:0 0 14px 0;font-size:22px;line-height:1.25;color:' . $text . ';letter-spacing:-0.01em;">' . $heading . '</h1>

            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
              ' . $items_html . '
            </table>
          </td>
        </tr>

        <tr>
          <td style="padding:16px 8px 0 8px;">
            <p class="muted" style="margin:0;font-size:13px;line-height:1.55;color:' . $muted . ';">
              You are receiving this email because you subscribed to updates from ' . esc_html($site_name) . '.
              <br>
              <a href="' . $unsubscribe_url . '" style="color:' . $accent . ';text-decoration:underline;">Unsubscribe</a>
              <span style="color:' . $muted . ';"> · </span>
              <span>' . $address . '</span>
            </p>
          </td>
        </tr>

      </table>
    </td>
  </tr>
</table>
</body>
</html>';

        return $html;
    }

    private function get_logo_html($site_name, $accent) {
        $logo_id = get_theme_mod('custom_logo');
        $logo_url = $logo_id ? wp_get_attachment_image_url($logo_id, 'full') : '';
        if ($logo_url) {
            $logo_url = esc_url($logo_url);
            return '<img src="' . $logo_url . '" width="140" alt="' . esc_attr($site_name) . '" style="display:block;max-width:140px;height:auto;border:0;">';
        }
        return '<div style="font-weight:800;font-size:18px;letter-spacing:-0.01em;color:' . esc_attr($accent) . ';">' . esc_html($site_name) . '</div>';
    }

    /**
     * Very conservative sanitizer for email HTML.
     * Allows a small safe subset of tags/attributes so letter formatting survives.
     */
    private function sanitize_email_html($html) {
        $allowed = array(
            'a' => array('href' => true, 'title' => true, 'target' => true, 'rel' => true),
            'p' => array(),
            'br' => array(),
            'strong' => array(),
            'em' => array(),
            'ul' => array(),
            'ol' => array(),
            'li' => array(),
            'blockquote' => array(),
            'h2' => array(),
            'h3' => array(),
            'h4' => array(),
            'span' => array('style' => true),
            'div' => array('style' => true),
            'img' => array('src' => true, 'alt' => true, 'title' => true, 'width' => true, 'height' => true, 'style' => true),
        );
        return wp_kses($html, $allowed);
    }
}

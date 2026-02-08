<?php
/**
 * RTS Email Renderer - Digital Letter Design
 *
 * Renders branded HTML emails styled as handwritten "digital letters"
 * with a dark container, centered paper card, and footer with social links.
 *
 * @package    RTS_Subscriber_System
 * @subpackage Email
 * @version    3.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class RTS_Email_Renderer {

    private $logo_url = 'https://reasonstostay.inkfire.co.uk/wp-content/uploads/2026/01/Screenshot-2026-01-27-at-00.30.21.png';

    /**
     * Social links configuration.
     */
    private $socials = array(
        'facebook'  => 'https://www.facebook.com/ben.west.56884',
        'instagram' => 'https://www.instagram.com/iambenwest/?hl=en',
        'linkedin'  => 'https://www.linkedin.com/in/benwest2/?originalSubdomain=uk',
        'linktree'  => 'https://linktr.ee/iambenwest',
    );

    /**
     * Render a complete email.
     *
     * @param string $template_name Template file name (without extension).
     * @param array  $data          Replacement variables.
     * @return string Complete HTML email.
     */
    public function render($template_name, $data = array()) {
        $content = $this->get_template_content($template_name);

        $full_html = $this->get_header() . $content . $this->get_footer($data);

        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $full_html = str_replace('{{' . $key . '}}', $value, $full_html);
            }
        }

        return $full_html;
    }

    /**
     * Build the email header: dark wrapper + paper card + logo header.
     */
    private function get_header() {
        ob_start();
        ?>
<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>Reasons to Stay</title>
    <!--[if mso]>
    <style type="text/css">
        table { border-collapse: collapse; }
        .paper-card { width: 600px !important; }
    </style>
    <![endif]-->
    <link href="https://fonts.googleapis.com/css2?family=Special+Elite&display=swap" rel="stylesheet">
    <style type="text/css">
        /* Reset */
        body, table, td, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
        table, td { mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
        img { -ms-interpolation-mode: bicubic; border: 0; height: auto; line-height: 100%; outline: none; text-decoration: none; }
        body { margin: 0 !important; padding: 0 !important; width: 100% !important; background-color: #0f172a; }

        /* Dark outer wrapper */
        .email-wrapper { background-color: #0f172a; width: 100%; padding: 40px 0; }

        /* Paper card */
        .paper-card {
            max-width: 600px;
            margin: 0 auto;
            background-color: #F8F1E9;
            border-radius: 4px;
        }

        /* Header row */
        .header-table { width: 100%; }
        .header-table td { padding: 28px 32px; vertical-align: middle; }
        .header-logo img { height: 55px; width: auto; display: block; }
        .header-text {
            font-family: Georgia, 'Times New Roman', serif;
            font-size: 12px;
            font-style: italic;
            color: #6b7280;
            line-height: 1.5;
            text-align: right;
        }

        /* Body content */
        .letter-body {
            padding: 10px 36px 36px 36px;
            font-family: 'Special Elite', 'Courier New', monospace;
            font-size: 16px;
            line-height: 1.6;
            color: #1a1a1a;
            text-align: left;
        }

        .letter-body p { margin: 0 0 16px 0; }
        .letter-body a { color: #0f172a; text-decoration: underline; }

        /* CTA button */
        .cta-button {
            display: inline-block;
            padding: 12px 28px;
            background-color: #0f172a;
            color: #ffffff !important;
            text-decoration: none;
            border-radius: 4px;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            font-weight: bold;
            font-size: 14px;
            margin-top: 10px;
        }

        /* Footer */
        .footer-wrapper { background-color: #1e293b; padding: 36px 32px; text-align: center; }
        .footer-text {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            font-size: 12px;
            line-height: 1.7;
            color: #94a3b8;
            max-width: 520px;
            margin: 0 auto;
        }
        .footer-text a { color: #FCA311; text-decoration: underline; }

        .social-icons { margin: 24px 0; }
        .social-icon {
            display: inline-block;
            width: 32px;
            height: 32px;
            margin: 0 6px;
            vertical-align: middle;
        }

        .footer-links {
            margin-top: 20px;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            font-size: 11px;
            color: #64748b;
        }
        .footer-links a { color: #94a3b8; text-decoration: underline; }

        /* Responsive */
        @media only screen and (max-width: 620px) {
            .paper-card { width: 100% !important; border-radius: 0 !important; }
            .letter-body { padding: 10px 20px 28px 20px !important; font-size: 15px !important; }
            .header-table td { padding: 20px !important; }
            .footer-wrapper { padding: 28px 20px !important; }
            .header-text { font-size: 11px !important; }
        }
    </style>
</head>
<body style="margin:0;padding:0;background-color:#0f172a;">
    <div class="email-wrapper" style="background-color:#0f172a;width:100%;padding:40px 0;">
        <!--[if mso]>
        <table role="presentation" width="600" align="center" cellpadding="0" cellspacing="0" border="0" style="background-color:#F8F1E9;">
        <tr><td>
        <![endif]-->
        <div class="paper-card" style="max-width:600px;margin:0 auto;background-color:#F8F1E9;border-radius:4px;overflow:hidden;">

            <!-- Header -->
            <table class="header-table" role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
                <tr>
                    <td class="header-logo" style="padding:28px 32px;vertical-align:middle;width:50%;">
                        <img src="<?php echo esc_url($this->logo_url); ?>" alt="Reasons to Stay" style="height:55px;width:auto;display:block;" />
                    </td>
                    <td class="header-text" style="padding:28px 32px;vertical-align:middle;width:50%;font-family:Georgia,'Times New Roman',serif;font-size:12px;font-style:italic;color:#6b7280;line-height:1.5;text-align:right;">
                        This letter was written by someone in the world that cares. It was delivered to you at random.
                    </td>
                </tr>
            </table>

            <!-- Body -->
            <div class="letter-body" style="padding:10px 36px 36px 36px;font-family:'Special Elite','Courier New',monospace;font-size:16px;line-height:1.6;color:#1a1a1a;text-align:left;">
        <?php
        return ob_get_clean();
    }

    /**
     * Build the email footer: mission statement, social icons, legal links.
     */
    private function get_footer($data) {
        $unsubscribe_url = isset($data['unsubscribe_url']) ? $data['unsubscribe_url'] : '#';
        $manage_url      = isset($data['manage_url']) ? $data['manage_url'] : '';
        ob_start();
        ?>
            </div><!-- /.letter-body -->

            <!-- Footer -->
            <div class="footer-wrapper" style="background-color:#1e293b;padding:36px 32px;text-align:center;">

                <div class="footer-text" style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:12px;line-height:1.7;color:#94a3b8;max-width:520px;margin:0 auto;">
                    <strong style="color:#f8fafc;">Reasons to Stay</strong> is a suicide prevention project reaching people at difficult moments through anonymous letters written by volunteers. Each letter on this site was written by a real person and delivered to you at random when you visited this page. This space exists as a reminder that we are not alone, even when it feels that way. There is someone, somewhere who wrote you a letter because they care.
                    <br><br>
                    If you&rsquo;d like to, you can <a href="https://reasonstostay.inkfire.co.uk" style="color:#FCA311;text-decoration:underline;">write your own letter</a> to a stranger, offering warmth, hope and connection to someone when they need it most.
                    <br><br>
                    This project was designed in memory of <strong style="color:#f8fafc;">Sam West</strong>, who took his own life in 2018. If you&rsquo;re struggling right now, reaching out to a support service or someone you trust could really help. <a href="https://reasonstostay.inkfire.co.uk/resources" style="color:#FCA311;text-decoration:underline;">Find resources here</a>.
                    <br><br>
                    <span style="color:#64748b;">Created by Ben West. Press: <a href="mailto:info@benwest.org.uk" style="color:#FCA311;text-decoration:underline;">info@benwest.org.uk</a></span>
                </div>

                <!-- Social Icons -->
                <div class="social-icons" style="margin:24px 0;text-align:center;">
                    <?php echo $this->render_social_icon('Facebook', $this->socials['facebook'], 'M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z'); ?>
                    <?php echo $this->render_social_icon('Instagram', $this->socials['instagram'], 'M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zM12 0C8.741 0 8.333.014 7.053.072 2.695.272.273 2.69.073 7.052.014 8.333 0 8.741 0 12c0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98C8.333 23.986 8.741 24 12 24c3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98C15.668.014 15.259 0 12 0zm0 5.838a6.162 6.162 0 100 12.324 6.162 6.162 0 000-12.324zM12 16a4 4 0 110-8 4 4 0 010 8zm6.406-11.845a1.44 1.44 0 100 2.881 1.44 1.44 0 000-2.881z'); ?>
                    <?php echo $this->render_social_icon('LinkedIn', $this->socials['linkedin'], 'M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433a2.062 2.062 0 01-2.063-2.065 2.064 2.064 0 112.063 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z'); ?>
                    <?php echo $this->render_social_icon('Linktree', $this->socials['linktree'], 'M7.953 15.066l-.038-4.079 4.063-.002.002-3.933L6.039 1.11 8.207 0l3.799 3.612L15.801 0l2.168 1.11-5.939 5.942.002 3.933 4.063.002-.038 4.079-2.18-.003-3.871-3.674-3.871 3.674-2.18.003zm4.053 2.727h-.001l-3.888 3.696-2.168-1.109 4.007-3.981h.001l2.048 1.394zm0 0l2.048-1.394h.001l4.007 3.981-2.168 1.109-3.888-3.696z'); ?>
                </div>

                <!-- Links -->
                <div class="footer-links" style="margin-top:20px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;font-size:11px;color:#64748b;">
                    <a href="<?php echo esc_url($unsubscribe_url); ?>" style="color:#94a3b8;text-decoration:underline;">Unsubscribe</a>
                    <?php if ($manage_url) : ?>
                        &nbsp;&middot;&nbsp;
                        <a href="<?php echo esc_url($manage_url); ?>" style="color:#94a3b8;text-decoration:underline;">Manage Preferences</a>
                    <?php endif; ?>
                    &nbsp;&middot;&nbsp;
                    <a href="<?php echo esc_url(home_url('/privacy-policy')); ?>" style="color:#94a3b8;text-decoration:underline;">Privacy Policy</a>
                    <br>
                    &copy; <?php echo esc_html(date('Y')); ?> Reasons to Stay. All rights reserved.
                </div>

            </div><!-- /.footer-wrapper -->

        </div><!-- /.paper-card -->
        <!--[if mso]>
        </td></tr></table>
        <![endif]-->
    </div><!-- /.email-wrapper -->
</body>
</html>
        <?php
        return ob_get_clean();
    }

    /**
     * Render a single SVG social icon as an inline link.
     *
     * @param string $label Accessible label.
     * @param string $url   Link destination.
     * @param string $path  SVG path data (24x24 viewBox).
     * @return string
     */
    private function render_social_icon($label, $url, $path) {
        $gold = '#FCA311';
        return sprintf(
            '<a href="%s" target="_blank" rel="noopener noreferrer" title="%s" style="display:inline-block;margin:0 6px;vertical-align:middle;text-decoration:none;">
                <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="%s" style="display:block;">
                    <path d="%s"/>
                </svg>
            </a>',
            esc_url($url),
            esc_attr($label),
            esc_attr($gold),
            esc_attr($path)
        );
    }

    /**
     * Load a template file from the templates directory.
     *
     * @param string $template_name
     * @return string
     */
    private function get_template_content($template_name) {
        $file = plugin_dir_path(dirname(__FILE__)) . 'templates/' . $template_name . '.php';
        if (file_exists($file)) {
            ob_start();
            include $file;
            return ob_get_clean();
        }
        return '';
    }
}

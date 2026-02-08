<?php

class RTS_Email_Renderer {

    private $logo_url = 'https://reasonstostay.inkfire.co.uk/wp-content/uploads/2026/01/cropped-5-messages-to-send-instead-of-how-are-you-1-300x300.png';

    public function render($template_name, $data = array()) {
        $content = $this->get_template_content($template_name);

        // Merge header and footer
        $full_html = $this->get_header() . $content . $this->get_footer($data);

        // Replace variables
        foreach ($data as $key => $value) {
            $full_html = str_replace('{{' . $key . '}}', $value, $full_html);
        }

        return $full_html;
    }

    private function get_header() {
        ob_start();
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <style>
                body { font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; background-color: #F8F1E9; margin: 0; padding: 0; color: #1A1A1A; }
                .email-container { max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 8px; overflow: hidden; margin-top: 20px; margin-bottom: 20px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
                .header { background-color: #070C13; padding: 30px; text-align: center; border-bottom: 4px solid #FCA311; }
                .logo { max-width: 150px; height: auto; }
                .content { padding: 40px 30px; line-height: 1.6; font-size: 16px; }
                .footer { background-color: #f4f4f4; padding: 20px; text-align: center; font-size: 12px; color: #666; }
                .button { display: inline-block; padding: 12px 24px; background-color: #070C13; color: #ffffff !important; text-decoration: none; border-radius: 4px; font-weight: bold; margin-top: 20px; }
            </style>
        </head>
        <body>
            <div class="email-container">
                <div class="header">
                    <img src="<?php echo esc_url($this->logo_url); ?>" alt="Reasons to Stay" class="logo">
                </div>
                <div class="content">
        <?php
        return ob_get_clean();
    }

    private function get_footer($data) {
        $unsubscribe_url = isset($data['unsubscribe_url']) ? $data['unsubscribe_url'] : '#';
        ob_start();
        ?>
                </div>
                <div class="footer">
                    <p>You are receiving this because you signed up for Reasons to Stay.</p>
                    <p>
                        <a href="<?php echo esc_url($unsubscribe_url); ?>" style="color: #666; text-decoration: underline;">Unsubscribe</a> |
                        <a href="<?php echo home_url('/privacy-policy'); ?>" style="color: #666; text-decoration: underline;">Privacy Policy</a>
                    </p>
                    <p>&copy; <?php echo date('Y'); ?> Reasons to Stay. All rights reserved.</p>
                </div>
            </div>
        </body>
        </html>
        <?php
        return ob_get_clean();
    }

    private function get_template_content($template_name) {
        // Simple template loader
        $file = plugin_dir_path(dirname(__FILE__)) . 'templates/' . $template_name . '.php';
        if (file_exists($file)) {
            ob_start();
            include $file;
            return ob_get_clean();
        }
        return '';
    }
}

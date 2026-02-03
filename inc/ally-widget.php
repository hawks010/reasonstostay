<?php
/**
 * Plugin Name: RTS Accessibility Toolkit
 * Plugin URI: https://reasonstostay.com
 * Description: Comprehensive WCAG 2.2 AA compliant accessibility toolkit with iOS Control Centre design. Fixed layout engine.
 * Version: 2.3.6
 * Author: Foundation by Sonny × Inkfire
 * Author URI: https://reasonstostay.com
 * Text Domain: rts-a11y
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Accessibility Toolkit Class
if (!class_exists('RTS_Accessibility_Toolkit')) {
    
class RTS_Accessibility_Toolkit {
    
    private static $instance = null;
    private $version = '2.3.6';
    private $option_name = 'rts_foundation_a11y_settings';
    
    /**
     * Singleton instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_footer', [$this, 'render_toolkit']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_options_page(
            esc_html__('Accessibility Toolkit', 'rts-a11y'),
            esc_html__('Accessibility', 'rts-a11y'),
            'manage_options',
            'rts-a11y-settings',
            [$this, 'render_admin_page']
        );
    }
    
    /**
     * Register settings
     */
    public function register_settings() {
        register_setting(
            'rts_a11y_settings_group',
            $this->option_name,
            [
                'type' => 'array',
                'sanitize_callback' => [$this, 'sanitize_settings'],
                'default' => [
                    'enabled' => true,
                    'save_preferences' => true,
                    'load_fontawesome' => true
                ]
            ]
        );
    }
    
    /**
     * Sanitize settings
     */
    public function sanitize_settings($input) {
        $sanitized = [];
        $sanitized['enabled'] = isset($input['enabled']) ? (bool) $input['enabled'] : false;
        $sanitized['save_preferences'] = isset($input['save_preferences']) ? (bool) $input['save_preferences'] : false;
        $sanitized['load_fontawesome'] = isset($input['load_fontawesome']) ? (bool) $input['load_fontawesome'] : false;
        return $sanitized;
    }
    
    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets($hook) {
        if ('settings_page_rts-a11y-settings' !== $hook) {
            return;
        }
        wp_enqueue_style('rts-a11y-admin', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css', [], '6.4.0');
    }
    
    /**
     * Enqueue frontend assets
     */
    public function enqueue_assets() {
        $settings = get_option($this->option_name, ['enabled' => true, 'load_fontawesome' => true]);
        if (!isset($settings['enabled']) || !$settings['enabled']) {
            return;
        }
        
        // Load Font Awesome if enabled
        if (isset($settings['load_fontawesome']) && $settings['load_fontawesome']) {
            wp_enqueue_style('font-awesome', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css', [], '6.4.0');
        }
    }

    /**
     * Render admin page
     */
    public function render_admin_page() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'rts-a11y'));
        }
        
        $settings = get_option($this->option_name, [
            'enabled' => true, 
            'save_preferences' => true,
            'load_fontawesome' => true
        ]);
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <form method="post" action="options.php">
                <?php
                settings_fields('rts_a11y_settings_group');
                ?>
                
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="rts_enabled"><?php esc_html_e('Enable Toolkit', 'rts-a11y'); ?></label>
                        </th>
                        <td>
                            <input type="checkbox" 
                                   id="rts_enabled" 
                                   name="<?php echo esc_attr($this->option_name); ?>[enabled]" 
                                   value="1" 
                                   <?php checked(isset($settings['enabled']) ? $settings['enabled'] : true); ?> />
                            <p class="description">
                                <?php esc_html_e('Display the accessibility toolkit on the front end.', 'rts-a11y'); ?>
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="rts_save_prefs"><?php esc_html_e('Save Preferences', 'rts-a11y'); ?></label>
                        </th>
                        <td>
                            <input type="checkbox" 
                                   id="rts_save_prefs" 
                                   name="<?php echo esc_attr($this->option_name); ?>[save_preferences]" 
                                   value="1" 
                                   <?php checked(isset($settings['save_preferences']) ? $settings['save_preferences'] : true); ?> />
                            <p class="description">
                                <?php esc_html_e('Remember user accessibility preferences in localStorage.', 'rts-a11y'); ?>
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="rts_load_fa"><?php esc_html_e('Load Font Awesome', 'rts-a11y'); ?></label>
                        </th>
                        <td>
                            <input type="checkbox" 
                                   id="rts_load_fa" 
                                   name="<?php echo esc_attr($this->option_name); ?>[load_fontawesome]" 
                                   value="1" 
                                   <?php checked(isset($settings['load_fontawesome']) ? $settings['load_fontawesome'] : true); ?> />
                            <p class="description">
                                <?php esc_html_e('Load Font Awesome for toolkit icons. Disable if your theme already includes it.', 'rts-a11y'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(); ?>
            </form>
            
            <hr>
            
            <h2><?php esc_html_e('Features Status', 'rts-a11y'); ?></h2>
            <p><strong>Version:</strong> <?php echo esc_html($this->version); ?></p>
            <div class="card" style="max-width: 600px; padding: 20px;">
                <h3><?php esc_html_e('Active Modules', 'rts-a11y'); ?></h3>
                <ul>
                    <li>✅ <strong><?php esc_html_e('Text-to-Speech:', 'rts-a11y'); ?></strong> <?php esc_html_e('Natural-sounding voice synthesis.', 'rts-a11y'); ?></li>
                    <li>✅ <strong><?php esc_html_e('Smart Zoom:', 'rts-a11y'); ?></strong> <?php esc_html_e('Scale content without breaking layout.', 'rts-a11y'); ?></li>
                    <li>✅ <strong><?php esc_html_e('Typography:', 'rts-a11y'); ?></strong> <?php esc_html_e('Dyslexia font, line height, and spacing controls.', 'rts-a11y'); ?></li>
                    <li>✅ <strong><?php esc_html_e('Visual Filters:', 'rts-a11y'); ?></strong> <?php esc_html_e('High Contrast, Dark Mode, Monochrome, Saturation, Sepia.', 'rts-a11y'); ?></li>
                    <li>✅ <strong><?php esc_html_e('Reading Aids:', 'rts-a11y'); ?></strong> <?php esc_html_e('Reading Ruler, Big Cursor, Focus Mode.', 'rts-a11y'); ?></li>
                </ul>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render toolkit on frontend
     */
    public function render_toolkit() {
        $settings = get_option($this->option_name, ['enabled' => true, 'save_preferences' => true]);
        
        $enabled = !isset($settings['enabled']) || $settings['enabled'];
        
        if (!$enabled) {
            return;
        }
        
        $save_prefs = isset($settings['save_preferences']) && $settings['save_preferences'];
        
        $this->render_styles();
        $this->render_markup();
        $this->render_scripts($save_prefs);
    }
    
    /**
     * Render CSS
     */
    private function render_styles() {
        ?>
        <style id="rts-a11y-styles">
            /* === BRAND COLORS & VARS === */
            :root {
                --rts-dark: #070C13;
                --rts-orange: #FCA311;
                --rts-cream: #F1E3D3;
                --rts-white: #FFFFFF;
                
                /* Control Centre Theme */
                --rts-cc-bg: rgba(245, 245, 247, 0.95);
                --rts-cc-module-bg: rgba(255, 255, 255, 0.8);
                --rts-cc-module-hover: #FFFFFF;
                --rts-cc-text: #1C1C1E;
                --rts-cc-text-sub: rgba(60, 60, 67, 0.6);
                --rts-cc-text-active: #FFF;
                --rts-cc-shadow: 0 20px 60px rgba(0, 0, 0, 0.2);
                
                /* Grid Settings */
                --rts-cc-cols: 4;
                --rts-cc-gap: 12px;
            }

            /* === WIDGET ISOLATION === */
            /* Ensure the widget is never affected by site styles */
            #rts-container {
                all: initial; 
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
                position: fixed;
                z-index: 2147483647; /* Max Z-index */
                pointer-events: none; /* Let clicks pass through container area */
                top: 0;
                left: 0;
                width: 100%;
                height: 0;
                overflow: visible;
                filter: none !important;
                transform: none !important;
                zoom: 1 !important;
            }

            #rts-container * {
                box-sizing: border-box;
                pointer-events: auto; /* Re-enable clicks on buttons */
            }

            /* === GLOBAL UTILITIES === */
            .rts-sr-only {
                position: absolute;
                width: 1px;
                height: 1px;
                padding: 0;
                margin: -1px;
                overflow: hidden;
                clip: rect(0, 0, 0, 0);
                white-space: nowrap;
                border: 0;
            }

            /* === READING RULER === */
            #rts-ruler {
                position: fixed; /* Fixed is better for screen overlays than absolute */
                left: 0;
                width: 100%;
                height: 60px;
                background: rgba(252, 163, 17, 0.15);
                z-index: 2147483646;
                pointer-events: none;
                display: none;
                border-top: 2px solid var(--rts-orange);
                border-bottom: 2px solid var(--rts-orange);
                /* The large shadow dims the rest of the screen */
                box-shadow: 0 0 0 9999px rgba(0, 0, 0, 0.5);
            }
            html.rts-ruler #rts-ruler {
                display: block;
            }

            #rts-ruler .rts-ruler-close {
                position: absolute;
                right: 20px;
                top: 50%;
                transform: translateY(-50%);
                width: 30px;
                height: 30px;
                border-radius: 50%;
                background: var(--rts-dark);
                color: #fff;
                border: 2px solid var(--rts-orange);
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 18px;
                line-height: 1;
                pointer-events: auto;
                cursor: pointer;
            }

            /* === MINIMIZED PILL === */
            .rts-a11y-pill {
                position: fixed;
                right: 10px;
                top: 75%;
                transform: translateY(-50%);
                display: flex;
                flex-direction: column;
                gap: 12px;
                padding: 8px;
                background: var(--rts-dark);
                border-radius: 50px;
                border: 2px solid var(--rts-orange);
                box-shadow: 0 8px 30px rgba(0, 0, 0, 0.25);
                transition: opacity 0.3s ease, right 0.3s ease;
            }

            .rts-a11y-pill:hover {
                opacity: 1;
            }

            .rts-a11y-quick {
                width: 44px;
                height: 44px;
                border-radius: 50%;
                background: rgba(255, 255, 255, 0.1);
                border: none;
                color: var(--rts-white);
                display: flex;
                align-items: center;
                justify-content: center;
                cursor: pointer;
                transition: all 0.2s ease;
                font-size: 18px;
            }

            .rts-a11y-quick:hover {
                background: var(--rts-orange);
                color: var(--rts-dark);
                transform: scale(1.1);
            }

            .rts-a11y-quick[aria-pressed="true"] {
                background: var(--rts-orange);
                color: var(--rts-dark);
                box-shadow: 0 0 15px rgba(252, 163, 17, 0.4);
            }

            .rts-a11y-quick:focus-visible {
                outline: 2px solid var(--rts-white);
                outline-offset: 2px;
            }

            /* Ensure pill buttons are visible by default */
            .rts-a11y-pill .rts-a11y-quick {
                display: flex;
            }

            /* === EXPANDED PANEL === */
            .rts-a11y-panel {
                position: fixed;
                top: auto; /* Bottom aligned */
                bottom: 10px;
                right: -520px; /* Hidden state */
                width: min(420px, calc(100vw - 40px));
                display: flex;
                flex-direction: column;
                background: var(--rts-cc-bg);
                backdrop-filter: blur(20px);
                -webkit-backdrop-filter: blur(20px);
                border: 1px solid rgba(255, 255, 255, 0.2);
                border-radius: 32px;
                padding: 24px;
                box-shadow: var(--rts-cc-shadow);
                opacity: 0;
                visibility: hidden;
                transition: all 0.4s cubic-bezier(0.19, 1, 0.22, 1);
                /* Adjust panel height to fit without scrolling */
                max-height: 85vh;
                overflow: hidden;
            }

            .rts-a11y-panel.rts-open {
                right: 10px;
                opacity: 1;
                visibility: visible;
            }

            /* Header */
            .rts-cc-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 20px;
                flex-shrink: 0;
            }

            .rts-cc-title {
                font-size: 1.5rem;
                font-weight: 800;
                color: var(--rts-cc-text);
                margin: 0;
                line-height: 1.1;
            }

            .rts-cc-subtitle {
                font-size: 0.75rem;
                font-weight: 600;
                color: var(--rts-cc-text-sub);
                text-transform: uppercase;
                letter-spacing: 0.05em;
                display: block;
                margin-top: 4px;
            }
            
            /* Link styling for accessibility footer in header */
            .rts-cc-subtitle a {
                color: var(--rts-orange) !important;
                text-decoration: none !important;
                font-weight: 600 !important;
                transition: opacity 0.2s ease !important;
            }
            .rts-cc-subtitle a:hover,
            .rts-cc-subtitle a:focus {
                opacity: 0.8 !important;
                text-decoration: underline !important;
            }

            .rts-cc-close {
                width: 36px;
                height: 36px;
                border-radius: 50%;
                background: rgba(0, 0, 0, 0.06);
                border: none;
                color: var(--rts-cc-text);
                display: flex;
                align-items: center;
                justify-content: center;
                cursor: pointer;
                transition: background 0.2s;
                font-size: 18px;
            }

            .rts-cc-close:hover {
                background: rgba(0, 0, 0, 0.12);
            }

            /* Content & Grid */
            .rts-cc-content {
                flex: 1;
                /* Adjust max-height to ensure internal scrolling if needed */
                max-height: calc(85vh - 140px);
                overflow-y: auto;
                padding-right: 8px; /* Space for scrollbar */
                -webkit-overflow-scrolling: touch;
            }

            .rts-cc-grid {
                display: grid;
                grid-template-columns: repeat(4, 1fr);
                gap: var(--rts-cc-gap);
                padding-bottom: 20px;
            }

            /* Modules */
            .rts-cc-module {
                background: var(--rts-cc-module-bg);
                border: 1px solid rgba(0,0,0,0.04);
                border-radius: 18px;
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                padding: 12px;
                cursor: pointer;
                transition: all 0.2s ease;
                min-height: 90px;
                width: 100%;
                color: var(--rts-cc-text);
            }

            .rts-cc-module:hover {
                background: var(--rts-cc-module-hover);
                transform: translateY(-2px);
                box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            }

            .rts-cc-module:active {
                transform: translateY(0);
            }

            .rts-cc-module.active,
            .rts-cc-module[aria-pressed="true"] {
                background: var(--rts-orange);
                color: var(--rts-cc-text-active);
                border-color: transparent;
            }

            .rts-cc-label {
                font-size: 0.75rem;
                font-weight: 600;
                text-align: center;
                margin-top: 8px;
                line-height: 1.2;
            }

            .rts-cc-module.active .rts-cc-label,
            .rts-cc-module[aria-pressed="true"] .rts-cc-label {
                color: var(--rts-cc-text-active);
            }

            .icon-circle {
                width: 32px;
                height: 32px;
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 16px;
                margin-bottom: 0;
            }
            
            /* TTS button active state */
            #tts-btn.active .icon-circle {
                background: #fff !important;
                color: var(--rts-orange) !important;
            }

            /* Spans */
            .rts-cc-wide { grid-column: span 2; flex-direction: row; gap: 12px; justify-content: flex-start; padding-left: 16px; }
            .rts-cc-large { grid-column: span 2; flex-direction: row; gap: 12px; }
            .rts-cc-toggle { grid-column: span 1; }

            /* Sliders */
            .rts-cc-slider-box {
                grid-column: span 2;
                background: var(--rts-cc-module-bg);
                border-radius: 18px;
                padding: 12px;
                display: flex;
                flex-direction: column;
                justify-content: space-between;
            }

            .rts-cc-slider-header {
                display: flex;
                justify-content: space-between;
                font-size: 0.75rem;
                font-weight: 600;
                color: var(--rts-cc-text-sub);
                margin-bottom: 8px;
                text-transform: uppercase;
            }

            .rts-cc-value {
                color: var(--rts-cc-text);
                font-weight: 800;
            }

            .rts-cc-controls {
                display: flex;
                gap: 8px;
            }

            .rts-cc-btn {
                flex: 1;
                height: 36px;
                border-radius: 10px;
                border: 1px solid rgba(0,0,0,0.1);
                background: rgba(255,255,255,0.5);
                display: flex;
                align-items: center;
                justify-content: center;
                cursor: pointer;
                color: var(--rts-cc-text);
                transition: all 0.2s;
            }
            
            .rts-cc-btn:hover {
                background: #fff;
            }

            .rts-cc-btn:active {
                background: var(--rts-orange);
                color: #fff;
                border-color: transparent;
            }

            /* === DARK MODE === */
            @media (prefers-color-scheme: dark) {
                :root {
                    --rts-cc-bg: rgba(28, 28, 30, 0.95);
                    --rts-cc-module-bg: rgba(255, 255, 255, 0.12);
                    --rts-cc-module-hover: rgba(255, 255, 255, 0.18);
                    --rts-cc-text: #FFF;
                    --rts-cc-text-sub: rgba(255, 255, 255, 0.5);
                }
                .rts-cc-btn {
                    background: rgba(255,255,255,0.05);
                    border-color: rgba(255,255,255,0.1);
                }
                .rts-cc-btn:hover {
                    background: rgba(255,255,255,0.2);
                }
                .rts-cc-close {
                    background: #c36;
                }
                .rts-cc-close:hover {
                    background: #FCA311;
                }
            }

            /* === MOBILE === */
            @media (max-width: 768px) {
                .rts-a11y-pill {
                    top: auto;
                    bottom: 10px;
                    right: 10px;
                    transform: none;
                    flex-direction: row;
                    height: auto;
                    width: auto;
                    border-radius: 30px;
                    padding: 6px;
                    align-items: center;
                    justify-content: center;
                    z-index: 2147483647;
                }
                
                .rts-a11y-panel {
                    top: auto;
                    bottom: 0;
                    right: 0;
                    left: 0;
                    width: 100%;
                    max-width: 100%;
                    border-radius: 24px 24px 0 0;
                    transform: translateY(105%);
                    transition: transform 0.3s cubic-bezier(0.19, 1, 0.22, 1);
                }
                .rts-a11y-panel.rts-open {
                    transform: translateY(0);
                }

                .rts-cc-grid {
                    grid-template-columns: repeat(2, 1fr);
                }
                
                .rts-cc-wide, .rts-cc-large {
                    grid-column: span 2 !important;
                }
                
                .rts-cc-slider-box {
                    grid-column: span 2 !important;
                }
                
                /* Nested Mobile Styles */
                @media (max-width: 480px) {
                    .rts-a11y-pill {
                        width: 56px;
                        height: 56px;
                        border-radius: 50%;
                        padding: 0;
                    }
                    
                    .rts-a11y-pill .rts-a11y-quick:not(#expand-btn) {
                        display: none;
                    }
                    
                    .rts-a11y-pill:hover .rts-a11y-quick,
                    .rts-a11y-pill:focus-within .rts-a11y-quick {
                        display: flex;
                    }
                }
            }

            /* === ACCESSIBILITY ADAPTATIONS (Applied to HTML/BODY via HTML class) === */
            
            /* FIX: Dyslexia Font - More aggressive override */
            html.rts-dyslexia,
            html.rts-dyslexia body,
            html.rts-dyslexia body *:not(i):not(.fa):not(.fas):not(.far):not(.fal):not(.fab):not([class*="icon"]):not([class*="Icon"]) {
                font-family: 'OpenDyslexic', 'Comic Sans MS', 'Chalkboard SE', sans-serif !important;
            }
            
            html.rts-font-boost body :is(p, li, span, div, a, button, input, textarea, select, label, h1, h2, h3, h4, h5, h6) {
                font-size: calc(100% + var(--rts-font-add, 0%)) !important;
            }
            html.rts-weight-boost body :is(p, li, h1, h2, h3, h4, h5, h6, span, div, a) {
                font-weight: var(--rts-weight-value, 400) !important;
            }
            html.rts-lineheight-boost body :is(p, li, h1, h2, h3, h4, h5, h6, div, span) {
                line-height: var(--rts-lineheight-value, 1.8) !important;
            }
            html.rts-spacing-boost body :is(p, li, h1, h2, h3, h4, h5, h6, div, span) {
                letter-spacing: var(--rts-spacing-value, 0.12em) !important;
                word-spacing: 0.16em !important;
            }
            html.rts-textalign body :is(p, li, h1, h2, h3, h4, h5, h6, div) {
                text-align: center !important;
            }
            html.rts-links body a {
                text-decoration: underline !important;
                font-weight: 700 !important;
                color: var(--rts-orange) !important;
            }
            
            /* Visual Filters - Applied to HTML */
            html.rts-contrast {
                filter: contrast(1.5);
            }
            
            html.rts-darkmode {
                filter: invert(1) hue-rotate(180deg);
                background-color: #f0f0f0;
            }
            /* Double invert to restore images/videos */
            html.rts-darkmode img, 
            html.rts-darkmode video, 
            html.rts-darkmode iframe {
                filter: invert(1) hue-rotate(180deg);
            }
            
            /* Combined Dark Mode Filters */
            html.rts-darkmode.rts-contrast {
                filter: invert(1) hue-rotate(180deg) contrast(1.5);
            }

            html.rts-darkmode.rts-monochrome {
                filter: invert(1) hue-rotate(180deg) grayscale(100%);
            }

            html.rts-darkmode.rts-saturate {
                filter: invert(1) hue-rotate(180deg) saturate(200%);
            }

            html.rts-darkmode.rts-calm {
                filter: invert(1) hue-rotate(180deg) sepia(30%) grayscale(20%);
            }

            /* Restore images/videos for combined filters */
            html.rts-darkmode.rts-contrast img,
            html.rts-darkmode.rts-contrast video,
            html.rts-darkmode.rts-contrast iframe,
            html.rts-darkmode.rts-monochrome img,
            html.rts-darkmode.rts-monochrome video,
            html.rts-darkmode.rts-monochrome iframe,
            html.rts-darkmode.rts-saturate img,
            html.rts-darkmode.rts-saturate video,
            html.rts-darkmode.rts-saturate iframe,
            html.rts-darkmode.rts-calm img,
            html.rts-darkmode.rts-calm video,
            html.rts-darkmode.rts-calm iframe {
                filter: invert(1) hue-rotate(180deg);
            }
            
            /* Ensure widget has its own background in dark mode */
            html.rts-darkmode #rts-container {
                background: transparent !important;
            }
            html.rts-darkmode .rts-a11y-pill,
            html.rts-darkmode .rts-a11y-panel {
                background: var(--rts-dark) !important;
                color: var(--rts-white) !important;
            }
            
            html.rts-monochrome {
                filter: grayscale(100%);
            }
            
            html.rts-saturate {
                filter: saturate(200%);
            }
            
            html.rts-calm {
                filter: sepia(30%) grayscale(20%);
                background-color: #fffff0;
            }
            
            html.rts-nomotion *,
            html.rts-nomotion *::before,
            html.rts-nomotion *::after {
                animation: none !important;
                transition: none !important;
                scroll-behavior: auto !important;
            }
            
            html.rts-bigcursor,
            html.rts-bigcursor * {
                cursor: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewport="0 0 48 48" style="fill:black;stroke:white;stroke-width:2px;"><path d="M0 0 L0 32 L10 22 L20 32 L24 28 L14 18 L28 18 Z" /></svg>'), auto !important;
            }
            
            /* Focus Mode - Applied to HTML, affect BODY */
            html.rts-focus body::before {
                display: none;
            }
            /* Instead, implement focus mode as a reading guide */
            html.rts-focus {
                cursor: none !important;
            }
            html.rts-focus *:hover {
                background-color: rgba(252, 163, 17, 0.1) !important;
                outline: 2px solid var(--rts-orange) !important;
            }

            /* Scroll Lock when Panel Open */
            body.rts-panel-open {
                overflow: hidden !important;
            }
        </style>
        <?php
    }
    
    /**
     * Render HTML markup
     */
    private function render_markup() {
        ?>
        <div id="rts-container">
            <!-- Screen Reader Live Region -->
            <div id="rts-live" class="rts-sr-only" role="status" aria-live="polite"></div>
            
            <!-- Minimized Pill -->
            <div class="rts-a11y-pill" id="rts-pill">
                <button type="button" class="rts-a11y-quick" id="quick-tts" aria-label="<?php esc_attr_e('Text to speech', 'rts-a11y'); ?>" title="<?php esc_attr_e('Text to speech', 'rts-a11y'); ?>">
                    <i class="fa-solid fa-volume-high" aria-hidden="true"></i>
                </button>
                
                <button type="button" class="rts-a11y-quick" id="quick-dyslexia" data-toggle="dyslexia" aria-label="<?php esc_attr_e('Dyslexia font', 'rts-a11y'); ?>" title="<?php esc_attr_e('Dyslexia font', 'rts-a11y'); ?>">
                    <i class="fa-solid fa-font" aria-hidden="true"></i>
                </button>
                
                <button type="button" class="rts-a11y-quick" id="quick-ruler" data-toggle="ruler" aria-label="<?php esc_attr_e('Reading ruler', 'rts-a11y'); ?>" title="<?php esc_attr_e('Reading ruler', 'rts-a11y'); ?>">
                    <i class="fa-solid fa-ruler-horizontal" aria-hidden="true"></i>
                </button>
                
                <button type="button" class="rts-a11y-quick" id="quick-contrast" data-toggle="contrast" aria-label="<?php esc_attr_e('Invert colors', 'rts-a11y'); ?>" title="<?php esc_attr_e('Invert colors', 'rts-a11y'); ?>">
                    <i class="fa-solid fa-circle-half-stroke" aria-hidden="true"></i>
                </button>
                
                <button type="button" class="rts-a11y-quick" id="expand-btn" aria-expanded="false" aria-controls="rts-panel" aria-label="<?php esc_attr_e('Open accessibility menu', 'rts-a11y'); ?>">
                    <i class="fa-solid fa-universal-access" aria-hidden="true"></i>
                </button>
            </div>
            
            <!-- Main Panel -->
            <div class="rts-a11y-panel" id="rts-panel" role="dialog" aria-modal="true" aria-labelledby="rts-panel-title">
                <div class="rts-cc-header">
                    <div>
                        <h2 class="rts-cc-title" id="rts-panel-title"><?php esc_html_e('Accessibility Tools', 'rts-a11y'); ?></h2>
                        <span class="rts-cc-subtitle">
                            <?php 
                            printf(
                                esc_html__('by %s', 'rts-a11y'),
                                '<a href="https://inkfire.co.uk" target="_blank" rel="noopener noreferrer">Sonny × Inkfire</a>'
                            );
                            ?>
                        </span>
                    </div>
                    <button type="button" class="rts-cc-close" id="close-btn" aria-label="<?php esc_attr_e('Close menu', 'rts-a11y'); ?>">
                        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                    </button>
                </div>
                
                <div class="rts-cc-content">
                    <div class="rts-cc-grid">
                        <!-- Row 1: TTS (2x1) + Font Size (2x1) -->
                        <button type="button" class="rts-cc-module rts-cc-large" id="tts-btn" aria-pressed="false">
                            <div class="icon-circle" style="background:var(--rts-orange); color:#fff;"><i class="fa-solid fa-volume-high"></i></div>
                            <span class="rts-cc-label"><?php esc_html_e('Read Aloud', 'rts-a11y'); ?></span>
                        </button>
                        
                        <div class="rts-cc-slider-box">
                            <div class="rts-cc-slider-header">
                                <span><?php esc_html_e('Font Size', 'rts-a11y'); ?></span>
                                <span class="rts-cc-value" id="val-font">100%</span>
                            </div>
                            <div class="rts-cc-controls">
                                <button type="button" class="rts-cc-btn" id="font-dec"><i class="fa-solid fa-minus"></i></button>
                                <button type="button" class="rts-cc-btn" id="font-inc"><i class="fa-solid fa-plus"></i></button>
                            </div>
                        </div>

                        <!-- Row 2: Zoom (2x1) + Line Height (2x1) -->
                        <div class="rts-cc-slider-box">
                            <div class="rts-cc-slider-header">
                                <span><?php esc_html_e('Page Zoom', 'rts-a11y'); ?></span>
                                <span class="rts-cc-value" id="val-zoom">100%</span>
                            </div>
                            <div class="rts-cc-controls">
                                <button type="button" class="rts-cc-btn" id="zoom-dec"><i class="fa-solid fa-magnifying-glass-minus"></i></button>
                                <button type="button" class="rts-cc-btn" id="zoom-inc"><i class="fa-solid fa-magnifying-glass-plus"></i></button>
                            </div>
                        </div>

                        <div class="rts-cc-slider-box">
                            <div class="rts-cc-slider-header">
                                <span><?php esc_html_e('Line Height', 'rts-a11y'); ?></span>
                                <span class="rts-cc-value" id="val-line">1.8</span>
                            </div>
                            <div class="rts-cc-controls">
                                <button type="button" class="rts-cc-btn" id="line-dec"><i class="fa-solid fa-arrow-down-short-wide"></i></button>
                                <button type="button" class="rts-cc-btn" id="line-inc"><i class="fa-solid fa-arrow-up-wide-short"></i></button>
                            </div>
                        </div>

                        <!-- Row 3: 4 Toggle buttons -->
                        <button type="button" class="rts-cc-module rts-cc-toggle" data-toggle="dyslexia">
                            <div class="icon-circle" style="background:#E2E8F0; color:#2D3748;"><i class="fa-solid fa-font"></i></div>
                            <span class="rts-cc-label"><?php esc_html_e('Dyslexia', 'rts-a11y'); ?></span>
                        </button>
                        
                        <button type="button" class="rts-cc-module rts-cc-toggle" data-toggle="contrast">
                            <div class="icon-circle" style="background:#000; color:#fff;"><i class="fa-solid fa-circle-half-stroke"></i></div>
                            <span class="rts-cc-label"><?php esc_html_e('Invert', 'rts-a11y'); ?></span>
                        </button>
                        
                        <button type="button" class="rts-cc-module rts-cc-toggle" data-toggle="darkmode">
                            <div class="icon-circle" style="background:#1A202C; color:#fff;"><i class="fa-solid fa-moon"></i></div>
                            <span class="rts-cc-label"><?php esc_html_e('Dark', 'rts-a11y'); ?></span>
                        </button>
                        
                        <button type="button" class="rts-cc-module rts-cc-toggle" data-toggle="monochrome">
                            <div class="icon-circle" style="background:#4A5568; color:#fff;"><i class="fa-solid fa-droplet-slash"></i></div>
                            <span class="rts-cc-label"><?php esc_html_e('Mono', 'rts-a11y'); ?></span>
                        </button>

                        <!-- Row 4: 4 More toggle buttons -->
                        <button type="button" class="rts-cc-module rts-cc-toggle" data-toggle="saturate">
                            <div class="icon-circle" style="background:#C53030; color:#fff;"><i class="fa-solid fa-droplet"></i></div>
                            <span class="rts-cc-label"><?php esc_html_e('Vivid', 'rts-a11y'); ?></span>
                        </button>

                        <button type="button" class="rts-cc-module rts-cc-toggle" data-toggle="calm">
                            <div class="icon-circle" style="background:#FEFCBF; color:#744210;"><i class="fa-solid fa-mug-hot"></i></div>
                            <span class="rts-cc-label"><?php esc_html_e('Calm', 'rts-a11y'); ?></span>
                        </button>
                        
                        <button type="button" class="rts-cc-module rts-cc-toggle" data-toggle="links">
                            <div class="icon-circle" style="background:#2B6CB0; color:#fff;"><i class="fa-solid fa-link"></i></div>
                            <span class="rts-cc-label"><?php esc_html_e('Links', 'rts-a11y'); ?></span>
                        </button>
                        
                        <button type="button" class="rts-cc-module rts-cc-toggle" data-toggle="textalign">
                            <div class="icon-circle" style="background:#CBD5E0; color:#2D3748;"><i class="fa-solid fa-align-center"></i></div>
                            <span class="rts-cc-label"><?php esc_html_e('Align', 'rts-a11y'); ?></span>
                        </button>

                        <!-- Row 5: Letter Spacing (2x1) + 2 more toggle buttons -->
                        <div class="rts-cc-slider-box">
                            <div class="rts-cc-slider-header">
                                <span><?php esc_html_e('Letter Spacing', 'rts-a11y'); ?></span>
                                <span class="rts-cc-value" id="val-space">Normal</span>
                            </div>
                            <div class="rts-cc-controls">
                                <button type="button" class="rts-cc-btn" id="space-dec"><i class="fa-solid fa-compress"></i></button>
                                <button type="button" class="rts-cc-btn" id="space-inc"><i class="fa-solid fa-expand"></i></button>
                            </div>
                        </div>

                        <button type="button" class="rts-cc-module rts-cc-toggle" data-toggle="bigcursor">
                            <div class="icon-circle" style="background:#B2F5EA; color:#234E52;"><i class="fa-solid fa-arrow-pointer"></i></div>
                            <span class="rts-cc-label"><?php esc_html_e('Cursor', 'rts-a11y'); ?></span>
                        </button>

                        <button type="button" class="rts-cc-module rts-cc-toggle" data-toggle="ruler">
                            <div class="icon-circle" style="background:#FBD38D; color:#744210;"><i class="fa-solid fa-ruler-horizontal"></i></div>
                            <span class="rts-cc-label"><?php esc_html_e('Ruler', 'rts-a11y'); ?></span>
                        </button>

                        <button type="button" class="rts-cc-module rts-cc-toggle" data-toggle="focus">
                            <div class="icon-circle" style="background:#FED7D7; color:#742A2A;"><i class="fa-solid fa-eye"></i></div>
                            <span class="rts-cc-label"><?php esc_html_e('Focus', 'rts-a11y'); ?></span>
                        </button>

                        <button type="button" class="rts-cc-module rts-cc-toggle" data-toggle="nomotion">
                            <div class="icon-circle" style="background:#C6F6D5; color:#22543D;"><i class="fa-solid fa-ban"></i></div>
                            <span class="rts-cc-label"><?php esc_html_e('No Motion', 'rts-a11y'); ?></span>
                        </button>

                        <!-- Row 6: Reset button (2x1) at bottom -->
                        <button type="button" class="rts-cc-module rts-cc-large" id="reset-btn" style="grid-column: span 2; background: #FFF5F5; border-color: #FEB2B2;">
                            <div class="icon-circle" style="background:#C53030; color:#fff;"><i class="fa-solid fa-rotate-left"></i></div>
                            <span class="rts-cc-label" style="color:#C53030;"><?php esc_html_e('Reset All', 'rts-a11y'); ?></span>
                        </button>
                        
                        <!-- Empty space to balance grid -->
                        <div style="grid-column: span 2;"></div>
                    </div>
                </div>
                
                <!-- Footer removed as requested -->
            </div>

            <!-- Reading Ruler Overlay -->
            <div id="rts-ruler" aria-hidden="true">
                <button type="button" class="rts-ruler-close" aria-label="<?php esc_attr_e('Close ruler', 'rts-a11y'); ?>">×</button>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render JavaScript
     */
    private function render_scripts($save_prefs) {
        ?>
        <script id="rts-a11y-scripts">
        (function() {
            'use strict';
            
            const CONFIG = {
                save: <?php echo $save_prefs ? 'true' : 'false'; ?>,
                key: 'rts_a11y_v2_prefs',
                selectors: {
                    html: document.documentElement,
                    body: document.body,
                    container: document.getElementById('rts-container'),
                    panel: document.getElementById('rts-panel'),
                    ruler: document.getElementById('rts-ruler'),
                    live: document.getElementById('rts-live')
                }
            };

            // State - note: zoom is percentage (50-200), others are steps (0-4)
            let state = {
                font: 0,    // 0 = 100%
                zoom: 100,  // %
                lineHeight: 0, // 0 = default (no added boost)
                spacing: 0, // 0 = default
                toggles: {}
            };
            
            // === UTILS ===
            function announce(msg) {
                if (!CONFIG.selectors.live) return;
                
                // Clear and set with a slight delay for screen readers
                CONFIG.selectors.live.textContent = '';
                setTimeout(() => {
                    CONFIG.selectors.live.textContent = msg;
                    // Clear after 2 seconds to prevent stale announcements
                    setTimeout(() => {
                        if (CONFIG.selectors.live.textContent === msg) {
                            CONFIG.selectors.live.textContent = '';
                        }
                    }, 2000);
                }, 100);
            }

            function saveState() {
                if (!CONFIG.save) return;
                try {
                    localStorage.setItem(CONFIG.key, JSON.stringify(state));
                } catch(e) { console.warn('RTS: Save failed', e); }
            }

            function loadState() {
                if (!CONFIG.save) return;
                try {
                    const saved = localStorage.getItem(CONFIG.key);
                    if (saved) {
                        const parsed = JSON.parse(saved);
                        if (typeof parsed === 'object') {
                            state = { ...state, ...parsed };
                            applyAll();
                        }
                    }
                } catch(e) { console.warn('RTS: Load failed', e); }
            }

            // === CORE FUNCTIONS ===
            
            // 1. Toggles (Always Apply to HTML)
            function toggleFeature(feature, force = null) {
                const html = document.documentElement;
                const cls = 'rts-' + feature;
                const active = (force !== null) ? force : !html.classList.contains(cls);
                
                if (active) html.classList.add(cls);
                else html.classList.remove(cls);
                
                state.toggles[feature] = active;
                
                // Button UI
                document.querySelectorAll(`[data-toggle="${feature}"]`).forEach(btn => {
                    btn.setAttribute('aria-pressed', active);
                    if (active) btn.classList.add('active');
                    else btn.classList.remove('active');
                });

                saveState();
            }

            // 2. Font Size (Percentage Based)
            function updateFont() {
                const percent = (state.font * 10); // 0, 10, 20, 30, 40
                document.body.style.setProperty('--rts-font-add', percent + '%');
                document.getElementById('val-font').textContent = (100 + percent) + '%';
                
                if (state.font > 0) document.documentElement.classList.add('rts-font-boost');
                else document.documentElement.classList.remove('rts-font-boost');
                
                saveState();
            }

            // 3. Page Zoom (Fix: Correct CSS Zoom Implementation)
            function updateZoom() {
                const zoomPercent = state.zoom;
                
                // Clear any previous zoom/transform styles
                document.body.style.zoom = '';
                document.body.style.transform = '';
                document.body.style.transformOrigin = '';
                document.body.style.width = '';
                
                if (state.zoom === 100) {
                    document.getElementById('val-zoom').textContent = '100%';
                    saveState();
                    return;
                }
                
                // Use CSS zoom if available (Chrome, Safari, Edge)
                if ('zoom' in document.body.style) {
                    document.body.style.zoom = zoomPercent + '%';
                } else {
                    // Fallback: CSS transform scale
                    const scale = zoomPercent / 100;
                    document.body.style.transform = `scale(${scale})`;
                    document.body.style.transformOrigin = 'top center';
                    // Optional: prevent horizontal scroll
                    document.body.style.width = `${100 / scale}%`;
                }
                
                document.getElementById('val-zoom').textContent = zoomPercent + '%';
                saveState();
            }

            // 4. Line Height
            function updateLineHeight() {
                // Steps: 0 (Default), 1 (1.8), 2 (2.0), 3 (2.2), 4 (2.4)
                if (state.lineHeight === 0) {
                     document.documentElement.classList.remove('rts-lineheight-boost');
                     document.body.style.removeProperty('--rts-lineheight-value');
                     document.getElementById('val-line').textContent = 'Normal';
                } else {
                     document.documentElement.classList.add('rts-lineheight-boost');
                     const val = 1.6 + (state.lineHeight * 0.2); // Base 1.8 roughly
                     document.body.style.setProperty('--rts-lineheight-value', val);
                     document.getElementById('val-line').textContent = val.toFixed(1);
                }
                saveState();
            }

            // 5. Letter Spacing
            function updateSpacing() {
                // Steps: 0 (Normal), 1 (Medium), 2 (Wide)
                if (state.spacing === 0) {
                    document.documentElement.classList.remove('rts-spacing-boost');
                    document.body.style.removeProperty('--rts-spacing-value');
                    document.getElementById('val-space').textContent = 'Normal';
                } else {
                    document.documentElement.classList.add('rts-spacing-boost');
                    const val = (state.spacing * 0.12) + 'em';
                    document.body.style.setProperty('--rts-spacing-value', val);
                    document.getElementById('val-space').textContent = (state.spacing === 1) ? 'Med' : 'Wide';
                }
                saveState();
            }

            function applyAll() {
                Object.keys(state.toggles).forEach(k => toggleFeature(k, state.toggles[k]));
                updateFont();
                updateZoom();
                updateLineHeight();
                updateSpacing();
            }

            function resetAll() {
                state = { font: 0, zoom: 100, lineHeight: 0, spacing: 0, toggles: {} };
                
                // Clear all rts classes from HTML
                const html = document.documentElement;
                const classes = [...html.classList];
                classes.forEach(c => {
                    if (c.startsWith('rts-')) html.classList.remove(c);
                });
                
                // Clear styles
                document.body.style.removeProperty('--rts-font-add');
                document.body.style.removeProperty('--rts-lineheight-value');
                document.body.style.removeProperty('--rts-spacing-value');
                if ('zoom' in document.body.style) document.body.style.zoom = '';
                document.body.style.transform = '';
                document.body.style.width = '';
                
                // Reset UI active states
                document.querySelectorAll('.rts-cc-module').forEach(btn => btn.classList.remove('active'));
                document.querySelectorAll('[aria-pressed]').forEach(b => b.setAttribute('aria-pressed', 'false'));
                
                // Reset Sliders
                document.getElementById('val-font').textContent = '100%';
                document.getElementById('val-zoom').textContent = '100%';
                document.getElementById('val-line').textContent = 'Normal';
                document.getElementById('val-space').textContent = 'Normal';

                if (tts.active) tts.stop();
                
                // Hide ruler explicitly via CSS class removal (handled in toggleFeature loop above)

                announce('All settings reset.');
                saveState();
            }

            // === TEXT TO SPEECH ===
            const tts = {
                active: false,
                utterance: null,
                voices: [], // Add voices storage
                init() {
                    if (!window.speechSynthesis) return;
                    
                    const loadVoices = () => {
                        this.voices = speechSynthesis.getVoices();
                    };
                    
                    loadVoices();
                    if (speechSynthesis.onvoiceschanged !== undefined) {
                        speechSynthesis.onvoiceschanged = loadVoices;
                    }
                },
                speak() {
                    if (this.active) { this.stop(); return; }
                    
                    // Improved Selector
                    const contentEl = document.querySelector('article, [role="main"], .entry-content, .post-content, main');
                    // Fallback if no main content found
                    const text = contentEl ? contentEl.innerText : document.body.innerText.substring(0, 1000); 
                    
                    if (!text || text.length < 5) {
                        announce('No readable content found.');
                        return;
                    }

                    this.utterance = new SpeechSynthesisUtterance(text);
                    
                    // Use stored voices
                    if (this.voices && this.voices.length > 0) {
                        const preferred = this.voices.find(v => v.lang.startsWith('en') && v.name.includes('Google')) || this.voices[0];
                        if (preferred) this.utterance.voice = preferred;
                    }
                    
                    this.utterance.onstart = () => {
                        this.active = true;
                        const btn = document.getElementById('tts-btn');
                        btn.classList.add('active');
                        btn.setAttribute('aria-pressed', 'true');
                        btn.querySelector('.rts-cc-label').textContent = 'Stop Reading';
                    };
                    
                    this.utterance.onend = () => this.stop();
                    this.utterance.onerror = () => this.stop();
                    
                    speechSynthesis.speak(this.utterance);
                },
                stop() {
                    speechSynthesis.cancel();
                    this.active = false;
                    const btn = document.getElementById('tts-btn');
                    btn.classList.remove('active');
                    btn.setAttribute('aria-pressed', 'false');
                    btn.querySelector('.rts-cc-label').textContent = 'Read Page Aloud';
                }
            };

            // === EVENTS ===
            document.addEventListener('DOMContentLoaded', () => {
                
                const panel = CONFIG.selectors.panel;
                const expand = document.getElementById('expand-btn');
                const close = document.getElementById('close-btn');

                function open() {
                    panel.classList.add('rts-open');
                    expand.setAttribute('aria-expanded', 'true');
                    document.body.classList.add('rts-panel-open');
                    // Focus Trap Fix
                    const focusable = panel.querySelectorAll('button');
                    if (focusable.length > 0) {
                        setTimeout(() => {
                            // Find first non-close button to focus
                            const firstFocusable = Array.from(focusable).find(btn => btn.id !== 'close-btn') || focusable[0];
                            firstFocusable.focus();
                        }, 100);
                    }
                }

                function closePanel() {
                    panel.classList.remove('rts-open');
                    expand.setAttribute('aria-expanded', 'false');
                    document.body.classList.remove('rts-panel-open');
                    expand.focus();
                }

                expand.addEventListener('click', open);
                close.addEventListener('click', closePanel);
                
                // Keyboard navigation for panel
                expand.addEventListener('keydown', (e) => {
                    if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault();
                        open();
                    }
                });
                
                document.addEventListener('click', (e) => {
                    if (panel.classList.contains('rts-open') && 
                        !panel.contains(e.target) && 
                        !expand.contains(e.target)) {
                        closePanel();
                    }
                });
                
                // Escape key handler
                document.addEventListener('keydown', (e) => {
                    if (e.key === 'Escape' && panel.classList.contains('rts-open')) {
                        closePanel();
                    }
                });

                document.querySelectorAll('[data-toggle]').forEach(btn => {
                    btn.addEventListener('click', () => toggleFeature(btn.dataset.toggle));
                    btn.addEventListener('keydown', (e) => {
                        if (e.key === 'Enter' || e.key === ' ') {
                            e.preventDefault();
                            toggleFeature(btn.dataset.toggle);
                        }
                    });
                });
                
                // New Pill Button Listeners
                document.getElementById('quick-tts').addEventListener('click', () => tts.speak());

                document.getElementById('reset-btn').addEventListener('click', resetAll);
                
                // Font Controls
                document.getElementById('font-inc').addEventListener('click', () => {
                    if (state.font < 4) { state.font++; updateFont(); }
                });
                document.getElementById('font-dec').addEventListener('click', () => {
                    if (state.font > 0) { state.font--; updateFont(); }
                });

                // Zoom Controls
                document.getElementById('zoom-inc').addEventListener('click', () => {
                    if (state.zoom < 200) { state.zoom += 10; updateZoom(); }
                });
                document.getElementById('zoom-dec').addEventListener('click', () => {
                    if (state.zoom > 50) { state.zoom -= 10; updateZoom(); }
                });

                // Line Height Controls
                document.getElementById('line-inc').addEventListener('click', () => {
                    if (state.lineHeight < 4) { state.lineHeight++; updateLineHeight(); }
                });
                document.getElementById('line-dec').addEventListener('click', () => {
                    if (state.lineHeight > 0) { state.lineHeight--; updateLineHeight(); }
                });

                // Spacing Controls
                document.getElementById('space-inc').addEventListener('click', () => {
                    if (state.spacing < 2) { state.spacing++; updateSpacing(); }
                });
                document.getElementById('space-dec').addEventListener('click', () => {
                    if (state.spacing > 0) { state.spacing--; updateSpacing(); }
                });

                // Ruler Movement (Fix: Bounded to Viewport)
                document.addEventListener('mousemove', (e) => {
                    if (state.toggles['ruler']) {
                        const ruler = CONFIG.selectors.ruler;
                        const viewportHeight = window.innerHeight;
                        let top = e.clientY - 30;
                        
                        // Keep ruler within viewport
                        if (top < 0) top = 0;
                        if (top > viewportHeight - 60) top = viewportHeight - 60;
                        
                        ruler.style.top = top + 'px';
                    }
                });
                document.querySelector('.rts-ruler-close').addEventListener('click', (e) => {
                    e.stopPropagation();
                    toggleFeature('ruler', false);
                });

                // TTS
                tts.init();
                document.getElementById('tts-btn').addEventListener('click', () => tts.speak());

                // Mobile Swipe
                let touchStartY = 0;
                panel.addEventListener('touchstart', e => touchStartY = e.touches[0].clientY, {passive: true});
                panel.addEventListener('touchmove', e => {
                    if (e.touches[0].clientY - touchStartY > 150) closePanel();
                }, {passive: true});

                // Load
                loadState();
            });

        })();
        </script>
        <?php
    }
}

// Initialize
RTS_Accessibility_Toolkit::get_instance();

} // end class_exists
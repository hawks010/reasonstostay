<?php
/**
 * Plugin Name: RTS Accessibility Toolkit
 * Plugin URI: https://reasonstostay.com
 * Description: Comprehensive WCAG 2.2 AA compliant accessibility toolkit with iOS Control Centre design
 * Version: 2.1.0
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
    private $version = '2.1.0';
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
                    'save_preferences' => true
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
        return $sanitized;
    }
    
    /**
     * Enqueue assets
     */
    public function enqueue_assets() {
        $settings = get_option($this->option_name, ['enabled' => true]);
        if (!isset($settings['enabled']) || !$settings['enabled']) {
            return;
        }
        // No external assets loaded here (Font Awesome is enqueued by the theme; dyslexia mode uses system fonts).
    }

    /**
     * Render admin page
     */
    public function render_admin_page() {
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'rts-a11y'));
        }
        
        $settings = get_option($this->option_name, ['enabled' => true, 'save_preferences' => true]);
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <form method="post" action="options.php">
                <?php
                settings_fields('rts_a11y_settings_group');
                do_settings_sections('rts_a11y_settings_group');
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
                                <?php esc_html_e('Display the accessibility toolkit on the front end', 'rts-a11y'); ?>
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
                                <?php esc_html_e('Remember user accessibility preferences in localStorage', 'rts-a11y'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(); ?>
            </form>
            
            <hr>
            
            <h2><?php esc_html_e('Features', 'rts-a11y'); ?></h2>
            <ul>
                <li><strong><?php esc_html_e('Text-to-Speech:', 'rts-a11y'); ?></strong> <?php esc_html_e('Natural-sounding voice reads letter content', 'rts-a11y'); ?></li>
                <li><strong><?php esc_html_e('Font Size:', 'rts-a11y'); ?></strong> <?php esc_html_e('Adjustable text scaling (100%-175%)', 'rts-a11y'); ?></li>
                <li><strong><?php esc_html_e('Font Weight:', 'rts-a11y'); ?></strong> <?php esc_html_e('Five weight levels from Normal to Extra Bold', 'rts-a11y'); ?></li>
                <li><strong><?php esc_html_e('Line Spacing:', 'rts-a11y'); ?></strong> <?php esc_html_e('Adjustable line height for easier reading', 'rts-a11y'); ?></li>
                <li><strong><?php esc_html_e('Page Zoom:', 'rts-a11y'); ?></strong> <?php esc_html_e('Entire page layout zoom (100%, 110%, 125%, 150%)', 'rts-a11y'); ?></li>
                <li><strong><?php esc_html_e('Dyslexia Font:', 'rts-a11y'); ?></strong> <?php esc_html_e('OpenDyslexic typeface', 'rts-a11y'); ?></li>
                <li><strong><?php esc_html_e('Focus Mode:', 'rts-a11y'); ?></strong> <?php esc_html_e('Highlight content, dim distractions', 'rts-a11y'); ?></li>
                <li><strong><?php esc_html_e('Invert Colors:', 'rts-a11y'); ?></strong> <?php esc_html_e('High contrast mode', 'rts-a11y'); ?></li>
                <li><strong><?php esc_html_e('Reading Ruler:', 'rts-a11y'); ?></strong> <?php esc_html_e('Mouse-following line guide', 'rts-a11y'); ?></li>
                <li><strong><?php esc_html_e('Calm Mode:', 'rts-a11y'); ?></strong> <?php esc_html_e('Grayscale filter', 'rts-a11y'); ?></li>
                <li><strong><?php esc_html_e('Link Highlight:', 'rts-a11y'); ?></strong> <?php esc_html_e('Enhanced link visibility', 'rts-a11y'); ?></li>
                <li><strong><?php esc_html_e('Stop Animations:', 'rts-a11y'); ?></strong> <?php esc_html_e('Disable all motion', 'rts-a11y'); ?></li>
                <li><strong><?php esc_html_e('Text Align:', 'rts-a11y'); ?></strong> <?php esc_html_e('Toggle center alignment', 'rts-a11y'); ?></li>
                <li><strong><?php esc_html_e('Monochrome:', 'rts-a11y'); ?></strong> <?php esc_html_e('High contrast black & white', 'rts-a11y'); ?></li>
                <li><strong><?php esc_html_e('Large Cursor:', 'rts-a11y'); ?></strong> <?php esc_html_e('Enlarged cursor', 'rts-a11y'); ?></li>
                <li><strong><?php esc_html_e('Dark Mode:', 'rts-a11y'); ?></strong> <?php esc_html_e('Inverted colors for dark theme', 'rts-a11y'); ?></li>
                <li><strong><?php esc_html_e('Color Saturation:', 'rts-a11y'); ?></strong> <?php esc_html_e('Boost color intensity', 'rts-a11y'); ?></li>
            </ul>
            
            <p><small><?php esc_html_e('WCAG 2.2 AA Compliant | iOS Control Centre Design | Foundation by Sonny × Inkfire', 'rts-a11y'); ?></small></p>
        </div>
        <?php
    }
    
    /**
     * Render toolkit on frontend
     */
    public function render_toolkit() {
        $settings = get_option($this->option_name, ['enabled' => true, 'save_preferences' => true]);
        
        // Default to enabled if not set
        $enabled = !isset($settings['enabled']) || $settings['enabled'];
        
        if (!$enabled) {
            return;
        }
        
        $save_prefs = isset($settings['save_preferences']) && $settings['save_preferences'];
        // Check if we're on a single post/page - works for any post type
        $is_single = is_singular();
        
        $this->render_styles();
        $this->render_markup($is_single);
        $this->render_scripts($save_prefs);
    }
    
    /**
     * Render CSS
     */
    private function render_styles() {
        ?>
        <style id="rts-a11y-styles">
/* === BRAND COLORS === */
:root {
    --rts-dark: #070C13;
    --rts-orange: #FCA311;
    --rts-cream: #F1E3D3;
    --rts-white: #FFFFFF;
    
    /* Control Centre Theme */
    --rts-cc-bg: rgba(245, 245, 247, 0.98);
    --rts-cc-module-bg: rgba(255, 255, 255, 0.6);
    --rts-cc-module-hover: rgba(255, 255, 255, 0.8);
    --rts-cc-text: #1C1C1E;
    --rts-cc-text-sub: rgba(60, 60, 67, 0.6);
    --rts-cc-text-active: #FFF;
    --rts-cc-shadow: 0 20px 60px rgba(0, 0, 0, 0.15);
    
    /* Grid Settings */
    --rts-cc-cols: 4;
    --rts-cc-tile: 84px;
    --rts-cc-gap: 14px;
}


        /* =========================================================
           PAGE WRAPPER (so page effects never affect the widget)
           ========================================================= */
        #rts-a11y-sitewrap{
            position: relative;
            width: 100%;
            min-height: 100%;
        }

        /* Never allow filters/transforms to affect the widget itself */
        #rts-container,
        #rts-container *{
            filter: none !important;
            transform: none;
        }


/* === UTILITY === */
.rts-sr-only {
    position: absolute !important;
    width: 1px !important;
    height: 1px !important;
    padding: 0 !important;
    margin: -1px !important;
    overflow: hidden !important;
    clip: rect(0, 0, 0, 0) !important;
    white-space: nowrap !important;
    border: 0 !important;
}

/* Reading Ruler */
#rts-ruler {
    position: fixed;
    left: 0;
    width: 100%;
    height: 60px;
    background: rgba(252, 163, 17, 0.15);
    z-index: 999998;
    pointer-events: none;
    display: none;
    border-top: 2px solid var(--rts-orange);
    border-bottom: 2px solid var(--rts-orange);
    box-shadow: 0 0 0 9999px rgba(0, 0, 0, 0.5);
}
#rts-a11y-sitewrap.rts-ruler #rts-ruler {
    display: block;
}

#rts-ruler .rts-ruler-close{
    position:absolute;
    right:10px;
    top:10px;
    width:34px;
    height:34px;
    border-radius:999px;
    background: var(--rts-dark);
    color:#fff;
    border:2px solid var(--rts-orange);
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:20px;
    line-height:1;
    pointer-events:auto;
    cursor:pointer;
}
#rts-ruler .rts-ruler-close:focus{
    outline:3px solid #fff;
    outline-offset:2px;
}

/* === MINIMIZED PILL === */
.rts-a11y-pill {
    position: fixed;
    right: 20px;
    top: 35%;
    transform: translateY(-50%);
    display: flex;
    flex-direction: column;
    gap: 12px;
    z-index: 999999;
    padding: 10px;
    background: var(--rts-dark);
    border-radius: 50px;
    border: 2px solid var(--rts-orange);
    box-shadow: 0 8px 30px rgba(0, 0, 0, 0.25);
    transition: opacity 0.3s ease;
}

/* Remove hover transform - it's jarring */
.rts-a11y-pill:hover {
    opacity: 0.95;
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

/* === EXPANDED PANEL === */
.rts-a11y-panel {
    position: fixed;
    top: 15px;
    bottom: 15px;
    right: -500px;
    width: min(400px, calc(100vw - 30px));
    display: flex;
    flex-direction: column;
    background: var(--rts-cc-bg);
    backdrop-filter: blur(40px) saturate(180%);
    -webkit-backdrop-filter: blur(40px) saturate(180%);
    border: 1px solid rgba(0, 0, 0, 0.1);
    border-radius: 32px;
    padding: 20px;
    box-shadow: var(--rts-cc-shadow);
    z-index: 1000000;
    opacity: 0;
    visibility: hidden;
    transition: all 0.4s cubic-bezier(0.32, 0.72, 0, 1);
    overflow: hidden;
}

.rts-a11y-panel.rts-open {
    right: 15px;
    opacity: 1;
    visibility: visible;
}

/* Header - make more compact */
.rts-cc-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
    padding: 0;
    flex-shrink: 0;
}

.rts-cc-header-content {
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.rts-cc-title {
    font-size: 1.2rem;
    font-weight: 800;
    color: var(--rts-cc-text);
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    letter-spacing: -0.02em;
    line-height: 1.2;
}

.rts-cc-subtitle {
    font-size: 0.7rem;
    font-weight: 600;
    color: var(--rts-cc-text-sub);
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}

.rts-cc-close {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: rgba(0, 0, 0, 0.06);
    border: none;
    color: var(--rts-cc-text-sub);
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.2s;
    flex-shrink: 0;
    font-size: 16px;
}

.rts-cc-close:hover {
    background: rgba(0, 0, 0, 0.1);
    color: var(--rts-cc-text);
}

.rts-cc-close:focus-visible {
    outline: 2px solid var(--rts-orange);
    outline-offset: 2px;
}

/* Content - NO SCROLLING, make it fit */
.rts-cc-content {
    flex: 1;
    overflow: visible;
    padding: 0;
    margin: 0;
    display: flex;
    flex-direction: column;
}

/* Grid - Use responsive grid */
.rts-cc-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 10px;
    grid-auto-rows: minmax(80px, auto);
    width: 100%;
}

/* 1x2 Horizontal Sliders - NEW STYLE */
.rts-cc-horizontal-slider {
    grid-column: span 1;
    grid-row: span 1;
    flex-direction: column;
    padding: 12px;
    justify-content: space-between;
}

.rts-cc-slider-controls {
    display: flex;
    justify-content: space-between;
    width: 100%;
    gap: 8px;
    margin-top: 8px;
}

.rts-cc-slider-btn.horizontal {
    flex: 1;
    background: var(--rts-cc-module-bg);
    border: 1px solid rgba(0, 0, 0, 0.08);
    border-radius: 10px;
    color: var(--rts-cc-text);
    font-size: 16px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s;
    padding: 6px 0;
    min-height: 36px;
}

.rts-cc-slider-btn.horizontal:hover {
    background: var(--rts-cc-module-hover);
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
}

.rts-cc-slider-btn.horizontal:active {
    transform: translateY(0);
}

.rts-cc-slider-btn.horizontal:focus-visible {
    outline: 2px solid var(--rts-orange);
    outline-offset: 2px;
}

.rts-cc-slider-label {
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--rts-cc-text-sub);
    text-align: center;
    margin-bottom: 6px;
    text-transform: uppercase;
    letter-spacing: 0.03em;
}

.rts-cc-value-display {
    font-size: 1rem;
    font-weight: 700;
    color: var(--rts-cc-text);
    text-align: center;
    min-height: 24px;
    display: flex;
    align-items: center;
    justify-content: center;
}

/* Modules - Make them flex to fit */
.rts-cc-module {
    background: var(--rts-cc-module-bg);
    border: 1px solid rgba(0, 0, 0, 0.06);
    border-radius: 16px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 4px;
    padding: 10px;
    cursor: pointer;
    transition: all 0.2s ease;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    -webkit-tap-highlight-color: transparent;
    min-height: 80px;
    width: 100%;
}

.rts-cc-module:hover {
    background: var(--rts-cc-module-hover);
    transform: scale(1.02);
}

.rts-cc-module:active {
    transform: scale(0.98);
}

.rts-cc-module:focus-visible {
    outline: 2px solid var(--rts-orange);
    outline-offset: 2px;
}

.rts-cc-module[aria-pressed="true"] {
    background: var(--rts-orange);
    color: var(--rts-cc-text-active);
    border-color: transparent;
    box-shadow: 0 6px 20px rgba(252, 163, 17, 0.4);
}

/* Module Labels - Ensure text fits */
.rts-cc-label {
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--rts-cc-text);
    text-align: center;
    line-height: 1.1;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    word-break: break-word;
    hyphens: auto;
    max-width: 100%;
}

.rts-cc-module[aria-pressed="true"] .rts-cc-label {
    color: var(--rts-cc-text-active);
}

/* 2x2 Large Module (TTS) - Adjust for smaller screens */
.rts-cc-large {
    grid-column: span 2;
    grid-row: span 1;
    padding: 12px;
}

.rts-cc-icon {
    font-size: 1.8rem;
    color: var(--rts-orange);
    margin-bottom: 6px;
}

.rts-cc-large .rts-cc-label {
    font-size: 0.85rem;
    font-weight: 700;
    -webkit-line-clamp: 1;
}

/* 1x2 Vertical Sliders - Make horizontal on small screens */
.rts-cc-slider-vertical {
    grid-column: span 1;
    grid-row: span 1;
    flex-direction: row;
    padding: 8px;
}

.rts-cc-slider-btn {
    flex: 1;
    background: transparent;
    border: none;
    color: var(--rts-cc-text);
    font-size: 18px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s;
    border-radius: 8px;
    min-height: 40px;
}

.rts-cc-slider-btn:hover {
    background: rgba(0, 0, 0, 0.05);
    color: var(--rts-orange);
}

.rts-cc-slider-btn:focus-visible {
    outline: 2px solid var(--rts-orange);
    outline-offset: -2px;
}

.rts-cc-slider-btn.inc {
    border-bottom: none;
    border-right: 1px solid rgba(0, 0, 0, 0.05);
}

/* 1x1 Toggles */
.rts-cc-toggle {
    grid-column: span 1;
    grid-row: span 1;
}

/* 2x1 Wide Modules - Adjust layout */
.rts-cc-wide {
    grid-column: span 2;
    grid-row: span 1;
    flex-direction: row;
    justify-content: flex-start;
    gap: 8px;
    padding: 0 12px;
}

.rts-cc-wide .icon-circle {
    margin-bottom: 0;
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: rgba(0, 0, 0, 0.05);
    display: flex;
    align-items: center;
    justify-content: center;
    transition: background 0.2s;
    color: var(--rts-cc-text);
    flex-shrink: 0;
    font-size: 14px;
}

.rts-cc-wide[aria-pressed="true"] .icon-circle {
    background: rgba(255, 255, 255, 0.4);
    color: var(--rts-dark);
}

.rts-cc-wide .rts-cc-label {
    font-size: 0.8rem;
    font-weight: 600;
    -webkit-line-clamp: 1;
    text-align: left;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* Active state for zoom button */
#zoom-btn[data-zoom]:not([data-zoom="100"]) {
    background: var(--rts-orange);
    color: var(--rts-cc-text-active);
    border-color: transparent;
    box-shadow: 0 6px 20px rgba(252, 163, 17, 0.4);
}

#zoom-btn[data-zoom]:not([data-zoom="100"]) .icon-circle {
    background: rgba(255, 255, 255, 0.4);
    color: var(--rts-dark);
}

.rts-cc-toggle .icon-circle {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: rgba(0, 0, 0, 0.05);
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 4px;
    transition: background 0.2s;
    color: var(--rts-cc-text);
    flex-shrink: 0;
    font-size: 14px;
}

.rts-cc-toggle[aria-pressed="true"] .icon-circle {
    background: rgba(255, 255, 255, 0.4);
    color: var(--rts-dark);
}

/* TTS Active Animation */
.rts-cc-tts[aria-pressed="true"] {
    background: var(--rts-dark) !important;
    color: var(--rts-white) !important;
    animation: rts-pulse 2s infinite;
}

.rts-cc-tts[aria-pressed="true"] .rts-cc-icon {
    color: var(--rts-orange);
}

@keyframes rts-pulse {
    0% {
        box-shadow: 0 0 0 0 rgba(252, 163, 17, 0.4);
    }
    70% {
        box-shadow: 0 0 0 10px rgba(252, 163, 17, 0);
    }
    100% {
        box-shadow: 0 0 0 0 rgba(252, 163, 17, 0);
    }
}

/* Reset Button - styled like other toggle widgets */
.rts-cc-reset {
    /* Inherits all styles from .rts-cc-module and .rts-cc-toggle */
}

.rts-cc-reset:hover {
    background: rgba(220, 38, 38, 0.1);
    border-color: rgba(220, 38, 38, 0.3);
}

.rts-cc-reset .icon-circle {
    background: rgba(220, 38, 38, 0.1);
    color: #DC2626;
}

.rts-cc-reset:hover .icon-circle {
    background: rgba(220, 38, 38, 0.2);
}

/* Footer - ensure it's always visible at bottom */
.rts-cc-footer {
    margin-top: 16px;
    padding-top: 16px;
    border-top: 1px solid rgba(0, 0, 0, 0.06);
    text-align: center;
    flex-shrink: 0;
}

.rts-cc-credit {
    font-size: 0.75rem;
    font-weight: 500;
    color: var(--rts-cc-text-sub);
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    letter-spacing: 0.01em;
}

/* === ACCESSIBILITY MODES === */
/*
   IMPORTANT: These modes must affect the page, not just the widget.
   We apply classes on <html> via JS and counter-style the widget so it stays readable.
*/
#rts-a11y-sitewrap.rts-dyslexia {
    font-family: 'Comic Sans MS','Chalkboard SE','Comic Neue',Verdana,Arial,sans-serif;
    line-height: 1.6 !important;
}

#rts-a11y-sitewrap.rts-contrast {
    filter: contrast(1.35) saturate(1.15);
}
#rts-a11y-sitewrap.rts-contrast video {
    filter: invert(1) hue-rotate(180deg);
}
#rts-a11y-sitewrap.rts-contrast .rts-a11y-container {
    filter: invert(1) hue-rotate(180deg);
}

#rts-a11y-sitewrap.rts-darkmode {
    filter: invert(1) hue-rotate(180deg);
}
#rts-a11y-sitewrap.rts-darkmode video {
    filter: invert(1) hue-rotate(180deg);
}
#rts-a11y-sitewrap.rts-darkmode .rts-a11y-container {
    filter: invert(1) hue-rotate(180deg);
}

#rts-a11y-sitewrap.rts-saturate {
    filter: saturate(1.5);
}

#rts-a11y-sitewrap.rts-saturate .rts-a11y-pill,
#rts-a11y-sitewrap.rts-saturate .rts-a11y-panel {
    filter: none;
}

/* Use transform-based zoom for better browser support - applied to body via JS */
#rts-a11y-sitewrap.rts-zoom {
    /* Zoom class added for state tracking only */
}

#rts-a11y-sitewrap.rts-focus body::before {
    content: '';
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.7);
    z-index: 9998;
    pointer-events: none;
}

#rts-a11y-sitewrap.rts-focus main,
#rts-a11y-sitewrap.rts-focus article,
#rts-a11y-sitewrap.rts-focus [role="main"] {
    position: relative;
    z-index: 9999;
}

#rts-a11y-sitewrap.rts-calm {
    filter: grayscale(1);
}

#rts-a11y-sitewrap.rts-calm .rts-a11y-pill,
#rts-a11y-sitewrap.rts-calm .rts-a11y-panel {
    filter: none;
}

#rts-a11y-sitewrap.rts-monochrome {
    filter: grayscale(1) contrast(2);
}

#rts-a11y-sitewrap.rts-monochrome .rts-a11y-pill,
#rts-a11y-sitewrap.rts-monochrome .rts-a11y-panel {
    filter: none;
}

#rts-a11y-sitewrap.rts-font-boost :is(p, li, span, div, a, button, input, textarea, select, label) {
    font-size: calc(1em + var(--rts-font-add, 0px)) !important;
}

#rts-a11y-sitewrap.rts-weight-boost :is(p, li, h1, h2, h3, h4, h5, h6, span, div, a) {
    font-weight: var(--rts-weight-value, 400) !important;
}

#rts-a11y-sitewrap.rts-lineheight-boost :is(p, li, h1, h2, h3, h4, h5, h6) {
    line-height: var(--rts-lineheight-value, 1.8) !important;
}

#rts-a11y-sitewrap.rts-links a {
    color: var(--rts-orange) !important;
    text-decoration: underline !important;
    font-weight: 700 !important;
}

#rts-a11y-sitewrap.rts-nomotion *,
#rts-a11y-sitewrap.rts-nomotion *::before,
#rts-a11y-sitewrap.rts-nomotion *::after {
    animation: none !important;
    transition: none !important;
}

#rts-a11y-sitewrap.rts-bigcursor,
#rts-a11y-sitewrap.rts-bigcursor * {
    cursor: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 32 32"><path fill="%23000" stroke="%23FFF" stroke-width="2" d="M4 4 L4 28 L12 20 L16 28 L20 26 L16 18 L24 18 Z"/></svg>') 8 4, auto !important;
}

#rts-a11y-sitewrap.rts-textalign :is(p, li, h1, h2, h3, h4, h5, h6, div:not(.rts-a11y-pill):not(.rts-a11y-panel):not(.rts-cc-grid):not(.rts-cc-module)) {
    text-align: center !important;
}

/* Lock body scroll when panel open */
body.rts-panel-open {
    overflow: hidden;
}

/* === MOBILE RESPONSIVE === */
@media (max-width: 768px) {
    /* Mobile pill - single icon only in bottom right */
    .rts-a11y-pill {
        top: auto;
        bottom: 20px;
        right: 20px;
        transform: none;
        flex-direction: row;
        padding: 12px;
        border-radius: 50%;
        width: 56px;
        height: 56px;
        justify-content: center;
        align-items: center;
    }
    
    /* Hide quick action buttons on mobile - only show expand button */
    .rts-a11y-pill .rts-a11y-quick:not(#expand-btn) {
        display: none !important;
    }
    
    /* Make expand button fill the circle */
    .rts-a11y-pill #expand-btn {
        width: 100%;
        height: 100%;
        margin: 0;
        padding: 0;
    }
    
    /* Panel - full width at bottom */
    .rts-a11y-panel {
        top: auto;
        bottom: 0;
        right: 0;
        left: 0;
        width: 100%;
        max-width: none;
        height: auto;
        max-height: 85vh;
        padding: 16px;
        border-radius: 24px 24px 0 0;
        transform: translateY(110%);
    }
    
    .rts-a11y-panel.rts-open {
        transform: translateY(0);
    }
    
    /* Grid - 3 columns on mobile, all widgets 1x1 */
    .rts-cc-grid {
        grid-template-columns: repeat(3, 1fr) !important;
        gap: 8px;
        grid-auto-rows: minmax(85px, auto);
    }
    
    /* ALL modules become 1x1 on mobile - NO EXCEPTIONS */
    .rts-cc-module,
    .rts-cc-horizontal-slider,
    .rts-cc-wide,
    .rts-cc-large,
    .rts-cc-toggle {
        grid-column: span 1 !important;
        grid-row: span 1 !important;
        min-height: 85px;
        padding: 8px;
        border-radius: 14px;
        flex-direction: column;
        justify-content: center;
        align-items: center;
        gap: 4px;
    }
    
    /* Horizontal sliders become vertical with icon on mobile */
    .rts-cc-horizontal-slider {
        padding: 8px;
    }
    
    .rts-cc-slider-label {
        font-size: 0.65rem;
        margin-bottom: 2px;
    }
    
    .rts-cc-value-display {
        font-size: 0.85rem;
        min-height: 20px;
        margin-bottom: 4px;
    }
    
    .rts-cc-slider-controls {
        width: 100%;
        gap: 4px;
        margin-top: 4px;
    }
    
    .rts-cc-slider-btn.horizontal {
        min-height: 28px;
        font-size: 12px;
        border-radius: 8px;
    }
    
    /* Wide modules lose their horizontal layout */
    .rts-cc-wide {
        flex-direction: column;
        padding: 8px;
        gap: 4px;
    }
    
    .rts-cc-wide .icon-circle {
        margin-bottom: 2px;
        width: 28px;
        height: 28px;
        font-size: 13px;
    }
    
    .rts-cc-wide .rts-cc-label {
        text-align: center;
        font-size: 0.7rem;
    }
    
    /* Regular toggles */
    .rts-cc-toggle .icon-circle {
        width: 28px;
        height: 28px;
        font-size: 13px;
        margin-bottom: 2px;
    }
    
    .rts-cc-label {
        font-size: 0.7rem;
        -webkit-line-clamp: 2;
    }
    
    /* TTS on mobile */
    .rts-cc-large {
        padding: 8px;
    }
    
    .rts-cc-large .rts-cc-icon {
        font-size: 1.5rem;
        margin-bottom: 4px;
    }
    
    .rts-cc-large .rts-cc-label {
        font-size: 0.7rem;
    }
    
    /* Reset button - same as other widgets on mobile */
    .rts-cc-reset {
        /* Inherits from .rts-cc-module styles */
    }
    
    /* Footer - ensure signature shows */
    .rts-cc-footer {
        margin-top: 12px;
        padding-top: 12px;
    }
    
    .rts-cc-credit {
        font-size: 0.7rem;
    }
    
    /* Header adjustments */
    .rts-cc-title {
        font-size: 1.1rem;
    }
    
    .rts-cc-subtitle {
        font-size: 0.65rem;
    }
    
    /* Hide ruler on mobile */
    [data-toggle="ruler"] {
        display: none !important;
    }
}

/* === VERY SMALL SCREENS (Mobile portrait) === */
@media (max-width: 480px) {
    /* Keep 3-column grid even on small screens */
    .rts-cc-grid {
        grid-template-columns: repeat(3, 1fr) !important;
        gap: 6px;
    }
    
    .rts-cc-module,
    .rts-cc-horizontal-slider,
    .rts-cc-wide,
    .rts-cc-large,
    .rts-cc-toggle {
        min-height: 80px;
        padding: 6px;
    }
    
    .rts-cc-label {
        font-size: 0.65rem;
    }
    
    .rts-cc-value-display {
        font-size: 0.8rem;
    }
    
    .rts-cc-slider-btn.horizontal {
        min-height: 26px;
        font-size: 11px;
    }
    
    .rts-cc-icon {
        font-size: 1.3rem;
    }
}

/* === DARK MODE SUPPORT === */
@media (prefers-color-scheme: dark) {
    :root {
        --rts-cc-bg: rgba(28, 28, 30, 0.98);
        --rts-cc-module-bg: rgba(255, 255, 255, 0.1);
        --rts-cc-module-hover: rgba(255, 255, 255, 0.15);
        --rts-cc-text: #FFFFFF;
        --rts-cc-text-sub: rgba(255, 255, 255, 0.6);
    }
    
    .rts-cc-close:hover {
        background: rgba(255, 255, 255, 0.1);
    }
    
    .rts-cc-slider-btn:hover {
        background: rgba(255, 255, 255, 0.1);
        color: #fff;
    }
    
    .rts-cc-toggle .icon-circle {
        background: rgba(255, 255, 255, 0.1);
        color: #fff;
    }
    
    .rts-cc-wide .icon-circle {
        background: rgba(255, 255, 255, 0.1);
        color: #fff;
    }
    
    .rts-cc-large {
        background: rgba(255, 255, 255, 0.15);
    }
    
    .rts-cc-reset {
        border-color: rgba(255, 255, 255, 0.15);
    }
}

/* Touch-friendly hover states for mobile */
@media (hover: none) and (pointer: coarse) {
    .rts-cc-slider-btn.horizontal:hover {
        transform: none;
        box-shadow: none;
    }
    
    .rts-cc-module:hover {
        transform: none;
    }
}

/* === REDUCED MOTION === */
@media (prefers-reduced-motion: reduce) {
    *,
    *::before,
    *::after {
        animation-duration: 0.01ms !important;
        animation-iteration-count: 1 !important;
        transition-duration: 0.01ms !important;
    }
}
        </style>
        <?php
    }
    
    /**
     * Render HTML markup
     */
    private function render_markup($is_single) {
        ?>
        <div class="rts-a11y-container" id="rts-container">
            <!-- Live Region for Screen Readers -->
            <div id="rts-a11y-live" class="rts-sr-only" role="status" aria-live="polite" aria-atomic="true"></div>
            
            <!-- Reading Ruler -->
            <div id="rts-ruler" aria-hidden="true">
                <button type="button" class="rts-ruler-close" tabindex="-1" aria-label="Close ruler" title="Close ruler">×</button>
            </div>
            
            <!-- Minimized Pill -->
            <div class="rts-a11y-pill" id="rts-pill">
                <?php if ($is_single): ?>
                <button type="button" class="rts-a11y-quick" id="quick-tts" title="<?php esc_attr_e('Text to speech', 'rts-a11y'); ?>" aria-label="<?php esc_attr_e('Text to speech', 'rts-a11y'); ?>" aria-pressed="false">
                    <i class="fa-solid fa-circle-play" aria-hidden="true"></i>
                </button>
                <?php endif; ?>
                
                <button type="button" class="rts-a11y-quick" id="quick-dyslexia" title="<?php esc_attr_e('Dyslexia font', 'rts-a11y'); ?>" aria-label="<?php esc_attr_e('Dyslexia font', 'rts-a11y'); ?>" aria-pressed="false" data-toggle="dyslexia">
                    <i class="fa-solid fa-book-open" aria-hidden="true"></i>
                </button>
                
                <button type="button" class="rts-a11y-quick" id="quick-focus" title="<?php esc_attr_e('Focus mode', 'rts-a11y'); ?>" aria-label="<?php esc_attr_e('Focus mode', 'rts-a11y'); ?>" aria-pressed="false" data-toggle="focus">
                    <i class="fa-solid fa-eye" aria-hidden="true"></i>
                </button>
                
                <button type="button" class="rts-a11y-quick" id="expand-btn" title="<?php esc_attr_e('Open accessibility menu', 'rts-a11y'); ?>" aria-label="<?php esc_attr_e('Open accessibility menu', 'rts-a11y'); ?>" aria-expanded="false" aria-controls="rts-panel">
                    <i class="fa-solid fa-universal-access" aria-hidden="true"></i>
                </button>
            </div>
            
            <!-- Expanded Control Panel -->
            <div class="rts-a11y-panel" id="rts-panel" role="dialog" aria-labelledby="rts-title" aria-modal="false">
                <div class="rts-cc-header">
                    <div class="rts-cc-header-content">
                        <h2 class="rts-cc-title" id="rts-title"><?php esc_html_e('Reading Assist', 'rts-a11y'); ?></h2>
                        <span class="rts-cc-subtitle"><?php esc_html_e('A11Y TOOLS', 'rts-a11y'); ?></span>
                    </div>
                    <button type="button" class="rts-cc-close" id="close-btn" aria-label="<?php esc_attr_e('Close accessibility menu', 'rts-a11y'); ?>">
                        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                    </button>
                </div>
                
                <div class="rts-cc-content">
                    <div class="rts-cc-grid">
                        <!-- Row 1: TTS (2x1) + Font Size (1x1) + Font Weight (1x1) -->
                        <?php if ($is_single): ?>
                        <button type="button" class="rts-cc-module rts-cc-large rts-cc-tts" id="tts-btn" aria-pressed="false" aria-label="<?php esc_attr_e('Text to speech', 'rts-a11y'); ?>">
                            <i class="fa-solid fa-circle-play rts-cc-icon" aria-hidden="true"></i>
                            <span class="rts-cc-label"><?php esc_html_e('Listen to Letter', 'rts-a11y'); ?></span>
                        </button>
                        <?php else: ?>
                        <div style="grid-column: span 2; grid-row: span 1;" aria-hidden="true"></div>
                        <?php endif; ?>
                        
                        <!-- Font Size (1x1 Horizontal Slider) -->
                        <div class="rts-cc-module rts-cc-horizontal-slider" id="font-size-control">
                            <div class="rts-cc-slider-label"><?php esc_html_e('Font Size', 'rts-a11y'); ?></div>
                            <div class="rts-cc-value-display" id="font-size-value">100%</div>
                            <div class="rts-cc-slider-controls">
                                <button type="button" class="rts-cc-slider-btn horizontal" id="font-decrease" aria-label="<?php esc_attr_e('Decrease font size', 'rts-a11y'); ?>">
                                    <i class="fa-solid fa-minus" aria-hidden="true"></i>
                                </button>
                                <button type="button" class="rts-cc-slider-btn horizontal" id="font-increase" aria-label="<?php esc_attr_e('Increase font size', 'rts-a11y'); ?>">
                                    <i class="fa-solid fa-plus" aria-hidden="true"></i>
                                </button>
                            </div>
                        </div>
                        
                        <!-- Font Weight (1x1 Horizontal Slider) -->
                        <div class="rts-cc-module rts-cc-horizontal-slider" id="font-weight-control">
                            <div class="rts-cc-slider-label"><?php esc_html_e('Font Weight', 'rts-a11y'); ?></div>
                            <div class="rts-cc-value-display" id="font-weight-value"><?php esc_html_e('Normal', 'rts-a11y'); ?></div>
                            <div class="rts-cc-slider-controls">
                                <button type="button" class="rts-cc-slider-btn horizontal" id="weight-decrease" aria-label="<?php esc_attr_e('Decrease font weight', 'rts-a11y'); ?>">
                                    <i class="fa-solid fa-minus" aria-hidden="true"></i>
                                </button>
                                <button type="button" class="rts-cc-slider-btn horizontal" id="weight-increase" aria-label="<?php esc_attr_e('Increase font weight', 'rts-a11y'); ?>">
                                    <i class="fa-solid fa-plus" aria-hidden="true"></i>
                                </button>
                            </div>
                        </div>
                        
                        <!-- Row 2: Line Height (1x1) + Dyslexia Font (2x1) + Invert (1x1) -->
                        
                        <!-- Line Height (1x1 Horizontal Slider) -->
                        <div class="rts-cc-module rts-cc-horizontal-slider" id="line-height-control">
                            <div class="rts-cc-slider-label"><?php esc_html_e('Line Spacing', 'rts-a11y'); ?></div>
                            <div class="rts-cc-value-display" id="line-height-value">1.5</div>
                            <div class="rts-cc-slider-controls">
                                <button type="button" class="rts-cc-slider-btn horizontal" id="line-decrease" aria-label="<?php esc_attr_e('Decrease line spacing', 'rts-a11y'); ?>">
                                    <i class="fa-solid fa-minus" aria-hidden="true"></i>
                                </button>
                                <button type="button" class="rts-cc-slider-btn horizontal" id="line-increase" aria-label="<?php esc_attr_e('Increase line spacing', 'rts-a11y'); ?>">
                                    <i class="fa-solid fa-plus" aria-hidden="true"></i>
                                </button>
                            </div>
                        </div>
                        
                        <!-- Dyslexia Font (2x1) -->
                        <button type="button" class="rts-cc-module rts-cc-wide" data-toggle="dyslexia" aria-pressed="false" aria-label="<?php esc_attr_e('Toggle dyslexia font', 'rts-a11y'); ?>">
                            <div class="icon-circle"><i class="fa-solid fa-book-open" aria-hidden="true"></i></div>
                            <span class="rts-cc-label"><?php esc_html_e('Dyslexia Font', 'rts-a11y'); ?></span>
                        </button>
                        
                        <!-- Invert (1x1) -->
                        <button type="button" class="rts-cc-module rts-cc-toggle" data-toggle="contrast" aria-pressed="false" aria-label="<?php esc_attr_e('Toggle inverted colors', 'rts-a11y'); ?>">
                            <div class="icon-circle"><i class="fa-solid fa-circle-half-stroke" aria-hidden="true"></i></div>
                            <span class="rts-cc-label"><?php esc_html_e('Invert', 'rts-a11y'); ?></span>
                        </button>
                        
                        <!-- Row 3: Focus Mode (2x1) + Ruler (1x1) + Calm (1x1) -->
                        
                        <!-- Focus Mode (2x1) -->
                        <button type="button" class="rts-cc-module rts-cc-wide" data-toggle="focus" aria-pressed="false" aria-label="<?php esc_attr_e('Toggle focus mode', 'rts-a11y'); ?>">
                            <div class="icon-circle"><i class="fa-solid fa-eye" aria-hidden="true"></i></div>
                            <span class="rts-cc-label"><?php esc_html_e('Focus Mode', 'rts-a11y'); ?></span>
                        </button>
                        
                        <!-- Ruler (1x1) -->
                        <button type="button" class="rts-cc-module rts-cc-toggle" data-toggle="ruler" id="ruler-btn" aria-pressed="false" aria-label="<?php esc_attr_e('Toggle reading ruler', 'rts-a11y'); ?>">
                            <div class="icon-circle"><i class="fa-solid fa-ruler-horizontal" aria-hidden="true"></i></div>
                            <span class="rts-cc-label"><?php esc_html_e('Ruler', 'rts-a11y'); ?></span>
                        </button>
                        
                        <!-- Row 5: Calm (1x1) + Links (1x1) + Stop Motion (2x1) -->
                        <button type="button" class="rts-cc-module rts-cc-toggle" data-toggle="calm" aria-pressed="false" aria-label="<?php esc_attr_e('Toggle calm mode', 'rts-a11y'); ?>">
                            <div class="icon-circle"><i class="fa-solid fa-cloud" aria-hidden="true"></i></div>
                            <span class="rts-cc-label"><?php esc_html_e('Calm', 'rts-a11y'); ?></span>
                        </button>
                        
                        <button type="button" class="rts-cc-module rts-cc-toggle" data-toggle="links" aria-pressed="false" aria-label="<?php esc_attr_e('Toggle link highlight', 'rts-a11y'); ?>">
                            <div class="icon-circle"><i class="fa-solid fa-link" aria-hidden="true"></i></div>
                            <span class="rts-cc-label"><?php esc_html_e('Links', 'rts-a11y'); ?></span>
                        </button>
                        
                        <button type="button" class="rts-cc-module rts-cc-wide" data-toggle="nomotion" aria-pressed="false" aria-label="<?php esc_attr_e('Toggle stop animations', 'rts-a11y'); ?>">
                            <div class="icon-circle"><i class="fa-solid fa-ban" aria-hidden="true"></i></div>
                            <span class="rts-cc-label"><?php esc_html_e('Stop Motion', 'rts-a11y'); ?></span>
                        </button>
                        
                        <!-- Row 6: Align (1x1) + Mono (1x1) + Cursor (1x1) + Zoom (1x1) -->
                        <button type="button" class="rts-cc-module rts-cc-toggle" data-toggle="textalign" aria-pressed="false" aria-label="<?php esc_attr_e('Toggle text alignment', 'rts-a11y'); ?>">
                            <div class="icon-circle"><i class="fa-solid fa-align-center" aria-hidden="true"></i></div>
                            <span class="rts-cc-label"><?php esc_html_e('Align', 'rts-a11y'); ?></span>
                        </button>
                        
                        <button type="button" class="rts-cc-module rts-cc-toggle" data-toggle="monochrome" aria-pressed="false" aria-label="<?php esc_attr_e('Toggle monochrome', 'rts-a11y'); ?>">
                            <div class="icon-circle"><i class="fa-solid fa-adjust" aria-hidden="true"></i></div>
                            <span class="rts-cc-label"><?php esc_html_e('Mono', 'rts-a11y'); ?></span>
                        </button>
                        
                        <button type="button" class="rts-cc-module rts-cc-toggle" data-toggle="bigcursor" aria-pressed="false" aria-label="<?php esc_attr_e('Toggle large cursor', 'rts-a11y'); ?>">
                            <div class="icon-circle"><i class="fa-solid fa-arrow-pointer" aria-hidden="true"></i></div>
                            <span class="rts-cc-label"><?php esc_html_e('Cursor', 'rts-a11y'); ?></span>
                        </button>
                        
                        <button type="button" class="rts-cc-module rts-cc-toggle" id="zoom-btn" data-zoom="100" aria-label="<?php esc_attr_e('Page zoom: 100%', 'rts-a11y'); ?>">
                            <div class="icon-circle"><i class="fa-solid fa-magnifying-glass-plus" aria-hidden="true"></i></div>
                            <span class="rts-cc-label" id="zoom-label">100%</span>
                        </button>
                        
                        <!-- Row 7: Dark (1x1) + Saturate (1x1) + Reset (1x1) -->
                        <button type="button" class="rts-cc-module rts-cc-toggle" data-toggle="darkmode" aria-pressed="false" aria-label="<?php esc_attr_e('Toggle dark mode', 'rts-a11y'); ?>">
                            <div class="icon-circle"><i class="fa-solid fa-moon" aria-hidden="true"></i></div>
                            <span class="rts-cc-label"><?php esc_html_e('Dark', 'rts-a11y'); ?></span>
                        </button>
                        
                        <button type="button" class="rts-cc-module rts-cc-toggle" data-toggle="saturate" aria-pressed="false" aria-label="<?php esc_attr_e('Toggle color saturation', 'rts-a11y'); ?>">
                            <div class="icon-circle"><i class="fa-solid fa-droplet" aria-hidden="true"></i></div>
                            <span class="rts-cc-label"><?php esc_html_e('Saturate', 'rts-a11y'); ?></span>
                        </button>
                        
                        <!-- Reset Button (inline 1x1) -->
                        <button type="button" class="rts-cc-module rts-cc-reset" id="reset-btn" aria-label="<?php esc_attr_e('Reset all accessibility settings', 'rts-a11y'); ?>">
                            <i class="fa-solid fa-rotate-left" aria-hidden="true"></i>
                            <span class="rts-cc-label"><?php esc_html_e('Reset', 'rts-a11y'); ?></span>
                        </button>
                    </div>
                </div>
                
                <!-- Footer Branding - Always at bottom -->
                <div class="rts-cc-footer">
                    <span class="rts-cc-credit"><?php esc_html_e('Foundation by Sonny × Inkfire', 'rts-a11y'); ?></span>
                </div>
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
    
    // Configuration - properly escaped boolean
    const SAVE_PREFS = <?php echo $save_prefs ? 'true' : 'false'; ?>;
    const STORAGE_KEY = 'rts_a11y_prefs_v2';
            // Safe storage wrapper (prevents crashes when storage is blocked)
            // FIXED: Use window.localStorage (never window.safeStorage)
        const safeStorage = {
            getItem: function(key) {
                try { return window.localStorage.getItem(key); } catch(e) { return null; }
            },
            setItem: function(key, val) {
                try { window.localStorage.setItem(key, val); } catch(e) { /* silent fail */ }
            },
            removeItem: function(key) {
                try { window.localStorage.removeItem(key); } catch(e) { /* silent fail */ }
            }
        };

    
    // State variables
    // State variables
    let fontSize = 0; // 0 to 5 (100% to 175% in 15% increments)
    let fontWeight = 0; // 0=Normal, 1=Medium, 2=SemiBold, 3=Bold, 4=Black
    let lineHeight = 0; // 0 to 5 (1.5 to 2.5 in 0.2 increments)
    let pageZoom = 100; // 100, 110, 125, 150
    
    // Value arrays for better management
    const fontSizes = [100, 115, 130, 145, 160, 175];
    const weightValues = [400, 500, 600, 700, 800];
    const weightNames = ["<?php esc_html_e('Normal', 'rts-a11y'); ?>", "<?php esc_html_e('Medium', 'rts-a11y'); ?>", "<?php esc_html_e('SemiBold', 'rts-a11y'); ?>", "<?php esc_html_e('Bold', 'rts-a11y'); ?>", "<?php esc_html_e('Black', 'rts-a11y'); ?>"];
    const lineHeights = [1.5, 1.7, 1.9, 2.1, 2.3, 2.5];
    const zoomLevels = [100, 110, 125, 150];
    
    // Friendly feature names for screen reader announcements
    const friendlyNames = {
        dyslexia: "<?php esc_html_e('Dyslexia font', 'rts-a11y'); ?>",
        contrast: "<?php esc_html_e('Inverted colors', 'rts-a11y'); ?>",
        focus: "<?php esc_html_e('Focus mode', 'rts-a11y'); ?>",
        calm: "<?php esc_html_e('Calm mode', 'rts-a11y'); ?>",
        ruler: "<?php esc_html_e('Reading ruler', 'rts-a11y'); ?>",
        links: "<?php esc_html_e('Link highlight', 'rts-a11y'); ?>",
        nomotion: "<?php esc_html_e('Stop animations', 'rts-a11y'); ?>",
        monochrome: "<?php esc_html_e('Monochrome', 'rts-a11y'); ?>",
        bigcursor: "<?php esc_html_e('Large cursor', 'rts-a11y'); ?>",
        textalign: "<?php esc_html_e('Center text', 'rts-a11y'); ?>",
        darkmode: "<?php esc_html_e('Dark mode', 'rts-a11y'); ?>",
        saturate: "<?php esc_html_e('Color saturation', 'rts-a11y'); ?>"
    };
    
    // DOM Elements
    const elements = {
        container: document.getElementById('rts-container'),
        page: null,
        pill: document.getElementById('rts-pill'),
        panel: document.getElementById('rts-panel'),
        expand: document.getElementById('expand-btn'),
        close: document.getElementById('close-btn'),
        ruler: document.getElementById('rts-ruler'),
        rulerClose: document.querySelector('#rts-ruler .rts-ruler-close'),
        reset: document.getElementById('reset-btn'),
        ttsBtn: document.getElementById('tts-btn'),
        quickTts: document.getElementById('quick-tts'),
        zoomBtn: document.getElementById('zoom-btn'),
        liveRegion: document.getElementById('rts-a11y-live')
    };

        // Teleport widget to <body> to avoid footer transforms breaking position:fixed
        if (elements.container && document.body && elements.container.parentNode !== document.body) {
            document.body.appendChild(elements.container);
        }


        // Wrap the rest of the page so visual modes never affect the widget.
        // This prevents "invert makes the widget drop to the footer" and keeps the pill floating.
        let siteWrap = document.getElementById('rts-a11y-sitewrap');
        if (!siteWrap && document.body) {
            siteWrap = document.createElement('div');
            siteWrap.id = 'rts-a11y-sitewrap';
            siteWrap.className = 'rts-a11y-sitewrap';

            // Insert wrapper at the top of <body>
            document.body.insertBefore(siteWrap, document.body.firstChild);

            // Move existing page nodes into wrapper (but keep scripts/styles and the widget out of it)
            const moved = [];
            Array.from(document.body.childNodes).forEach((node) => {
                if (node === siteWrap) return;
                if (node === elements.container) return;

                if (node.nodeType === 1) {
                    const tag = node.tagName ? node.tagName.toLowerCase() : '';
                    if (tag === 'script' || tag === 'style' || tag === 'link') return;
                }
                moved.push(node);
            });

            moved.forEach((node) => siteWrap.appendChild(node));
        }

        // Use the wrapper as our "page" target for all visual changes
        elements.page = siteWrap || document.documentElement;
    
    // Text-to-Speech
    const tts = {
        synth: window.speechSynthesis,
        utterance: null,
        voice: null,
        active: false,
        
        init() {
            if (!this.synth) return;
            
            // Load voices
            if (this.synth.getVoices().length > 0) {
                this.setVoice();
            }
            this.synth.addEventListener('voiceschanged', () => this.setVoice());
        },
        
        setVoice() {
            const voices = this.synth.getVoices();
            // Prefer natural-sounding English voices
            this.voice = voices.find(v => v.lang.startsWith('en') && v.name.includes('Natural')) ||
                        voices.find(v => v.lang.startsWith('en')) ||
                        voices[0];
        },
        
        speak() {
            if (!this.synth || this.active) return;
            
            // Find content to read
            const content = document.querySelector('.rts-letter-content, .entry-content, article');
            if (!content) {
                announce("<?php esc_html_e('No content found to read', 'rts-a11y'); ?>");
                return;
            }
            
            const text = content.innerText;
            if (!text.trim()) {
                announce("<?php esc_html_e('No text content found', 'rts-a11y'); ?>");
                return;
            }
            
            this.utterance = new SpeechSynthesisUtterance(text);
            this.utterance.voice = this.voice;
            this.utterance.rate = 0.9;
            this.utterance.pitch = 1.0;
            
            this.utterance.onstart = () => {
                this.active = true;
                updateTTSUI(true);
                announce("<?php esc_html_e('Text to speech started', 'rts-a11y'); ?>");
            };
            
            this.utterance.onend = () => {
                this.active = false;
                updateTTSUI(false);
                announce("<?php esc_html_e('Text to speech finished', 'rts-a11y'); ?>");
            };
            
            this.utterance.onerror = (e) => {
                this.active = false;
                updateTTSUI(false);
                // "interrupted" is expected when the user stops speech or triggers a new utterance.
                if (e && e.error === 'interrupted') return;
                console.error('TTS Error:', e);
            };
            
            this.synth.speak(this.utterance);
        },
        
        stop() {
            if (!this.synth) return;
            this.synth.cancel();
            this.active = false;
            updateTTSUI(false);
            announce("<?php esc_html_e('Text to speech stopped', 'rts-a11y'); ?>");
        }
    };
    
    // Update TTS UI state
    function updateTTSUI(active) {
        const buttons = [elements.ttsBtn, elements.quickTts].filter(Boolean);
        if (buttons.length === 0) return;
        
        buttons.forEach(btn => {
            if (!btn) return;
            btn.setAttribute('aria-pressed', active);
            const icon = btn.querySelector('i');
            if (icon) {
                icon.className = active ? 'fa-solid fa-stop' : 'fa-solid fa-circle-play';
                if (btn.classList.contains('rts-cc-tts')) {
                    icon.classList.add('rts-cc-icon');
                }
            }
        });
    }
    
    // Screen reader announcements
    function announce(message) {
        if (!elements.liveRegion) return;
        elements.liveRegion.textContent = '';
        setTimeout(() => {
            elements.liveRegion.textContent = message;
        }, 100);
    }
    
    // Visual feedback pulse
    function pulse() {
        if (elements.expand && !elements.panel.classList.contains('rts-open')) {
            elements.expand.style.transform = 'scale(1.2)';
            setTimeout(() => {
                elements.expand.style.transform = '';
            }, 200);
        }
    }
    
    // === PANEL MANAGEMENT ===
    function openPanel() {
        if (!elements.panel || !elements.expand) return;
        
        elements.panel.classList.add('rts-open');
        elements.panel.setAttribute('aria-modal', 'true');
        elements.expand.setAttribute('aria-expanded', 'true');
        document.body.classList.add('rts-panel-open');
        
        // Focus first interactive element
        const firstButton = elements.panel.querySelector('button:not([disabled])');
        if (firstButton) {
            setTimeout(() => firstButton.focus(), 100);
        }
    }
    
    function closePanel() {
        if (!elements.panel || !elements.expand) return;
        
        elements.panel.classList.remove('rts-open');
        elements.panel.setAttribute('aria-modal', 'false');
        elements.expand.setAttribute('aria-expanded', 'false');
        document.body.classList.remove('rts-panel-open');
        
        // Return focus to expand button
        elements.expand.focus();
    }
    
    // === PREFERENCES ===
    function savePrefs() {
        if (!SAVE_PREFS || !elements.container) return;
        
        const prefs = {
            font: fontSize,
            weight: fontWeight,
            lineHeight: lineHeight,
            zoom: pageZoom,
            toggles: {}
        };
        
        ['dyslexia', 'contrast', 'focus', 'ruler', 'calm', 'links', 'nomotion', 'monochrome', 'bigcursor', 'textalign', 'darkmode', 'saturate'].forEach(feature => {
            // Dyslexia applies to body, others to container
            const el = (feature === 'dyslexia') ? document.body : elements.container;
            prefs.toggles[feature] = el.classList.contains('rts-' + feature);
        });
        
        try {
            safeStorage.setItem(STORAGE_KEY, JSON.stringify(prefs));
        } catch (e) {
            console.error('RTS A11y: Error saving preferences', e);
        }
    }

        // Back-compat alias: older handlers call saveSettings()
        function saveSettings(){
            savePrefs();
        }

    
    function loadPrefs() {
        if (!SAVE_PREFS) return;
        
        try {
            const saved = safeStorage.getItem(STORAGE_KEY);
            if (!saved) return;
            
            const prefs = JSON.parse(saved);
            
            if (typeof prefs.font === 'number') {
                fontSize = Math.max(0, Math.min(prefs.font, fontSizes.length - 1));
                updateFont();
            }
            
            if (typeof prefs.weight === 'number') {
                fontWeight = Math.max(0, Math.min(prefs.weight, weightValues.length - 1));
                updateWeight();
            }
            
            if (typeof prefs.lineHeight === 'number') {
                lineHeight = Math.max(0, Math.min(prefs.lineHeight, lineHeights.length - 1));
                updateLineHeight();
            }
            
            if (typeof prefs.zoom === 'number') {
                pageZoom = prefs.zoom;
                updateZoom();
            }
            
            if (prefs.toggles) {
                Object.keys(prefs.toggles).forEach(feature => {
                    if (prefs.toggles[feature]) {
                        toggleFeature(feature, true);
                    }
                });
            }
            
            updateAllDisplays();
        } catch (e) {
            console.error('RTS A11y: Error loading preferences', e);
        }
    }
    
    // === FONT SIZE ===
    function changeFontSize(direction) {
        if ((direction > 0 && fontSize < fontSizes.length - 1) || (direction < 0 && fontSize > 0)) {
            fontSize += direction;
            updateFont();
            savePrefs();
            pulse();
            
            const percent = fontSizes[fontSize];
            const display = document.getElementById('font-size-value');
            if (display) display.textContent = `${percent}%`;
            announce(`<?php esc_html_e('Font size:', 'rts-a11y'); ?> ${percent}%`);
        }
    }
    
    function updateFont() {
        if (!elements.container) return;
        
        if (fontSize > 0) {
            const addPx = (fontSizes[fontSize] / 100 - 1) * 16; // Relative to 16px base
            elements.page.classList.add('rts-font-boost');
            elements.container.style.setProperty('--rts-font-add', addPx + 'px');
        } else {
            elements.page.classList.remove('rts-font-boost');
            elements.container.style.removeProperty('--rts-font-add');
        }
    }
    
    // === FONT WEIGHT ===
    function changeFontWeight(direction) {
        if ((direction > 0 && fontWeight < weightValues.length - 1) || (direction < 0 && fontWeight > 0)) {
            fontWeight += direction;
            updateWeight();
            savePrefs();
            pulse();
            
            const name = weightNames[fontWeight];
            const display = document.getElementById('font-weight-value');
            if (display) display.textContent = name;
            announce(`<?php esc_html_e('Font weight:', 'rts-a11y'); ?> ${name}`);
        }
    }
    
    function updateWeight() {
        if (!elements.container) return;
        
        if (fontWeight > 0) {
            elements.page.classList.add('rts-weight-boost');
            elements.container.style.setProperty('--rts-weight-value', weightValues[fontWeight]);
        } else {
            elements.page.classList.remove('rts-weight-boost');
            elements.container.style.removeProperty('--rts-weight-value');
        }
    }
    
    // === LINE HEIGHT ===
    function changeLineHeight(direction) {
        if ((direction > 0 && lineHeight < lineHeights.length - 1) || (direction < 0 && lineHeight > 0)) {
            lineHeight += direction;
            updateLineHeight();
            savePrefs();
            pulse();
            
            const value = lineHeights[lineHeight];
            const display = document.getElementById('line-height-value');
            if (display) display.textContent = value.toFixed(1);
            announce(`<?php esc_html_e('Line spacing:', 'rts-a11y'); ?> ${value.toFixed(1)}`);
        }
    }
    
    function updateLineHeight() {
        if (!elements.container) return;
        
        if (lineHeight > 0) {
            elements.page.classList.add('rts-lineheight-boost');
            elements.container.style.setProperty('--rts-lineheight-value', lineHeights[lineHeight]);
        } else {
            elements.page.classList.remove('rts-lineheight-boost');
            elements.container.style.removeProperty('--rts-lineheight-value');
        }
    }
    
    // Helper function to update all displays
    function updateAllDisplays() {
        const fontDisplay = document.getElementById('font-size-value');
        const weightDisplay = document.getElementById('font-weight-value');
        const lineDisplay = document.getElementById('line-height-value');
        
        if (fontDisplay) fontDisplay.textContent = `${fontSizes[fontSize]}%`;
        if (weightDisplay) weightDisplay.textContent = weightNames[fontWeight];
        if (lineDisplay) lineDisplay.textContent = lineHeights[lineHeight].toFixed(1);
    }
    
    // === PAGE ZOOM ===
    function cycleZoom() {
        const currentIndex = zoomLevels.indexOf(pageZoom);
        const nextIndex = (currentIndex + 1) % zoomLevels.length;
        pageZoom = zoomLevels[nextIndex];
        updateZoom();
        savePrefs();
        pulse();
        announce(`<?php esc_html_e('Page zoom:', 'rts-a11y'); ?> ${pageZoom}%`);
    }
    
    function updateZoom() {
        const label = document.getElementById('zoom-label');

        // We apply zoom to the SITE WRAPPER only (never to <html>/<body>), so fixed widgets stay fixed.
        const target = elements.page || document.getElementById('rts-a11y-sitewrap') || document.body;

        // Clear any old zoom styles/classes first
        if (target) {
            target.classList.remove('rts-zoom');
            target.style.zoom = '';
            target.style.transform = '';
            target.style.transformOrigin = '';
            target.style.width = '';
        }

        if (pageZoom === 100) {
            if (label) label.textContent = '100%';
            announce('Zoom reset to 100%.');
            saveSettings();
            return;
        }

        const scale = pageZoom / 100;

        if (target) {
            target.classList.add('rts-zoom');

            // Preferred: CSS zoom (Chromium/WebKit). Fallback: transform scale (Firefox).
            target.style.zoom = String(scale);
            target.style.transform = `scale(${scale})`;
            target.style.transformOrigin = 'top center';
            target.style.width = `calc(100% / ${scale})`;
        }

        if (label) label.textContent = pageZoom + '%';
        announce('Zoom set to ' + pageZoom + ' percent.');
        saveSettings();
    }
    
    // === TOGGLE FEATURES ===
    function toggleFeature(type, forceState = null) {
        if (!elements.page) return;
        
        const className = 'rts-' + type;
        const newState = forceState !== null ? forceState : !elements.page.classList.contains(className);

        // Apply ALL feature classes to the page (html) so adaptions affect the full site.
        if (newState) {
            elements.page.classList.add(className);
        } else {
            elements.page.classList.remove(className);
        }

        // Extra behaviour hooks
        if (type === 'ruler' && elements.ruler) {
            elements.ruler.setAttribute('aria-hidden', newState ? 'false' : 'true');

            // Prevent focus from living inside an aria-hidden region (console warning)
            if (elements.rulerClose) {
                elements.rulerClose.tabIndex = newState ? 0 : -1;
                if (!newState && document.activeElement === elements.rulerClose) {
                    elements.rulerClose.blur();
                }
            }
        }
        if (type === 'tts' && !newState) {
            // Stop speech when toggling off
            tts.stop();
        }
        
        // Update all buttons for this feature
        const buttons = document.querySelectorAll(`[data-toggle="${type}"]`);
        buttons.forEach(btn => btn.setAttribute('aria-pressed', newState));
        
        savePrefs();
        pulse();
        announce(`${friendlyNames[type]} ${newState ? "<?php esc_html_e('enabled', 'rts-a11y'); ?>" : "<?php esc_html_e('disabled', 'rts-a11y'); ?>"}`);
    }
    
    // === EVENT LISTENERS ===
    function setupListeners() {
        // Panel controls - add null checks
        if (elements.expand) {
            elements.expand.addEventListener('click', openPanel);
        }
        if (elements.close) {
            elements.close.addEventListener('click', closePanel);
        }
        
        // Escape key to close
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && elements.panel && elements.panel.classList.contains('rts-open')) {
                closePanel();
            }
        });
        
        // Click outside to close
        document.addEventListener('click', (e) => {
            if (elements.panel && elements.pill &&
                elements.panel.classList.contains('rts-open') &&
                !elements.panel.contains(e.target) &&
                !elements.pill.contains(e.target)) {
                closePanel();
            }
        });
        
        // Toggle feature buttons
        document.querySelectorAll('[data-toggle]').forEach(btn => {
            const feature = btn.getAttribute('data-toggle');
            btn.addEventListener('click', () => toggleFeature(feature));
            btn.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    toggleFeature(feature);
                }
            });
        });
        
        // Font size controls
        const fontInc = document.getElementById('font-increase');
        const fontDec = document.getElementById('font-decrease');
        
        if (fontInc) {
            fontInc.addEventListener('click', () => changeFontSize(1));
            fontInc.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    changeFontSize(1);
                }
            });
        }
        
        if (fontDec) {
            fontDec.addEventListener('click', () => changeFontSize(-1));
            fontDec.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    changeFontSize(-1);
                }
            });
        }
        
        // Font weight controls
        const weightInc = document.getElementById('weight-increase');
        const weightDec = document.getElementById('weight-decrease');
        
        if (weightInc) {
            weightInc.addEventListener('click', () => changeFontWeight(1));
            weightInc.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    changeFontWeight(1);
                }
            });
        }
        
        if (weightDec) {
            weightDec.addEventListener('click', () => changeFontWeight(-1));
            weightDec.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    changeFontWeight(-1);
                }
            });
        }
        
        // Line height controls
        const lineInc = document.getElementById('line-increase');
        const lineDec = document.getElementById('line-decrease');
        
        if (lineInc) {
            lineInc.addEventListener('click', () => changeLineHeight(1));
            lineInc.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    changeLineHeight(1);
                }
            });
        }
        
        if (lineDec) {
            lineDec.addEventListener('click', () => changeLineHeight(-1));
            lineDec.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    changeLineHeight(-1);
                }
            });
        }
        
        // Zoom button
        elements.zoomBtn?.addEventListener('click', cycleZoom);
        elements.zoomBtn?.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                cycleZoom();
            }
        });
        
        // TTS controls
        const triggerTTS = () => {
            if (tts.active) tts.stop();
            else tts.speak();
        };
        
        elements.ttsBtn?.addEventListener('click', triggerTTS);
        elements.quickTts?.addEventListener('click', triggerTTS);
        
        // Reset button
        elements.reset?.addEventListener('click', () => {
            ['dyslexia', 'contrast', 'focus', 'ruler', 'calm', 'links', 'nomotion', 'monochrome', 'bigcursor', 'textalign', 'darkmode', 'saturate'].forEach(feature => {
                toggleFeature(feature, false);
            });
            fontSize = 0;
            fontWeight = 0;
            lineHeight = 0;
            pageZoom = 100;
            updateFont();
            updateWeight();
            updateLineHeight();
            updateZoom();
            tts.stop();
            savePrefs();
            pulse();
            announce("<?php esc_html_e('All accessibility settings reset', 'rts-a11y'); ?>");
        });

        // Ruler close button
        elements.rulerClose?.addEventListener('click', () => {
            toggleFeature('ruler', false);
        });
        
        // Reading ruler movement
        let lastY = 0;
        document.addEventListener('mousemove', (e) => {
            if (Math.abs(e.clientY - lastY) > 2 && elements.page.classList.contains('rts-ruler')) {
                lastY = e.clientY;
                requestAnimationFrame(() => {
                    if (elements.ruler) {
                        elements.ruler.style.top = (e.clientY - 30) + 'px';
                    }
                });
            }
        });
        
        // Mobile swipe down to close
        let startY = 0;
        elements.panel.addEventListener('touchstart', (e) => {
            startY = e.touches[0].clientY;
        }, { passive: true });
        
        elements.panel.addEventListener('touchmove', (e) => {
            const currentY = e.touches[0].clientY;
            const diff = currentY - startY;
            
            if (diff > 100 && elements.panel.classList.contains('rts-open')) {
                closePanel();
            }
        }, { passive: true });
        
        // Focus trap in panel
        const focusableElements = 'button:not([disabled]), [href], input, select, textarea, [tabindex]:not([tabindex="-1"])';
        
        elements.panel.addEventListener('keydown', (e) => {
            if (!elements.panel.classList.contains('rts-open')) return;
            
            if (e.key === 'Tab') {
                const focusables = Array.from(elements.panel.querySelectorAll(focusableElements));
                const first = focusables[0];
                const last = focusables[focusables.length - 1];
                
                if (e.shiftKey && document.activeElement === first) {
                    e.preventDefault();
                    last.focus();
                } else if (!e.shiftKey && document.activeElement === last) {
                    e.preventDefault();
                    first.focus();
                }
            }
        });
    }
    
    // === INITIALIZATION ===
    function init() {
        // Check if container exists
        if (!elements.container) {
            console.error('RTS A11y: Container element not found');
            return;
        }
        
        tts.init();
        if (SAVE_PREFS) loadPrefs();
        setupListeners();
        updateFont();
        updateWeight();
        updateLineHeight();
        updateZoom();
        updateAllDisplays(); // Update value displays on load
    }
    
    // Start when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
    
    // Cleanup on page unload
    window.addEventListener('beforeunload', () => {
        if (tts.synth) {
            tts.synth.cancel();
        }
    });
    
})();
        </script>
        <?php
    }
}

// Initialize the toolkit immediately
RTS_Accessibility_Toolkit::get_instance();

} // end class_exists check

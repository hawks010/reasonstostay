<?php
/**
 * Reasons to Stay - Accessibility Widget (Theme Component)
 *
 * Fixes:
 * 1) Widget no longer falls into footer/bottom when site uses filter/transform.
 *    - We teleport the widget to <body> and wrap the rest of the page content
 *      into a dedicated "sitewrap" element. Visual filters apply to sitewrap,
 *      never to the widget.
 * 2) No OpenDyslexic CDN (prevents "Tracking Prevention blocked access to storage").
 *    - Uses a dyslexia-friendly system font stack.
 * 3) Storage is fully optional and safe.
 *    - If localStorage is blocked, we disable persistence without throwing.
 * 4) Working controls: dark mode, high contrast, zoom, font weight, line height,
 *    dyslexia font, reading ruler, reset.
 */

if (!defined('ABSPATH')) exit;

if (!class_exists('RTS_Accessibility_Toolkit')):

class RTS_Accessibility_Toolkit {
    private static $instance = null;
    private $option_name = 'rts_foundation_a11y_settings';

    public static function get_instance() {
        if (null === self::$instance) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        // Admin-only hooks
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);

        // Frontend only
        if (!is_admin()) {
            add_action('wp_footer', [$this, 'render_toolkit'], 999);
        }
    }

    public function add_admin_menu() {
        add_options_page(
            'Accessibility Toolkit',
            'Accessibility',
            'manage_options',
            'rts-a11y-settings',
            [$this, 'render_admin_page']
        );
    }

    public function register_settings() {
        register_setting(
            'rts_a11y_settings_group',
            $this->option_name,
            [
                'type' => 'array',
                'sanitize_callback' => [$this, 'sanitize_settings'],
                'default' => ['enabled' => true, 'save_preferences' => true],
            ]
        );
    }

    public function sanitize_settings($input) {
        // Nonce verification (admin form)
        if (!isset($_POST['rts_a11y_settings_nonce']) || !wp_verify_nonce($_POST['rts_a11y_settings_nonce'], 'rts_a11y_settings_action')) {
            add_settings_error('rts_a11y_settings_group', 'nonce_fail', 'Security verification failed.');
            return get_option($this->option_name);
        }

        $sanitized = [];
        $sanitized['enabled'] = isset($input['enabled']) ? (bool) $input['enabled'] : false;
        $sanitized['save_preferences'] = isset($input['save_preferences']) ? (bool) $input['save_preferences'] : false;
        return $sanitized;
    }

    public function render_admin_page() {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        $settings = get_option($this->option_name, ['enabled' => true, 'save_preferences' => true]);
        ?>
        <div class="wrap">
            <h1>Accessibility Toolkit Settings</h1>
            <form method="post" action="options.php">
                <?php
                wp_nonce_field('rts_a11y_settings_action', 'rts_a11y_settings_nonce');
                settings_fields('rts_a11y_settings_group');
                ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="rts_enabled">Enable Toolkit</label></th>
                        <td>
                            <input type="checkbox" id="rts_enabled" name="<?php echo esc_attr($this->option_name); ?>[enabled]" value="1" <?php checked(!empty($settings['enabled'])); ?> />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="rts_save_prefs">Save Preferences</label></th>
                        <td>
                            <input type="checkbox" id="rts_save_prefs" name="<?php echo esc_attr($this->option_name); ?>[save_preferences]" value="1" <?php checked(!empty($settings['save_preferences'])); ?> />
                            <p class="description">Saves user choices in their browser. If storage is blocked by the browser, preferences will not persist (but the widget will still work).</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    public function render_toolkit() {
        $settings = get_option($this->option_name, ['enabled' => true, 'save_preferences' => true]);
        if (isset($settings['enabled']) && !$settings['enabled']) return;

        $save_prefs = !empty($settings['save_preferences']);
        $is_single = is_singular('letter') || is_singular();

        $this->render_styles();
        $this->render_markup($is_single);
        $this->render_scripts($save_prefs);
    }

    private function render_styles() {
        ?>
        <style id="rts-a11y-styles">
            :root{
                --rts-dark:#070C13;
                --rts-orange:#FCA311;
                --rts-white:#FFFFFF;
                --rts-panel-bg: rgba(245,245,247,0.98);
                --rts-panel-text:#1C1C1E;
                --rts-shadow: 0 18px 55px rgba(0,0,0,0.28);
            }

            /* =====================
               WIDGET ISOLATION
               ===================== */
            #rts-a11y-root{
                position: fixed;
                right: 18px;
                top: 50%;
                transform: translateY(-50%);
                z-index: 2147483647;
                font-family: inherit;
                color: var(--rts-white);
            }

            /* Pill */
            .rts-a11y-pill{
                display:flex;
                flex-direction: column;
                gap: 12px;
                padding: 10px;
                background: var(--rts-dark);
                border-radius: 50px;
                border: 2px solid var(--rts-orange);
                box-shadow: 0 8px 30px rgba(0,0,0,0.30);
            }

            .rts-a11y-btn{
                width: 44px;
                height: 44px;
                border-radius: 50%;
                border: none;
                cursor: pointer;
                background: rgba(255,255,255,0.10);
                color: var(--rts-white);
                display:flex;
                align-items:center;
                justify-content:center;
                font-size: 18px;
                transition: transform 0.15s ease, background 0.15s ease, color 0.15s ease;
            }
            .rts-a11y-btn:hover,
            .rts-a11y-btn:focus{
                background: var(--rts-orange);
                color: var(--rts-dark);
                transform: scale(1.08);
                outline: none;
            }
            .rts-a11y-btn:focus-visible{
                outline: 3px solid rgba(252,163,17,0.55);
                outline-offset: 3px;
            }

            /* Panel */
            .rts-a11y-panel{
                position: fixed;
                top: 14px;
                bottom: 14px;
                right: -520px;
                width: min(420px, calc(100vw - 28px));
                background: var(--rts-panel-bg);
                color: var(--rts-panel-text);
                border-radius: 32px;
                box-shadow: var(--rts-shadow);
                padding: 18px;
                opacity: 0;
                visibility: hidden;
                transition: right 0.35s cubic-bezier(0.32,0.72,0,1), opacity 0.2s ease, visibility 0.2s ease;
                overflow: auto;
                z-index: 2147483647;
            }
            .rts-a11y-panel.rts-open{
                right: 14px;
                opacity: 1;
                visibility: visible;
            }

            .rts-a11y-header{
                display:flex;
                align-items:center;
                justify-content: space-between;
                gap: 12px;
                margin-bottom: 14px;
            }
            .rts-a11y-title{
                margin:0;
                font-size: 18px;
                font-weight: 900;
            }
            .rts-a11y-close{
                width: 36px;
                height: 36px;
                border-radius: 50%;
                border: none;
                cursor: pointer;
                background: rgba(0,0,0,0.06);
                color: #333;
                display:flex;
                align-items:center;
                justify-content:center;
            }
            .rts-a11y-close:hover,
            .rts-a11y-close:focus{
                background: rgba(0,0,0,0.12);
                outline: none;
            }

            .rts-a11y-grid{
                display:grid;
                grid-template-columns: repeat(2, 1fr);
                gap: 12px;
            }

            .rts-a11y-tile{
                border: 1px solid rgba(0,0,0,0.06);
                background: rgba(255,255,255,0.85);
                border-radius: 16px;
                padding: 14px 12px;
                cursor: pointer;
                display:flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                gap: 8px;
                min-height: 92px;
                transition: transform 0.15s ease, background 0.15s ease;
            }
            .rts-a11y-tile:hover,
            .rts-a11y-tile:focus{
                background: #fff;
                transform: translateY(-2px);
                outline: none;
            }
            .rts-a11y-tile[aria-pressed="true"]{
                background: var(--rts-orange);
                color: #fff;
                border-color: transparent;
            }

            .rts-a11y-icon{
                font-size: 24px;
                color: var(--rts-orange);
            }
            .rts-a11y-tile[aria-pressed="true"] .rts-a11y-icon{ color:#fff; }

            .rts-a11y-label{
                font-size: 12px;
                font-weight: 700;
                text-align: center;
                line-height: 1.2;
            }

            .rts-a11y-full{ grid-column: span 2; flex-direction: row; justify-content:flex-start; padding: 12px 14px; min-height: 0; }
            .rts-a11y-full .rts-a11y-icon{ margin-right: 12px; }

            .rts-a11y-slider{
                grid-column: span 2;
                border: 1px solid rgba(0,0,0,0.06);
                background: rgba(255,255,255,0.85);
                border-radius: 16px;
                padding: 12px 14px;
                display:flex;
                flex-direction: column;
                gap: 8px;
            }
            .rts-a11y-slider label{
                font-size: 12px;
                font-weight: 800;
            }
            .rts-a11y-slider input[type="range"]{ width: 100%; }

            /* Reading ruler (no focusable elements inside) */
            #rts-reading-ruler{
                position: fixed;
                left: 0;
                width: 100%;
                height: 60px;
                background: rgba(252,163,17,0.22);
                border-top: 2px solid var(--rts-orange);
                border-bottom: 2px solid var(--rts-orange);
                pointer-events: none;
                display: none;
                z-index: 2147483640;
            }

            /* Mobile placement */
            @media (max-width: 768px){
                #rts-a11y-root{ top:auto; bottom: 18px; right: 18px; transform: none; }
                .rts-a11y-pill{ flex-direction: row; border-radius: 30px; }
                .rts-a11y-panel{ top:auto; left:0; right:0; bottom:0; width:100%; border-radius: 24px 24px 0 0; max-height: 82vh; transform: translateY(110%); transition: transform 0.25s ease; }
                .rts-a11y-panel.rts-open{ transform: translateY(0); }
            }

            /* =====================
               SITE WRAP MODES
               (Applied to #rts-a11y-sitewrap only)
               ===================== */
            #rts-a11y-sitewrap{
                --rts-font-scale: 1;
                --rts-line-height: 1.65;
            }

            #rts-a11y-sitewrap.rts-zoom{
                font-size: calc(100% * var(--rts-font-scale)) !important;
            }

            #rts-a11y-sitewrap.rts-lineheight{
                line-height: var(--rts-line-height) !important;
            }

            #rts-a11y-sitewrap.rts-bold p,
            #rts-a11y-sitewrap.rts-bold li,
            #rts-a11y-sitewrap.rts-bold a,
            #rts-a11y-sitewrap.rts-bold span,
            #rts-a11y-sitewrap.rts-bold div{
                font-weight: 700 !important;
            }

            /* Dyslexia-friendly system stack (no CDN) */
            #rts-a11y-sitewrap.rts-dyslexia{
                font-family: 'Comic Sans MS', 'Chalkboard SE', 'Comic Neue', Verdana, Arial, sans-serif !important;
                letter-spacing: 0.04em !important;
                word-spacing: 0.10em !important;
            }

            /* Dark Mode and High Contrast using filter on sitewrap only */
            #rts-a11y-sitewrap.rts-darkmode{
                filter: invert(1) hue-rotate(180deg);
            }
            #rts-a11y-sitewrap.rts-contrast{
                filter: invert(1) hue-rotate(180deg) contrast(1.25) saturate(1.1);
            }

            /* Re-invert media so images/videos remain correct */
            #rts-a11y-sitewrap.rts-darkmode img,
            #rts-a11y-sitewrap.rts-darkmode video,
            #rts-a11y-sitewrap.rts-darkmode iframe,
            #rts-a11y-sitewrap.rts-contrast img,
            #rts-a11y-sitewrap.rts-contrast video,
            #rts-a11y-sitewrap.rts-contrast iframe{
                filter: invert(1) hue-rotate(180deg) !important;
            }

        </style>
        <?php
    }

    private function render_markup($is_single) {
        ?>
        <div id="rts-reading-ruler" aria-hidden="true"></div>

        <div id="rts-a11y-root" aria-label="Accessibility tools">
            <div class="rts-a11y-pill" role="group" aria-label="Quick accessibility controls">
                <?php if ($is_single): ?>
                    <button type="button" class="rts-a11y-btn" data-action="tts" aria-label="Read aloud">
                        <i class="fa-solid fa-circle-play" aria-hidden="true"></i>
                    </button>
                <?php endif; ?>

                <button type="button" class="rts-a11y-btn" data-action="open" aria-label="Open accessibility menu" aria-expanded="false">
                    <i class="fa-solid fa-universal-access" aria-hidden="true"></i>
                </button>
            </div>

            <div class="rts-a11y-panel" id="rts-a11y-panel" role="dialog" aria-modal="true" aria-hidden="true" aria-label="Accessibility menu">
                <div class="rts-a11y-header">
                    <h2 class="rts-a11y-title">Accessibility</h2>
                    <button type="button" class="rts-a11y-close" data-action="close" aria-label="Close accessibility menu">
                        <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                    </button>
                </div>

                <div class="rts-a11y-grid">
                    <button type="button" class="rts-a11y-tile rts-a11y-full" data-toggle="darkmode" aria-pressed="false">
                        <i class="fa-solid fa-moon rts-a11y-icon" aria-hidden="true"></i>
                        <span class="rts-a11y-label">Dark mode</span>
                    </button>

                    <button type="button" class="rts-a11y-tile rts-a11y-full" data-toggle="contrast" aria-pressed="false">
                        <i class="fa-solid fa-circle-half-stroke rts-a11y-icon" aria-hidden="true"></i>
                        <span class="rts-a11y-label">High contrast</span>
                    </button>

                    <div class="rts-a11y-slider" role="group" aria-label="Text size">
                        <label for="rts-zoom">Text size</label>
                        <input id="rts-zoom" type="range" min="1" max="1.5" step="0.05" value="1" />
                    </div>

                    <button type="button" class="rts-a11y-tile" data-toggle="bold" aria-pressed="false">
                        <i class="fa-solid fa-bold rts-a11y-icon" aria-hidden="true"></i>
                        <span class="rts-a11y-label">Bolder text</span>
                    </button>

                    <button type="button" class="rts-a11y-tile" data-toggle="lineheight" aria-pressed="false">
                        <i class="fa-solid fa-text-height rts-a11y-icon" aria-hidden="true"></i>
                        <span class="rts-a11y-label">More spacing</span>
                    </button>

                    <button type="button" class="rts-a11y-tile" data-toggle="dyslexia" aria-pressed="false">
                        <i class="fa-solid fa-book-open rts-a11y-icon" aria-hidden="true"></i>
                        <span class="rts-a11y-label">Dyslexia font</span>
                    </button>

                    <button type="button" class="rts-a11y-tile" data-toggle="ruler" aria-pressed="false">
                        <i class="fa-solid fa-ruler-horizontal rts-a11y-icon" aria-hidden="true"></i>
                        <span class="rts-a11y-label">Reading ruler</span>
                    </button>

                    <?php if ($is_single): ?>
                        <button type="button" class="rts-a11y-tile rts-a11y-full" data-action="tts" aria-pressed="false">
                            <i class="fa-solid fa-circle-play rts-a11y-icon" aria-hidden="true"></i>
                            <span class="rts-a11y-label">Read aloud</span>
                        </button>
                    <?php endif; ?>

                    <button type="button" class="rts-a11y-tile rts-a11y-full" data-action="reset" aria-label="Reset accessibility settings" style="background:#ffebee;color:#c62828;border-color:rgba(198,40,40,0.2)">
                        <i class="fa-solid fa-rotate-left rts-a11y-icon" aria-hidden="true" style="color:#c62828"></i>
                        <span class="rts-a11y-label">Reset</span>
                    </button>
                </div>
            </div>
        </div>
        <?php
    }

    private function render_scripts($save_prefs) {
        ?>
        <script>
        (function(){
            const SAVE_PREFS = <?php echo $save_prefs ? 'true' : 'false'; ?>;
            const STORAGE_KEY = 'rts_a11y_v4';

            const root = document.getElementById('rts-a11y-root');
            const panel = document.getElementById('rts-a11y-panel');
            const ruler = document.getElementById('rts-reading-ruler');
            const zoom = document.getElementById('rts-zoom');

            if(!root || !panel) return;

            // -------- Safe storage (no console fatals, avoids repeated probes)
            const storage = (function(){
                let ok = false;
                try {
                    const k = '__rts_test__';
                    window.localStorage.setItem(k,'1');
                    window.localStorage.removeItem(k);
                    ok = true;
                } catch(e) { ok = false; }

                return {
                    ok,
                    get(key){ if(!ok) return null; try { return window.localStorage.getItem(key); } catch(e){ return null; } },
                    set(key,val){ if(!ok) return; try { window.localStorage.setItem(key,val); } catch(e){} },
                    remove(key){ if(!ok) return; try { window.localStorage.removeItem(key); } catch(e){} }
                };
            })();

            // -------- Create sitewrap so filters never touch the widget
            function ensureSiteWrap(){
                let wrap = document.getElementById('rts-a11y-sitewrap');
                if(wrap) return wrap;

                wrap = document.createElement('div');
                wrap.id = 'rts-a11y-sitewrap';

                const bodyChildren = Array.from(document.body.children);
                bodyChildren.forEach(el => {
                    if(el === root) return;
                    if(el.id === 'wpadminbar') return; // keep admin bar outside filters
                    // Leave any existing sitewrap alone
                    if(el.id === 'rts-a11y-sitewrap') return;
                    wrap.appendChild(el);
                });

                document.body.insertBefore(wrap, root);
                return wrap;
            }

            // Teleport widget to body last, for maximum isolation
            if(document.body && root.parentNode !== document.body){
                document.body.appendChild(root);
            }
            // And ensure our ruler also lives at body-level
            if(document.body && ruler && ruler.parentNode !== document.body){
                document.body.appendChild(ruler);
            }

            const sitewrap = ensureSiteWrap();

            // -------- State
            const state = {
                darkmode:false,
                contrast:false,
                bold:false,
                lineheight:false,
                dyslexia:false,
                ruler:false,
                zoom:1
            };

            function applyState(){
                // Classes
                sitewrap.classList.toggle('rts-darkmode', !!state.darkmode);
                sitewrap.classList.toggle('rts-contrast', !!state.contrast);
                sitewrap.classList.toggle('rts-bold', !!state.bold);
                sitewrap.classList.toggle('rts-lineheight', !!state.lineheight);
                sitewrap.classList.toggle('rts-dyslexia', !!state.dyslexia);
                sitewrap.classList.toggle('rts-zoom', state.zoom && state.zoom !== 1);
                sitewrap.style.setProperty('--rts-font-scale', String(state.zoom || 1));
                sitewrap.style.setProperty('--rts-line-height', state.lineheight ? '1.85' : '1.65');

                // Ruler
                if(ruler){
                    ruler.style.display = state.ruler ? 'block' : 'none';
                }

                // Update pressed states
                document.querySelectorAll('#rts-a11y-panel [data-toggle]').forEach(btn => {
                    const key = btn.getAttribute('data-toggle');
                    btn.setAttribute('aria-pressed', state[key] ? 'true' : 'false');
                });

                // Slider
                if(zoom) zoom.value = String(state.zoom || 1);
            }

            function saveState(){
                if(!SAVE_PREFS) return;
                if(!storage.ok) return;
                storage.set(STORAGE_KEY, JSON.stringify(state));
            }

            function loadState(){
                if(!SAVE_PREFS) return;
                if(!storage.ok) return;
                const raw = storage.get(STORAGE_KEY);
                if(!raw) return;
                try {
                    const parsed = JSON.parse(raw);
                    if(parsed && typeof parsed === 'object'){
                        Object.keys(state).forEach(k => {
                            if(Object.prototype.hasOwnProperty.call(parsed,k)) state[k] = parsed[k];
                        });
                    }
                } catch(e) {}
            }

            // -------- Panel open/close with focus safety
            const openBtn = root.querySelector('[data-action="open"]');
            const closeBtn = panel.querySelector('[data-action="close"]');
            let lastFocus = null;

            function openPanel(){
                lastFocus = document.activeElement;
                panel.classList.add('rts-open');
                panel.setAttribute('aria-hidden','false');
                if(openBtn) openBtn.setAttribute('aria-expanded','true');
                // Focus the first control
                const first = panel.querySelector('button, [tabindex="0"], input');
                if(first) first.focus();
            }
            function closePanel(){
                panel.classList.remove('rts-open');
                panel.setAttribute('aria-hidden','true');
                if(openBtn) openBtn.setAttribute('aria-expanded','false');
                if(lastFocus && typeof lastFocus.focus === 'function') lastFocus.focus();
            }

            // Close on ESC
            document.addEventListener('keydown', (e) => {
                if(e.key === 'Escape' && panel.classList.contains('rts-open')){
                    e.preventDefault();
                    closePanel();
                }
            });

            // Click actions
            root.addEventListener('click', (e) => {
                const btn = e.target.closest('[data-action]');
                if(!btn) return;
                const action = btn.getAttribute('data-action');
                if(action === 'open') openPanel();
                if(action === 'close') closePanel();
                if(action === 'reset') {
                    state.darkmode = false;
                    state.contrast = false;
                    state.bold = false;
                    state.lineheight = false;
                    state.dyslexia = false;
                    state.ruler = false;
                    state.zoom = 1;
                    applyState();
                    saveState();
                }
                if(action === 'tts') {
                    ttsToggle();
                }
            });

            panel.addEventListener('click', (e) => {
                const toggleBtn = e.target.closest('[data-toggle]');
                if(!toggleBtn) return;
                const key = toggleBtn.getAttribute('data-toggle');
                if(!key || !(key in state)) return;

                // Dark mode and contrast should be mutually exclusive for predictable visuals
                if(key === 'darkmode' && !state.darkmode) state.contrast = false;
                if(key === 'contrast' && !state.contrast) state.darkmode = false;

                state[key] = !state[key];
                applyState();
                saveState();
            });

            if(openBtn) openBtn.addEventListener('click', (e) => { e.preventDefault(); openPanel(); });
            if(closeBtn) closeBtn.addEventListener('click', (e) => { e.preventDefault(); closePanel(); });

            // Zoom slider
            if(zoom){
                zoom.addEventListener('input', () => {
                    state.zoom = parseFloat(zoom.value || '1') || 1;
                    applyState();
                });
                zoom.addEventListener('change', () => {
                    state.zoom = parseFloat(zoom.value || '1') || 1;
                    saveState();
                });
            }

            // Reading ruler follow
            document.addEventListener('mousemove', (e) => {
                if(!ruler) return;
                if(ruler.style.display !== 'block') return;
                ruler.style.top = (e.clientY - 30) + 'px';
            }, {passive:true});

            // -------- TTS
            let speaking = false;
            function getReadableText(){
                const letter = document.querySelector('.rts-letter-content') || document.querySelector('article') || document.querySelector('main') || document.body;
                return (letter && letter.innerText) ? letter.innerText : '';
            }
            function ttsToggle(){
                if(!('speechSynthesis' in window)) return;
                try {
                    if(speaking){
                        window.speechSynthesis.cancel();
                        speaking = false;
                        return;
                    }
                    const text = getReadableText();
                    if(!text) return;
                    const utter = new SpeechSynthesisUtterance(text);
                    speaking = true;
                    utter.onend = () => { speaking = false; };
                    utter.onerror = () => { speaking = false; };
                    window.speechSynthesis.speak(utter);
                } catch(err){
                    speaking = false;
                }
            }

            // -------- Init
            loadState();
            applyState();
        })();
        </script>
        <?php
    }
}

RTS_Accessibility_Toolkit::get_instance();

endif;

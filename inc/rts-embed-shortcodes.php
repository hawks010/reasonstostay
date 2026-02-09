<?php
/**
 * RTS Embed Shortcodes (Frontend)
 *
 * Provides: [rts_embed_badge]
 *
 * Outputs a "Get the Reasons to Stay Letter Widget" button that opens a modal containing the
 * copy/paste embed snippet. Ensures the embed widget script is available.
 */

if (!defined('ABSPATH')) { exit; }

/**
 * Register assets for the embed badge (Styles & Scripts)
 * This ensures they are handled via WP's dependency system and are CSP compatible.
 * Hooked to wp_enqueue_scripts via functions.php loading this file.
 */
add_action('wp_enqueue_scripts', 'rts_register_embed_badge_assets');
function rts_register_embed_badge_assets() {
    
    // 1. CSS - Registered as an inline style attached to a dummy handle
    wp_register_style('rts-embed-badge', false);
    $css = '
        .rts-embed-badge-wrap .rts-embed-modal[hidden] { display:none !important; }
        .rts-embed-badge-wrap .rts-embed-modal {
            position: fixed; inset: 0; z-index: 999999;
            background: rgba(0,0,0,.55);
            display: flex; align-items: center; justify-content: center;
            padding: 18px;
        }
        .rts-embed-badge-wrap .rts-embed-modal__panel {
            width: min(720px, 100%);
            background: #fff;
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 12px 40px rgba(0,0,0,.25);
            position: relative;
        }
        /* Fixed Close Button Styling */
        .rts-embed-badge-wrap .rts-embed-modal__close {
            position: absolute; top: 12px; right: 12px;
            width: 36px; height: 36px;
            border-radius: 8px;
            border: 1px solid #e5e7eb; /* Light grey border */
            background: #fff;
            cursor: pointer;
            font-size: 24px;
            line-height: 1;
            color: #1e293b; /* Dark Slate Color - Fixes invisibility */
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
            padding: 0;
        }
        .rts-embed-badge-wrap .rts-embed-modal__close:hover {
            background: #f1f5f9;
            border-color: #cbd5e1;
            color: #000;
        }
        .rts-embed-badge-wrap .rts-embed-modal__title { margin: 0 0 8px 0; font-weight: 700; font-size: 1.25rem; color: #111827; }
        .rts-embed-badge-wrap .rts-embed-modal__text { margin: 0 0 12px 0; color: #4b5563; font-size: 0.95rem; }
        .rts-embed-badge-wrap .rts-embed-modal__code {
            width: 100%;
            min-height: 120px;
            padding: 12px;
            border-radius: 12px;
            border: 1px solid #d1d5db;
            background: #f9fafb;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
            font-size: 13px;
            resize: vertical;
            color: #374151;
        }
        .rts-embed-badge-wrap .rts-embed-modal__code:focus {
            outline: 2px solid #FCA311;
            border-color: #FCA311;
        }
        .rts-embed-badge-wrap .rts-embed-modal__actions {
            display: flex;
            gap: 10px;
            margin-top: 16px;
            flex-wrap: wrap;
            justify-content: flex-end;
        }
        .rts-embed-badge-wrap .rts-embed-modal__hint {
            margin: 16px 0 0 0;
            color: #6b7280;
            font-size: 0.85rem;
            border-top: 1px solid #e5e7eb;
            padding-top: 12px;
        }
        /* Button overrides for modal context */
        .rts-embed-badge-wrap .rts-btn {
            background-color: #000000;
            color: #F1E3D3;
            border: 1px solid rgba(0,0,0,0.1);
            padding: 10px 20px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s, background-color 0.2s, color 0.2s, border-color 0.2s;
        }
        .rts-embed-badge-wrap .rts-btn:hover {
            background-color: #F1E3D3;
            color: #000;
        }
        /* Loading state for copy button */
        .rts-embed-copy.rts-copying {
            background-color: #10b981 !important;
            color: white !important;
            border-color: #10b981 !important;
        }
        /* Screen Reader Only Class */
        .rts-sr-only {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0,0,0,0);
            white-space: nowrap;
            border: 0;
        }
        /* Mobile Touch Improvements */
        @media (hover: none) and (pointer: coarse) {
            .rts-embed-badge-wrap .rts-embed-modal__close {
                width: 44px;
                height: 44px;
            }
            .rts-embed-badge-wrap .rts-btn {
                min-height: 44px;
                padding: 12px 24px;
            }
        }
        /* Prefers Reduced Motion */
        @media (prefers-reduced-motion: reduce) {
            .rts-embed-badge-wrap .rts-embed-modal__close,
            .rts-embed-badge-wrap .rts-btn {
                transition: none !important;
            }
        }
    ';
    wp_add_inline_style('rts-embed-badge', $css);

    // 2. JS - Registered inline
    // Use defined theme version or fallback
    $ver = defined('RTS_THEME_VERSION') ? RTS_THEME_VERSION : '1.0';
    wp_register_script('rts-embed-badge', false, [], $ver, true);
    
    $js = '
    (function(){
        document.addEventListener("DOMContentLoaded", function(){
            // Support multiple badges on a page
            var wraps = document.querySelectorAll(".rts-embed-badge-wrap");
            var activeCloseFunc = null; // Track active modal to ensure single instance

            wraps.forEach(function(wrap){
                var openBtn  = wrap.querySelector(".rts-embed-badge-btn");
                var modal    = wrap.querySelector(".rts-embed-modal");
                var closeBtn = wrap.querySelector(".rts-embed-modal__close");
                var copyBtn  = wrap.querySelector(".rts-embed-copy");
                var textarea = wrap.querySelector(".rts-embed-modal__code");
                
                if(!openBtn || !modal || !closeBtn || !copyBtn || !textarea) return;

                var lastFocus = null;

                // Focus Trap for Accessibility
                function trapFocus(e) {
                    if (e.key !== "Tab") return;
                    var focusable = modal.querySelectorAll("button, [href], textarea, select, input");
                    if (focusable.length === 0) return;
                    
                    var first = focusable[0];
                    var last = focusable[focusable.length - 1];

                    if (e.shiftKey) {
                        if (document.activeElement === first) {
                            e.preventDefault();
                            last.focus();
                        }
                    } else {
                        if (document.activeElement === last) {
                            e.preventDefault();
                            first.focus();
                        }
                    }
                }

                function openModal(){
                    // Close any other open modal first
                    if (activeCloseFunc && typeof activeCloseFunc === "function") {
                        activeCloseFunc();
                    }

                    lastFocus = document.activeElement;
                    modal.hidden = false;
                    
                    // Prevent background scrolling
                    document.documentElement.style.overflow = "hidden";
                    
                    closeBtn.focus();
                    document.addEventListener("keydown", onKeydown);
                    
                    // Set this instance as active
                    activeCloseFunc = closeModal;
                }

                function closeModal(){
                    modal.hidden = true;
                    
                    // Restore background scrolling
                    document.documentElement.style.overflow = "";
                    
                    document.removeEventListener("keydown", onKeydown);
                    if(lastFocus) lastFocus.focus();
                    
                    // Clear active state if this was the active one
                    if (activeCloseFunc === closeModal) {
                        activeCloseFunc = null;
                    }
                }

                function onKeydown(e){
                    if(e.key === "Escape") closeModal();
                    if(!modal.hidden) trapFocus(e);
                }

                openBtn.addEventListener("click", openModal);
                
                // Keyboard support for opening modal
                openBtn.addEventListener("keydown", function(e) {
                    if (e.key === "Enter" || e.key === " ") {
                        e.preventDefault();
                        openModal();
                    }
                });

                closeBtn.addEventListener("click", closeModal);
                modal.addEventListener("click", function(e){
                    if(e.target === modal) closeModal();
                });

                copyBtn.addEventListener("click", function(){
                    // Modern clipboard API with safer fallback
                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        navigator.clipboard.writeText(textarea.value).then(onCopySuccess).catch(onCopyFallback);
                    } else {
                        onCopyFallback();
                    }
                });

                function onCopyFallback() {
                    textarea.focus();
                    textarea.select();
                    try {
                        var successful = document.execCommand("copy");
                        if(successful) onCopySuccess();
                    } catch (err) {
                        // Fail silently or handle error if needed
                    }
                }

                function onCopySuccess() {
                    var originalText = copyBtn.textContent;
                    copyBtn.textContent = "Copied!";
                    copyBtn.classList.add("rts-copying");
                    
                    // Announce to screen readers
                    var announcement = document.createElement("div");
                    announcement.className = "rts-sr-only";
                    announcement.setAttribute("aria-live", "polite");
                    announcement.textContent = "Code snippet copied to clipboard";
                    document.body.appendChild(announcement);
                    
                    setTimeout(function(){ 
                        copyBtn.textContent = originalText; 
                        copyBtn.classList.remove("rts-copying");
                        if(announcement && announcement.parentNode) {
                            announcement.parentNode.removeChild(announcement);
                        }
                    }, 1500);
                }
            });
        });
    })();
    ';
    wp_add_inline_script('rts-embed-badge', $js);
}

function rts_embed_badge_shortcode($atts = []) {
    $site = home_url();
    $api  = esc_url($site . '/wp-json/rts/v1/embed/random');
    
    // UPDATED: Use the virtual stable URL to hide theme paths/versions
    // This creates the clean: https://your-site.com/rts-widget.js link
    $js   = esc_url($site . '/rts-widget.js');

    // Enqueue the badge assets we registered above
    wp_enqueue_style('rts-embed-badge');
    wp_enqueue_script('rts-embed-badge');

    // Ensure main widget script is registered (bootloader registers it).
    if (function_exists('wp_enqueue_script')) {
        // Enqueue the widget script so the "Get Widget" button page renders the widget if needed
        wp_enqueue_script('rts-embed-widget');
    }

    // Build the snippet using the clean URL
    $snippet = '<div id="rts-widget" data-api="' . $api . '"></div>' . "\n"
             . '<script src="' . $js . '" async></script>';

    ob_start(); ?>
    <div class="rts-embed-badge-wrap" data-rts-badge="1" data-rts-version="<?php echo defined('RTS_THEME_VERSION') ? esc_attr(RTS_THEME_VERSION) : '1.0'; ?>">
        <button type="button" class="rts-btn rts-embed-badge-btn" aria-haspopup="dialog" aria-controls="rts-embed-badge-modal">
            Get the Reasons to Stay Letter Widget
        </button>

        <div id="rts-embed-badge-modal" class="rts-embed-modal" role="dialog" aria-modal="true" aria-labelledby="rts-embed-title" aria-describedby="rts-embed-desc" hidden>
            <div class="rts-embed-modal__panel" role="document">
                <button type="button" class="rts-embed-modal__close" aria-label="Close dialog" data-action="close-modal">×</button>

                <h3 id="rts-embed-title" class="rts-embed-modal__title">Embed the Reasons to Stay Letter Widget</h3>
                <p id="rts-embed-desc" class="rts-embed-modal__text">Copy and paste this HTML into your website where you want the widget to appear.</p>

                <textarea class="rts-embed-modal__code" readonly><?php echo esc_textarea($snippet); ?></textarea>

                <div class="rts-embed-modal__actions">
                    <button type="button" class="rts-btn rts-embed-copy">Copy snippet</button>
                    <a class="rts-btn" href="<?php echo esc_url($site); ?>" target="_blank" rel="noopener">Visit Reasons to Stay</a>
                </div>

                <p class="rts-embed-modal__hint"><strong>Note:</strong> The widget rotates letters continuously and includes “Read Another Letter”.</p>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('rts_embed_badge', 'rts_embed_badge_shortcode');

/**
 * [rts_embed_widget]
 * Renders the actual widget on your own site pages (for previewing or usage).
 */
add_shortcode( 'rts_embed_widget', 'rts_shortcode_render_widget' );
function rts_shortcode_render_widget( $atts ) {
    // 1. Enqueue the script (registered in bootloader with the stable URL)
    wp_enqueue_script( 'rts-embed-widget' );

    // 2. Define the API endpoint
    $api_url = home_url( '/wp-json/rts/v1/embed/random' );

    // 3. Render container
    return '<div id="rts-widget" data-api="' . esc_url( $api_url ) . '"></div>';
}

/**
 * [rts_embed_code]
 * Displays the HTML code snippet for partners to copy/paste.
 * Uses the new stable /rts-widget.js URL to hide theme paths.
 */
add_shortcode( 'rts_embed_code', 'rts_shortcode_render_snippet' );
function rts_shortcode_render_snippet( $atts ) {
    $api_url = home_url( '/wp-json/rts/v1/embed/random' );
    $js_url  = home_url( '/rts-widget.js' ); // Virtual stable URL

    $code  = '<div id="rts-widget" data-api="' . $api_url . '"></div>' . "\n";
    $code .= '<script src="' . $js_url . '" async></script>';

    return '<textarea readonly class="rts-code-snippet" style="width:100%;height:100px;font-family:monospace;padding:15px;background:#0f172a;color:#f8fafc;border:1px solid #334155;border-radius:8px;font-size:13px;line-height:1.5;">' . esc_textarea( $code ) . '</textarea>';
}
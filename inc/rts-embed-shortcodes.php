<?php
/**
 * RTS Embed Shortcodes (Frontend)
 *
 * Provides: [rts_embed_badge]
 *
 * Outputs a "Get the Hope Widget" button that opens a modal containing the
 * copy/paste embed snippet. Ensures the embed widget script is available.
 */

if (!defined('ABSPATH')) { exit; }

function rts_embed_badge_shortcode($atts = []) {
    $site = home_url();
    $api  = esc_url($site . '/wp-json/rts/v1/embed/random');
    $js   = esc_url(get_stylesheet_directory_uri() . '/embeds/assets/rts-widget.js');

    // Ensure script is registered (bootloader registers it).
    if (function_exists('wp_enqueue_script')) {
        // Only enqueue if it was registered; otherwise fall back to inline <script> in snippet.
        wp_enqueue_script('rts-embed-widget');
    }

    $snippet = '<div id="rts-widget" data-api="' . $api . '"></div>' . "\n"
             . '<script src="' . $js . '" async></script>';

    ob_start(); ?>
    <div class="rts-embed-badge-wrap">
        <button type="button" class="rts-btn rts-embed-badge-btn" aria-haspopup="dialog" aria-controls="rts-embed-badge-modal">
            Get the Hope Widget
        </button>

        <div id="rts-embed-badge-modal" class="rts-embed-modal" role="dialog" aria-modal="true" aria-label="Get the Reasons to Stay widget" hidden>
            <div class="rts-embed-modal__panel" role="document">
                <button type="button" class="rts-embed-modal__close" aria-label="Close">×</button>

                <h3 class="rts-embed-modal__title">Embed the Hope Widget</h3>
                <p class="rts-embed-modal__text">Copy and paste this HTML into your website where you want the widget to appear.</p>

                <textarea class="rts-embed-modal__code" readonly><?php echo esc_textarea($snippet); ?></textarea>

                <div class="rts-embed-modal__actions">
                    <button type="button" class="rts-btn rts-embed-copy">Copy snippet</button>
                    <a class="rts-btn" href="<?php echo esc_url($site); ?>" target="_blank" rel="noopener">Visit Reasons to Stay</a>
                </div>

                <p class="rts-embed-modal__hint"><strong>Note:</strong> The widget rotates letters continuously and includes “Read Another Letter”.</p>
            </div>
        </div>
    </div>

    <style>
        .rts-embed-badge-wrap .rts-embed-modal[hidden]{ display:none !important; }
        .rts-embed-badge-wrap .rts-embed-modal{
            position:fixed; inset:0; z-index:999999;
            background:rgba(0,0,0,.55);
            display:flex; align-items:center; justify-content:center;
            padding:18px;
        }
        .rts-embed-badge-wrap .rts-embed-modal__panel{
            width:min(720px, 100%);
            background:#fff;
            border-radius:16px;
            padding:20px;
            box-shadow:0 12px 40px rgba(0,0,0,.25);
            position:relative;
        }
        .rts-embed-badge-wrap .rts-embed-modal__close{
            position:absolute; top:10px; right:12px;
            width:40px; height:40px;
            border-radius:10px;
            border:1px solid rgba(17,24,39,.15);
            background:#fff;
            cursor:pointer;
            font-size:22px;
            line-height:1;
        }
        .rts-embed-badge-wrap .rts-embed-modal__title{ margin:0 0 8px 0; }
        .rts-embed-badge-wrap .rts-embed-modal__text{ margin:0 0 12px 0; }
        .rts-embed-badge-wrap .rts-embed-modal__code{
            width:100%;
            min-height:120px;
            padding:12px;
            border-radius:12px;
            border:1px solid rgba(17,24,39,.15);
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
            font-size:13px;
            resize:vertical;
        }
        .rts-embed-badge-wrap .rts-embed-modal__actions{
            display:flex;
            gap:10px;
            margin-top:12px;
            flex-wrap:wrap;
            justify-content:flex-end;
        }
        .rts-embed-badge-wrap .rts-embed-modal__hint{
            margin:12px 0 0 0;
            color:#374151;
            font-size:0.95rem;
        }
    </style>

    <script>
    (function(){
        // Support multiple badges on a page
        document.querySelectorAll('.rts-embed-badge-wrap').forEach(function(wrap){
            const openBtn  = wrap.querySelector('.rts-embed-badge-btn');
            const modal    = wrap.querySelector('#rts-embed-badge-modal');
            const closeBtn = wrap.querySelector('.rts-embed-modal__close');
            const copyBtn  = wrap.querySelector('.rts-embed-copy');
            const textarea = wrap.querySelector('.rts-embed-modal__code');
            if(!openBtn || !modal || !closeBtn || !copyBtn || !textarea) return;

            let lastFocus = null;

            function openModal(){
                lastFocus = document.activeElement;
                modal.hidden = false;
                closeBtn.focus();
                document.addEventListener('keydown', onKeydown);
            }
            function closeModal(){
                modal.hidden = true;
                document.removeEventListener('keydown', onKeydown);
                if(lastFocus) lastFocus.focus();
            }
            function onKeydown(e){
                if(e.key === 'Escape') closeModal();
            }

            openBtn.addEventListener('click', openModal);
            closeBtn.addEventListener('click', closeModal);
            modal.addEventListener('click', function(e){
                if(e.target === modal) closeModal();
            });

            copyBtn.addEventListener('click', async function(){
                try{
                    await navigator.clipboard.writeText(textarea.value);
                }catch(err){
                    textarea.focus();
                    textarea.select();
                    document.execCommand('copy');
                }
                copyBtn.textContent = 'Copied!';
                setTimeout(()=> copyBtn.textContent = 'Copy snippet', 1200);
            });
        });
    })();
    </script>
    <?php
    return ob_get_clean();
}
add_shortcode('rts_embed_badge', 'rts_embed_badge_shortcode');

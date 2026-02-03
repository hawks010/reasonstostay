<?php
/**
 * RTS Quick Exit
 * - Desktop: stacks above the a11y widget (right side)
 * - Mobile: bottom-left (opposite typical a11y position)
 * - Icon: Font Awesome (fa-right-from-bracket)
 * - WCAG 2.2 AA: 44px target, focus-visible ring, aria-label, reduced-motion respect.
 */

if (!defined('ABSPATH')) exit;

add_action('wp_footer', function () {
    // Front-end only.
    if (is_admin()) return;

    ?>
    <div id="rts-quick-exit-wrap" aria-hidden="false">
        <button
            type="button"
            id="rts-quick-exit"
            class="rts-quick-exit"
            aria-label="Quick exit to Google"
            title="Quick exit to Google"
        >
            <span class="rts-quick-exit__icon" aria-hidden="true">
                <i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i>
            </span>
        </button>
    </div>

    <style>
        :root{
            --rts-quick-exit-size: 56px;
            --rts-quick-exit-yellow: #FCA311;
            --rts-quick-exit-black: #070C13;
            --rts-quick-exit-white: #FFFFFF;
            --rts-quick-exit-focus: #179AD6;
            --rts-quick-exit-z: 999999;
        }

        #rts-quick-exit-wrap{
            position: fixed;
            right: 10px;
            bottom: 10px;
            z-index: var(--rts-quick-exit-z);
            pointer-events: none;
        }

        .rts-quick-exit{
            pointer-events: auto;
            width: var(--rts-quick-exit-size);
            height: var(--rts-quick-exit-size);
            min-width: 44px;
            min-height: 44px;
            border-radius: 999px;
            border: 0;
            background: var(--rts-quick-exit-yellow);
            color: #000;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            box-shadow: 0 10px 26px rgba(0,0,0,0.22);
            transition: background-color 160ms ease, color 160ms ease, transform 120ms ease;
            touch-action: manipulation;
            -webkit-tap-highlight-color: transparent;
        }

        .rts-quick-exit:hover{
            background: var(--rts-quick-exit-black);
            color: var(--rts-quick-exit-white);
        }

        .rts-quick-exit:active{ transform: scale(0.98); }
        .rts-quick-exit:focus{ outline: none; }

        .rts-quick-exit:focus-visible{
            outline: 3px solid var(--rts-quick-exit-focus);
            outline-offset: 4px;
            box-shadow: 0 0 0 6px rgba(23,154,214,0.25), 0 10px 26px rgba(0,0,0,0.22);
        }

        .rts-quick-exit__icon{
            display: inline-flex;
            line-height: 0;
            align-items: center;
            justify-content: center;
        }

        /* Font Awesome icon sizing */
        .rts-quick-exit__icon i{
            font-size: 22px;
            line-height: 1;
            display: block;
        }

        @media (prefers-reduced-motion: reduce){
            .rts-quick-exit{ transition: none; }
            .rts-quick-exit:active{ transform: none; }
        }

        /* Mobile: bottom-left */
        @media (max-width: 768px){
            #rts-quick-exit-wrap{ right: auto; left: 10px; bottom: 10px; }
        }
    </style>

    <script>
        (function(){
            var exitBtn = document.getElementById('rts-quick-exit');
            if (exitBtn) {
                exitBtn.addEventListener('click', function(){
                    try { window.location.replace('https://www.google.co.uk/'); }
                    catch (e) { window.location.href = 'https://www.google.co.uk/'; }
                });
            }

            // Stack above a11y widget on desktop (if found)
            var SELECTORS = [
                '#rts-a11y-toolkit',
                '#rts-accessibility-toolkit',
                '.rts-a11y-toolkit',
                '.rts-accessibility-toolkit',
                '#rts-a11y-widget',
                '.rts-a11y-widget',
                '#rts-toolkit',
                '.rts-toolkit'
            ];

            function findA11yWidget(){
                for (var i=0; i<SELECTORS.length; i++){
                    var el = document.querySelector(SELECTORS[i]);
                    if (el) return el;
                }
                return null;
            }

            function ensureStackDesktop(){
                var isMobile = window.matchMedia && window.matchMedia('(max-width: 768px)').matches;
                var a11y = findA11yWidget();
                var exitWrap = document.getElementById('rts-quick-exit-wrap');
                if (!exitWrap) return;

                if (isMobile) {
                    var stack = document.getElementById('rts-float-stack');
                    if (stack && a11y && stack.contains(a11y)) document.body.appendChild(a11y);
                    if (stack) stack.remove();
                    
                    // Reset inline styles so CSS @media takes over for mobile
                    exitWrap.style.position = '';
                    exitWrap.style.right = '';
                    exitWrap.style.left = '';
                    exitWrap.style.bottom = '';
                    exitWrap.style.pointerEvents = '';
                    return;
                }

                if (!a11y) return;

                var stack = document.getElementById('rts-float-stack');
                if (!stack) {
                    stack = document.createElement('div');
                    stack.id = 'rts-float-stack';
                    stack.style.position = 'fixed';
                    stack.style.right = '10px';
                    stack.style.bottom = '10px';
                    stack.style.zIndex = '999998';
                    stack.style.display = 'flex';
                    stack.style.flexDirection = 'column';
                    stack.style.gap = '12px';
                    stack.style.alignItems = 'flex-end';
                    document.body.appendChild(stack);
                }

                if (exitWrap.parentNode !== stack) stack.appendChild(exitWrap);
                if (a11y.parentNode !== stack) stack.appendChild(a11y);

                exitWrap.style.position = 'static';
                exitWrap.style.right = 'auto';
                exitWrap.style.left = 'auto';
                exitWrap.style.bottom = 'auto';
                exitWrap.style.pointerEvents = 'none';
            }

            function tick(){ ensureStackDesktop(); }

            tick();
            window.addEventListener('load', function(){
                tick();
                setTimeout(tick, 300);
                setTimeout(tick, 1000);
            });
            window.addEventListener('resize', tick);
        })();
    </script>
    <?php
}, 9999);
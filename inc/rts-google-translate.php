<?php
/**
 * RTS Google Translate Integration
 * Provides automatic translation triggered by custom theme flags.
 * Uses the "Cookie Method" for reliable switching.
 */

if (!defined('ABSPATH')) { exit; }

class RTS_Google_Translate {

	private static $instance = null;

	public static function get_instance(): self {
		if (null === self::$instance) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// output the script and hidden widget in the footer
		add_action('wp_footer', [$this, 'render_google_translate_scripts']);
	}

	/**
	 * Render Google Translate widget and control scripts
	 */
	public function render_google_translate_scripts() {
		?>
		<div id="google_translate_element" style="display:none;"></div>

		<script type="text/javascript">
			function googleTranslateElementInit() {
				new google.translate.TranslateElement({
					pageLanguage: 'en',
					includedLanguages: 'en,es,fr,zh-CN,zh-TW,hi,ru,pt,ja,de,ar',
					autoDisplay: false
				}, 'google_translate_element');
			}
		</script>

		<script type="text/javascript" src="//translate.google.com/translate_a/element.js?cb=googleTranslateElementInit"></script>

		<script type="text/javascript">
			document.addEventListener('DOMContentLoaded', function() {
				// Map theme language codes to Google Translate codes
				const langMap = {
					'en': 'en',
					'es': 'es',
					'fr': 'fr',
					'zh': 'zh-CN',
					'zh-TW': 'zh-TW',
					'hi': 'hi',
					'ru': 'ru',
					'pt': 'pt',
					'ja': 'ja',
					'de': 'de',
					'ar': 'ar'
				};

				// Select all language flags/options in the theme
				const flags = document.querySelectorAll('.rts-lang-flag, .rts-lang-compact-option, .rts-lang-option');

				flags.forEach(function(flag) {
					flag.addEventListener('click', function(e) {
						// 1. Stop the default theme link behavior (prevent ?rts_lang=xx reload)
						const combo = document.querySelector('select.goog-te-combo');
						const gtReady = !!(window.google && google.translate && combo);
						if (!gtReady) { return; } // fall back to normal ?rts_lang navigation
						e.preventDefault();

						const themeLang = flag.getAttribute('data-lang');
						if (!themeLang) return;

						// Map to Google Translate code
						const targetLang = langMap[themeLang] || themeLang;

						// 2. Clear existing Google cookies to ensure clean switch
						document.cookie = "googtrans=; path=/; domain=" + document.domain + "; expires=Thu, 01 Jan 1970 00:00:00 UTC";
						document.cookie = "googtrans=; path=/; expires=Thu, 01 Jan 1970 00:00:00 UTC";

						// 3. Set the new Google Translate cookie
						// Format: /source/target (e.g., /auto/es for Spanish)
						const cookieValue = '/en/' + targetLang;
						document.cookie = "googtrans=" + cookieValue + "; path=/; domain=" + document.domain;
						document.cookie = "googtrans=" + cookieValue + "; path=/;";

						// 4. Also set the Theme's preference cookie (for UI consistency)
						document.cookie = "rts_language=" + themeLang + "; path=/; max-age=" + (365 * 24 * 60 * 60);

						// 5. Reload the page - Google Translate will read the cookie and translate immediately on load
						window.location.reload();
					});
				});
			});
		</script>

		<style>
			/* Hide the Google Top Bar that appears after translation */
			.goog-te-banner-frame.skiptranslate {
				display: none !important;
			}
			body {
				top: 0px !important;
			}
			/* Hide the widget completely */
			#google_translate_element {
				display: none !important;
				position: absolute;
				opacity: 0;
				pointer-events: none;
			}
			.goog-logo-link,
			.goog-te-gadget span,
			.goog-te-gadget a {
				display: none !important;
			}
			/* Ensure flags look clickable */
			.rts-lang-flag,
			.rts-lang-compact-option,
			.rts-lang-option {
				cursor: pointer;
			}
		</style>
		<?php
	}
}

// Initialize
RTS_Google_Translate::get_instance();

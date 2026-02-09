<?php
/**
 * RTS Google Translate Integration
 * Provides automatic translation triggered by theme language switcher.
 *
 * Design goals:
 * - Never break normal navigation or other clickable UI.
 * - Expose a small JS API the main rts-system.js can call (single source of truth).
 * - Use the reliable Google "cookie method" (googtrans) + reload.
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
		// Output the script and hidden widget in the footer
		add_action('wp_footer', [$this, 'render_google_translate_scripts'], 50);
	}

	/**
	 * Render Google Translate widget and control scripts
	 */
	public function render_google_translate_scripts() {
		?>
		<div id="google_translate_element" style="display:none;"></div>

		<script type="text/javascript">
			function googleTranslateElementInit() {
				try {
					window.RTSGoogleTranslate = window.RTSGoogleTranslate || {};
					window.RTSGoogleTranslate.isReady = true;

					new google.translate.TranslateElement({
						pageLanguage: 'en',
						// SYNCED: Full list matching rts-multilingual.php
						// Hebrew is 'iw' in Google Translate, Chinese is 'zh-CN'
						includedLanguages: 'en,es,fr,de,it,pt,nl,pl,ro,hu,cs,sv,no,da,fi,el,ru,uk,ar,iw,tr,hi,zh-CN,ja,ko,vi,th,id',
						autoDisplay: false
					}, 'google_translate_element');
				} catch (e) {}
			}
		</script>

		<script type="text/javascript" src="//translate.google.com/translate_a/element.js?cb=googleTranslateElementInit"></script>

		<script type="text/javascript">
			(function() {
				// Map theme language codes to Google Translate codes
				// SYNCED: Matches rts-multilingual.php keys
				var langMap = {
					'en': 'en',
					'es': 'es',
					'fr': 'fr',
					'de': 'de',
					'it': 'it',
					'pt': 'pt',
					'nl': 'nl',
					'pl': 'pl',
					'ro': 'ro',
					'hu': 'hu',
					'cs': 'cs',
					'sv': 'sv',
					'no': 'no',
					'da': 'da',
					'fi': 'fi',
					'el': 'el',
					'ru': 'ru',
					'uk': 'uk',
					'ar': 'ar',
					'he': 'iw', // Google uses 'iw' for Hebrew
					'tr': 'tr',
					'hi': 'hi',
					'zh': 'zh-CN', // Generic 'zh' -> Simplified
					'ja': 'ja',
					'ko': 'ko',
					'vi': 'vi',
					'th': 'th',
					'id': 'id'
				};

				function setCookie(name, value, maxAgeSeconds) {
					try {
						var parts = [name + '=' + value, 'path=/'];
						if (typeof maxAgeSeconds === 'number') parts.push('max-age=' + maxAgeSeconds);
						document.cookie = parts.join('; ');
					} catch (e) {}
				}

				function clearGoogTransCookie() {
					try {
						var expired = 'expires=Thu, 01 Jan 1970 00:00:00 GMT';
						// Clear both host-only and domain cookies (common GT behavior)
						document.cookie = 'googtrans=; path=/; ' + expired;
						document.cookie = 'googtrans=; path=/; domain=' + document.domain + '; ' + expired;
						var rootDomain = ('.' + document.domain).replace(/^\.www\./, '.');
						document.cookie = 'googtrans=; path=/; domain=' + rootDomain + '; ' + expired;
					} catch (e) {}
				}

				function applyLanguage(themeLang) {
					if (!themeLang) return;

					var targetLang = langMap[themeLang] || themeLang;

					// Always persist theme preference for UI consistency
					setCookie('rts_language', themeLang, 365 * 24 * 60 * 60);

					// If GT script isn't ready yet, allow setting cookie but warn/wait
					if (!window.RTSGoogleTranslate || !window.RTSGoogleTranslate.isReady) {
						// Proceeding anyway as the reload often fixes the load state
					}

					clearGoogTransCookie();

					// Format: /source/target (e.g., /en/es)
					var cookieValue = '/en/' + targetLang;

					try {
						// Host-only + domain cookies (covers most setups)
						document.cookie = 'googtrans=' + cookieValue + '; path=/';
						document.cookie = 'googtrans=' + cookieValue + '; path=/; domain=' + document.domain;
						
						// Handle subdomains/root domain logic (Crucial for mobile redirecting)
						var rootDomain2 = ('.' + document.domain).replace(/^\.www\./, '.');
						document.cookie = 'googtrans=' + cookieValue + '; path=/; domain=' + rootDomain2;
					} catch (e) {}

					// IPHONE FIX: Increased timeout to 500ms.
					// iOS Safari sometimes fails to write cookies if the page reloads too instantly.
					setTimeout(function() { window.location.reload(); }, 500);
					return true;
				}

				// Expose a tiny API for rts-system.js (single source of truth for click behavior)
				window.RTSGoogleTranslate = window.RTSGoogleTranslate || {};
				window.RTSGoogleTranslate.isReady = window.RTSGoogleTranslate.isReady || false;
				window.RTSGoogleTranslate.setLang = applyLanguage;
				window.RTSGoogleTranslate.langMap = langMap;
			})();
		</script>

		<style>
			/* Hide the Google top bar that appears after translation */
			.goog-te-banner-frame.skiptranslate { display: none !important; }
			body { top: 0px !important; }

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
			.rts-lang-option { cursor: pointer; }
		</style>
		<?php
	}
}

// Initialize
RTS_Google_Translate::get_instance();
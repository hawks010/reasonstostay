<?php
/**
 * RTS Translation Integration (JigsawStack)
 *
 * REPLACED: Google Translate widget with JigsawStack Translation Widget (open-source)
 * WHY: Google's cookie-based approach was unreliable, especially on iPhone Safari
 *
 * QA CHECKLIST:
 * ✓ Desktop Chrome:
 *   - Load page, switch to Spanish → content translates without reload
 *   - Switch back to English → translation resets
 *   - Switch to Arabic → RTL direction applies, layout usable
 *   - No console errors
 *
 * ✓ iPhone Safari:
 *   - Switch language → translation applies and persists on navigation
 *   - Reload page → translation auto-applies from rts_language cookie
 *   - No stuck loading overlay
 *   - No console errors
 *
 * ✓ Regression checks:
 *   - Language switcher UI renders exactly as before
 *   - No other click behaviour broken
 *   - No requests to translate.google.com
 *   - No googtrans cookie created
 *   - rts_language cookie set correctly
 *
 * Design goals:
 * - Never break normal navigation or other clickable UI
 * - Expose same JS API for backwards compatibility (window.RTSGoogleTranslate.setLang)
 * - No page reload required
 * - Cookie persistence for seamless multi-page experience
 * - RTL support for Arabic/Hebrew
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
		add_action('wp_footer', [$this, 'render_translation_scripts'], 50);
	}

	/**
	 * Get JigsawStack API key
	 * Supports multiple configuration methods for flexibility
	 */
	private function get_api_key(): string {
		// Method 1: WordPress option (can be set in admin or via code)
		$key = get_option('rts_jigsawstack_api_key', '');
		if (!empty($key)) {
			return $key;
		}

		// Method 2: PHP constant (set in wp-config.php or theme)
		if (defined('RTS_JIGSAWSTACK_API_KEY')) {
			return RTS_JIGSAWSTACK_API_KEY;
		}

		// Method 3: Environment variable
		$env_key = getenv('RTS_JIGSAWSTACK_API_KEY');
		if (!empty($env_key)) {
			return $env_key;
		}

		// Return empty string if no key configured
		// The widget will fail gracefully and log to console
		return '';
	}

	/**
	 * Render JigsawStack Translation Widget scripts
	 */
	public function render_translation_scripts() {
		$api_key = $this->get_api_key();

		// If no API key, show admin notice and bail
		if (empty($api_key)) {
			if (current_user_can('manage_options')) {
				echo '<!-- RTS Translation: No JigsawStack API key configured. Set RTS_JIGSAWSTACK_API_KEY constant or rts_jigsawstack_api_key option. -->';
			}
			// Still output the API stub so the language switcher doesn't break
			?>
			<script type="text/javascript">
				window.RTSGoogleTranslate = window.RTSGoogleTranslate || {};
				window.RTSGoogleTranslate.isReady = false;
				window.RTSGoogleTranslate.setLang = function() {
					console.warn('RTS Translation: No JigsawStack API key configured');
				};
				window.RTSGoogleTranslate.langMap = {};
			</script>
			<?php
			return;
		}

		?>
		<!-- JigsawStack Translation Widget -->
		<script defer src="https://unpkg.com/translation-widget@latest/dist/index.min.js"></script>

		<script type="text/javascript">
			(function() {
				'use strict';

				// Map theme language codes to JigsawStack widget codes
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
					'he': 'he',  // JigsawStack uses 'he' not 'iw'
					'tr': 'tr',
					'hi': 'hi',
					'zh': 'zh',  // JigsawStack uses 'zh' not 'zh-CN'
					'ja': 'ja',
					'ko': 'ko',
					'vi': 'vi',
					'th': 'th',
					'id': 'id'
				};

				// RTL languages
				var rtlLanguages = ['ar', 'he'];

				// Build includedLanguages list for widget
				var includedLanguages = Object.keys(langMap).map(function(key) {
					return langMap[key];
				}).join(',');

				// Cookie helpers
				function setCookie(name, value, maxAgeSeconds) {
					try {
						var parts = [name + '=' + encodeURIComponent(value), 'path=/', 'SameSite=Lax'];
						if (typeof maxAgeSeconds === 'number') {
							parts.push('max-age=' + maxAgeSeconds);
						}
						document.cookie = parts.join('; ');
					} catch (e) {
						console.error('RTS Translation: Cookie set failed', e);
					}
				}

				function getCookie(name) {
					try {
						var match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'));
						return match ? decodeURIComponent(match[2]) : null;
					} catch (e) {
						return null;
					}
				}

				function clearCookie(name) {
					try {
						document.cookie = name + '=; path=/; expires=Thu, 01 Jan 1970 00:00:00 GMT';
					} catch (e) {}
				}

				// RTL direction management
				function applyDirection(lang) {
					var isRTL = rtlLanguages.indexOf(lang) !== -1;
					var html = document.documentElement;
					var body = document.body;

					if (isRTL) {
						html.setAttribute('dir', 'rtl');
						body.classList.add('rts-rtl');
					} else {
						html.setAttribute('dir', 'ltr');
						body.classList.remove('rts-rtl');
					}
				}

				// Loading overlay management
				function showLoading() {
					var body = document.body;
					if (body) {
						body.style.opacity = '0.6';
						body.style.pointerEvents = 'none';
						body.style.transition = 'opacity 0.3s ease';
					}
				}

				function hideLoading() {
					var body = document.body;
					if (body) {
						body.style.opacity = '1';
						body.style.pointerEvents = 'auto';
					}
				}

				// Translation state
				var translationState = {
					initialized: false,
					currentLang: 'en',
					isTranslating: false
				};

				// Initialize widget when script loads
				function initializeWidget() {
					if (translationState.initialized) {
						return;
					}

					if (typeof TranslationWidget === 'undefined') {
						console.error('RTS Translation: TranslationWidget not loaded');
						return;
					}

					try {
						TranslationWidget('<?php echo esc_js($api_key); ?>', {
							pageLanguage: 'en',
							showUI: false,
							autoDetectLanguage: false,
							position: 'top-right',
							theme: {
								baseColor: '',
								textColor: ''
							}
						});

						translationState.initialized = true;
						window.RTSGoogleTranslate.isReady = true;

						// Check for saved language preference and auto-apply
						var savedLang = getCookie('rts_language');
						if (savedLang && savedLang !== 'en') {
							// Small delay to ensure widget is fully ready
							setTimeout(function() {
								applyLanguage(savedLang, true);
							}, 100);
						}
					} catch (e) {
						console.error('RTS Translation: Widget init failed', e);
					}
				}

				// Apply translation for a specific language
				function applyLanguage(themeLang, isSilent) {
					if (!themeLang) {
						console.warn('RTS Translation: No language specified');
						return;
					}

					// Prevent concurrent translations
					if (translationState.isTranslating) {
						console.warn('RTS Translation: Already translating, please wait');
						return;
					}

					var targetLang = langMap[themeLang] || themeLang;

					// If widget not initialized yet, wait
					if (!translationState.initialized) {
						console.warn('RTS Translation: Widget not ready, retrying...');
						setTimeout(function() {
							applyLanguage(themeLang, isSilent);
						}, 200);
						return;
					}

					// Always persist theme preference
					setCookie('rts_language', themeLang, 365 * 24 * 60 * 60);

					// Apply RTL direction if needed
					applyDirection(themeLang);

					// If switching to English, reset translation
					if (themeLang === 'en' || targetLang === 'en') {
						if (!isSilent) {
							showLoading();
						}

						translationState.isTranslating = true;

						if (typeof window.resetTranslation === 'function') {
							window.resetTranslation(
								'en',
								function(res) {
									translationState.isTranslating = false;
									translationState.currentLang = 'en';
									hideLoading();
									console.log('RTS Translation: Reset to English', res);
								},
								function(err) {
									translationState.isTranslating = false;
									hideLoading();
									console.error('RTS Translation: Reset failed', err);
									localStorage.setItem('rts_translate_error', JSON.stringify({
										time: new Date().toISOString(),
										lang: themeLang,
										error: err
									}));
								}
							);
						} else {
							// Fallback: reload if resetTranslation not available
							console.warn('RTS Translation: resetTranslation not available, reloading page');
							window.location.reload();
						}

						return;
					}

					// Apply translation for non-English language
					if (!isSilent) {
						showLoading();
					}

					translationState.isTranslating = true;

					if (typeof window.translate === 'function') {
						window.translate(
							targetLang,
							function(res) {
								translationState.isTranslating = false;
								translationState.currentLang = themeLang;
								hideLoading();
								console.log('RTS Translation: Applied ' + themeLang, res);
							},
							function(err) {
								translationState.isTranslating = false;
								hideLoading();
								console.error('RTS Translation: Translation failed', err);
								localStorage.setItem('rts_translate_error', JSON.stringify({
									time: new Date().toISOString(),
									lang: themeLang,
									error: err
								}));
							}
						);
					} else {
						translationState.isTranslating = false;
						hideLoading();
						console.error('RTS Translation: window.translate not available');
					}
				}

				// Expose API for backwards compatibility with existing theme scripts
				window.RTSGoogleTranslate = window.RTSGoogleTranslate || {};
				window.RTSGoogleTranslate.isReady = false;
				window.RTSGoogleTranslate.setLang = applyLanguage;
				window.RTSGoogleTranslate.langMap = langMap;
				window.RTSGoogleTranslate.getState = function() {
					return translationState;
				};

				// Initialize when widget script is loaded
				if (typeof TranslationWidget !== 'undefined') {
					initializeWidget();
				} else {
					// Poll for widget availability (script is loaded with defer)
					var initCheckCount = 0;
					var initInterval = setInterval(function() {
						initCheckCount++;
						if (typeof TranslationWidget !== 'undefined') {
							clearInterval(initInterval);
							initializeWidget();
						} else if (initCheckCount > 50) {
							// Give up after 10 seconds (50 * 200ms)
							clearInterval(initInterval);
							console.error('RTS Translation: Widget script failed to load');
						}
					}, 200);
				}

				// Clean up old Google Translate cookies if present
				clearCookie('googtrans');
			})();
		</script>

		<style>
			/* RTL Support */
			body.rts-rtl {
				direction: rtl;
			}

			/* Ensure language switcher elements are always clickable */
			.rts-lang-flag,
			.rts-lang-compact-option,
			.rts-lang-option {
				cursor: pointer;
			}

			/* Loading state visual feedback */
			body[style*="opacity: 0.6"] {
				cursor: wait;
			}
		</style>
		<?php
	}
}

// Initialize
RTS_Google_Translate::get_instance();

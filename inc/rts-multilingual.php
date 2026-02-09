<?php
/**
 * RTS Multilingual Support Framework
 *
 * Supports translation for:
 * - English, Spanish, French, German, Italian, Portuguese
 * - Dutch, Polish, Romanian, Hungarian, Czech, Swedish, Norwegian, Danish, Finnish, Greek
 * - Russian, Ukrainian
 * - Arabic, Hebrew, Turkish
 * - Hindi
 * - Chinese, Japanese, Korean, Vietnamese, Thai, Indonesian
 *
 * INTEGRATION:
 * - Works with Polylang, WPML, or standalone
 * - Auto-detects language from browser
 * - Translates UI strings, safety keywords, and letter content
 */

if (!defined('ABSPATH')) { exit; }

class RTS_Multilingual {

	private static $instance = null;
	private $current_language = 'en';
	private $supported_languages = [];
	private $translations = [];

	public static function get_instance(): self {
		if (null === self::$instance) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->supported_languages = [
			// High-traffic / high-likelihood languages first
			'en' => ['name' => 'English', 'flag' => '🇬🇧', 'google_code' => 'en', 'dir' => 'ltr'],
			'es' => ['name' => 'Español', 'flag' => '🇪🇸', 'google_code' => 'es', 'dir' => 'ltr'],
			'fr' => ['name' => 'Français', 'flag' => '🇫🇷', 'google_code' => 'fr', 'dir' => 'ltr'],
			'de' => ['name' => 'Deutsch', 'flag' => '🇩🇪', 'google_code' => 'de', 'dir' => 'ltr'],
			'it' => ['name' => 'Italiano', 'flag' => '🇮🇹', 'google_code' => 'it', 'dir' => 'ltr'],
			'pt' => ['name' => 'Português', 'flag' => '🇵🇹', 'google_code' => 'pt', 'dir' => 'ltr'],

			// Europe
			'nl' => ['name' => 'Nederlands', 'flag' => '🇳🇱', 'google_code' => 'nl', 'dir' => 'ltr'],
			'pl' => ['name' => 'Polski', 'flag' => '🇵🇱', 'google_code' => 'pl', 'dir' => 'ltr'],
			'ro' => ['name' => 'Română', 'flag' => '🇷🇴', 'google_code' => 'ro', 'dir' => 'ltr'],
			'hu' => ['name' => 'Magyar', 'flag' => '🇭🇺', 'google_code' => 'hu', 'dir' => 'ltr'],
			'cs' => ['name' => 'Čeština', 'flag' => '🇨🇿', 'google_code' => 'cs', 'dir' => 'ltr'],
			'sv' => ['name' => 'Svenska', 'flag' => '🇸🇪', 'google_code' => 'sv', 'dir' => 'ltr'],
			'no' => ['name' => 'Norsk', 'flag' => '🇳🇴', 'google_code' => 'no', 'dir' => 'ltr'],
			'da' => ['name' => 'Dansk', 'flag' => '🇩🇰', 'google_code' => 'da', 'dir' => 'ltr'],
			'fi' => ['name' => 'Suomi', 'flag' => '🇫🇮', 'google_code' => 'fi', 'dir' => 'ltr'],
			'el' => ['name' => 'Ελληνικά', 'flag' => '🇬🇷', 'google_code' => 'el', 'dir' => 'ltr'],

			// Eastern Europe + wider
			'ru' => ['name' => 'Русский', 'flag' => '🇷🇺', 'google_code' => 'ru', 'dir' => 'ltr'],
			'uk' => ['name' => 'Українська', 'flag' => '🇺🇦', 'google_code' => 'uk', 'dir' => 'ltr'],

			// Middle East
			'ar' => ['name' => 'العربية', 'flag' => '🇸🇦', 'google_code' => 'ar', 'dir' => 'rtl'],
			'he' => ['name' => 'עברית', 'flag' => '🇮🇱', 'google_code' => 'iw', 'dir' => 'rtl'],
			'tr' => ['name' => 'Türkçe', 'flag' => '🇹🇷', 'google_code' => 'tr', 'dir' => 'ltr'],

			// South Asia
			'hi' => ['name' => 'हिन्दी', 'flag' => '🇮🇳', 'google_code' => 'hi', 'dir' => 'ltr'],

			// East + SE Asia
			'zh' => ['name' => '中文', 'flag' => '🇨🇳', 'google_code' => 'zh-CN', 'dir' => 'ltr'],
			'ja' => ['name' => '日本語', 'flag' => '🇯🇵', 'google_code' => 'ja', 'dir' => 'ltr'],
			'ko' => ['name' => '한국어', 'flag' => '🇰🇷', 'google_code' => 'ko', 'dir' => 'ltr'],
			'vi' => ['name' => 'Tiếng Việt', 'flag' => '🇻🇳', 'google_code' => 'vi', 'dir' => 'ltr'],
			'th' => ['name' => 'ไทย', 'flag' => '🇹🇭', 'google_code' => 'th', 'dir' => 'ltr'],
			'id' => ['name' => 'Bahasa Indonesia', 'flag' => '🇮🇩', 'google_code' => 'id', 'dir' => 'ltr'],
		];

        // Normalise language definitions for backwards-compatibility.
        // Some renderers expect `native` but older configs only define `name`.
        foreach ($this->supported_languages as $code => $lang) {
            if (!isset($lang['native']) || $lang['native'] === '') {
                $this->supported_languages[$code]['native'] = $lang['name'] ?? strtoupper((string) $code);
            }
            if (!isset($lang['name']) || $lang['name'] === '') {
                $this->supported_languages[$code]['name'] = $this->supported_languages[$code]['native'];
            }
            if (!isset($lang['flag'])) {
                $this->supported_languages[$code]['flag'] = '🌐';
            }
        }

		$this->current_language = $this->detect_language();
		$this->load_translations();

		add_action('init', [$this, 'register_hooks']);
	}

	public function register_hooks(): void {
		// Language switcher shortcode
		add_shortcode('rts_language_switcher', [$this, 'render_language_switcher']);

		// Filter letter content for translation
		add_filter('the_content', [$this, 'maybe_translate_content'], 15);

		// Add language meta box to letters
		add_action('add_meta_boxes', [$this, 'add_language_meta_box']);
		add_action('save_post_letter', [$this, 'save_language_meta'], 10, 2);

		// Enqueue language-specific CSS
		add_action('wp_enqueue_scripts', [$this, 'enqueue_language_assets']);

		// Handle language switching via query param
		add_action('init', [$this, 'handle_language_switch']);
	}

	/**
	 * Handle language switch from query parameter
	 */
	public function handle_language_switch(): void {
		if (!empty($_GET['rts_lang'])) {
			$lang = sanitize_key($_GET['rts_lang']);
			if (isset($this->supported_languages[$lang])) {
				// Set cookie for standalone mode
				setcookie('rts_language', $lang, time() + (365 * DAY_IN_SECONDS), COOKIEPATH, COOKIE_DOMAIN);
				$_COOKIE['rts_language'] = $lang;

				// If Polylang is active, redirect to translated URL
				if (function_exists('pll_the_languages') && function_exists('pll_home_url')) {
					$home_url = pll_home_url($lang);
					if ($home_url && $home_url !== home_url('/')) {
						wp_safe_redirect($home_url);
						exit;
					}
				}

				// If WPML is active, switch language
				if (defined('ICL_LANGUAGE_CODE')) {
					do_action('wpml_switch_language', $lang);
					// Redirect to translated version of current page
					if (function_exists('icl_get_home_url')) {
						wp_safe_redirect(icl_get_home_url());
						exit;
					}
				}
			}
		}
	}

	/**
	 * Detect language from browser, user preference, or Polylang/WPML
	 */
	private function detect_language(): string {
		// 1. Check user preference (cookie/session)
		if (!empty($_COOKIE['rts_language'])) {
			$lang = sanitize_key($_COOKIE['rts_language']);
			if (isset($this->supported_languages[$lang])) {
				return $lang;
			}
		}

		// 2. Check Polylang
		if (function_exists('pll_current_language')) {
			return pll_current_language();
		}

		// 3. Check WPML
		if (function_exists('icl_get_current_language')) {
			return icl_get_current_language();
		}

		// 4. Check browser Accept-Language header
		if (!empty($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
			$browser_lang = substr($_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 2);
			if (isset($this->supported_languages[$browser_lang])) {
				return $browser_lang;
			}
		}

		return 'en'; // Default
	}

	/**
	 * Load translation files
	 */
	private function load_translations(): void {
		$lang_dir = get_stylesheet_directory() . '/languages/';

		if (!is_dir($lang_dir)) {
			@mkdir($lang_dir, 0755, true);
		}

		$lang_file = $lang_dir . 'rts-' . $this->current_language . '.json';

		if (file_exists($lang_file)) {
			$json = file_get_contents($lang_file);
			$this->translations = json_decode($json, true) ?: [];
		} else {
			$this->translations = $this->get_default_translations($this->current_language);
		}
	}

	/**
	 * Get default translations (embedded, no external files needed for MVP)
	 */
	private function get_default_translations(string $lang): array {
		$translations = [
			'en' => [
				'read_letter' => 'Read a Letter',
				'next_letter' => 'Next Letter',
				'was_helpful' => 'Was this letter helpful?',
				'yes_helpful' => 'Yes, this helped',
				'no_not_helpful' => 'Not helpful',
				'submit_letter' => 'Submit Your Letter',
				'your_letter' => 'Your Letter',
				'submit' => 'Submit',
				'thank_you' => 'Thank you for sharing',
				'you_are_not_alone' => 'You are not alone',
				'no_letter_available' => 'No letter available right now. Please refresh the page in a moment.',
			],
			'es' => [
				'read_letter' => 'Leer una Carta',
				'next_letter' => 'Siguiente Carta',
				'was_helpful' => '¿Esta carta fue útil?',
				'yes_helpful' => 'Sí, esto ayudó',
				'no_not_helpful' => 'No fue útil',
				'submit_letter' => 'Enviar Tu Carta',
				'your_letter' => 'Tu Carta',
				'submit' => 'Enviar',
				'thank_you' => 'Gracias por compartir',
				'you_are_not_alone' => 'No estás solo',
				'no_letter_available' => 'No hay cartas disponibles en este momento. Por favor, actualice la página en un momento.',
			],
			'fr' => [
				'read_letter' => 'Lire une Lettre',
				'next_letter' => 'Lettre Suivante',
				'was_helpful' => 'Cette lettre était-elle utile?',
				'yes_helpful' => 'Oui, cela a aidé',
				'no_not_helpful' => 'Pas utile',
				'submit_letter' => 'Soumettre Votre Lettre',
				'your_letter' => 'Votre Lettre',
				'submit' => 'Soumettre',
				'thank_you' => 'Merci de partager',
				'you_are_not_alone' => 'Vous n\'êtes pas seul',
			],
			'zh' => [
				'read_letter' => '阅读一封信',
				'next_letter' => '下一封信',
				'was_helpful' => '这封信有帮助吗？',
				'yes_helpful' => '是的，这有帮助',
				'no_not_helpful' => '没有帮助',
				'submit_letter' => '提交您的信',
				'your_letter' => '您的信',
				'submit' => '提交',
				'thank_you' => '感谢分享',
				'you_are_not_alone' => '你并不孤单',
			],
			'hi' => [
				'read_letter' => 'एक पत्र पढ़ें',
				'next_letter' => 'अगला पत्र',
				'was_helpful' => 'क्या यह पत्र सहायक था?',
				'yes_helpful' => 'हाँ, इससे मदद मिली',
				'no_not_helpful' => 'सहायक नहीं',
				'submit_letter' => 'अपना पत्र जमा करें',
				'your_letter' => 'आपका पत्र',
				'submit' => 'जमा करें',
				'thank_you' => 'साझा करने के लिए धन्यवाद',
				'you_are_not_alone' => 'आप अकेले नहीं हैं',
			],
			'ru' => [
				'read_letter' => 'Прочитать Письмо',
				'next_letter' => 'Следующее Письмо',
				'was_helpful' => 'Было ли это письмо полезным?',
				'yes_helpful' => 'Да, это помогло',
				'no_not_helpful' => 'Не полезно',
				'submit_letter' => 'Отправить Ваше Письмо',
				'your_letter' => 'Ваше Письмо',
				'submit' => 'Отправить',
				'thank_you' => 'Спасибо за то, что поделились',
				'you_are_not_alone' => 'Вы не одиноки',
			],
			'ar' => [
				'read_letter' => 'اقرأ رسالة',
				'next_letter' => 'الرسالة التالية',
				'was_helpful' => 'هل كانت هذه الرسالة مفيدة؟',
				'yes_helpful' => 'نعم، هذا ساعد',
				'no_not_helpful' => 'ليس مفيدًا',
				'submit_letter' => 'قدم رسالتك',
				'your_letter' => 'رسالتك',
				'submit' => 'إرسال',
				'thank_you' => 'شكرا للمشاركة',
				'you_are_not_alone' => 'أنت لست وحدك',
			],
		];

		return $translations[$lang] ?? $translations['en'];
	}

	/**
	 * Translate a string
	 *
	 * @param string $key Translation key
	 * @param string $default Default text if not found
	 * @return string
	 */
	public function translate(string $key, string $default = ''): string {
		return $this->translations[$key] ?? $default;
	}

	/**
	 * Get language switch URL
	 * Returns Polylang/WPML translated URL if available, otherwise query param
	 */
	private function get_language_url(string $lang_code): string {
		global $post;

		// Polylang: Get translated URL for current post/page
		if (function_exists('pll_get_post') && function_exists('get_permalink') && $post) {
			$translated_post_id = pll_get_post($post->ID, $lang_code);
			if ($translated_post_id) {
				return get_permalink($translated_post_id);
			}
		}

		// Polylang: Fall back to language home URL
		if (function_exists('pll_home_url')) {
			return pll_home_url($lang_code);
		}

		// WPML: Get translated URL
		if (function_exists('icl_get_languages')) {
			$languages = icl_get_languages('skip_missing=0');
			if (isset($languages[$lang_code]['url'])) {
				return $languages[$lang_code]['url'];
			}
		}

		// Standalone mode: Use query parameter
		return add_query_arg('rts_lang', $lang_code);
	}

	/**
	 * Shorthand helper function
	 */
	public function __($key, $default = '') {
		return $this->translate($key, $default);
	}

	/**
	 * Render language switcher
	 */
	public function render_language_switcher($atts = []): string {
		$atts = shortcode_atts([
			'style' => 'dropdown', // dropdown, flags, or compact
		], $atts);

		ob_start();

		if ($atts['style'] === 'compact') {
			// Compact header-friendly dropdown
			$current_lang_data = $this->supported_languages[$this->current_language] ?? $this->supported_languages['en'];

			echo '<div class="rts-language-switcher rts-compact">';
			echo '<div class="rts-lang-compact-wrapper">';
			echo sprintf(
				'<button type="button" class="rts-lang-compact-button" aria-haspopup="listbox" aria-expanded="false" title="Change Language">
					<span class="rts-flag-emoji">%s</span>
					<span class="rts-lang-code">%s</span>
					<svg class="rts-dropdown-arrow" width="10" height="10" viewBox="0 0 12 12" fill="none" xmlns="http://www.w3.org/2000/svg">
						<path d="M3 4.5L6 7.5L9 4.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
					</svg>
				</button>',
				$current_lang_data['flag'],
				strtoupper($this->current_language)
			);

			echo '<ul class="rts-lang-compact-menu" role="listbox" style="display:none;">';
			foreach ($this->supported_languages as $code => $lang) {
				$active = ($code === $this->current_language) ? ' aria-selected="true"' : '';
				$url = add_query_arg('rts_lang', $code);
				echo sprintf(
					'<li role="option"%s><a href="%s" class="rts-lang-compact-option" data-lang="%s"><span class="rts-flag-emoji">%s</span> <span class="rts-lang-text">%s</span></a></li>',
					$active,
					esc_url($url),
					esc_attr($code),
					$lang['flag'],
					esc_html($lang['native'])
				);
			}
			echo '</ul>';
			echo '</div>'; // .rts-lang-compact-wrapper

			echo '</div>'; // .rts-language-switcher
		} else if ($atts['style'] === 'flags') {
			// Flag grid layout
			echo '<div class="rts-language-switcher rts-flags">';

			// Show current language indicator for testing
			echo '<div class="rts-current-lang-indicator" style="grid-column: 1 / -1; margin-bottom: 12px; padding: 12px; background: rgba(252, 163, 17, 0.1); border-radius: 8px; border: 2px solid #FCA311; text-align: center;">
				<strong style="color: #FCA311;">Current Language:</strong>
				<span style="color: #1d2327; font-weight: 600;"> ' . esc_html($this->supported_languages[$this->current_language]['native']) . ' (' . esc_html(strtoupper($this->current_language)) . ')</span>
			</div>';

			foreach ($this->supported_languages as $code => $lang) {
				$active = ($code === $this->current_language) ? 'active' : '';
				$url = add_query_arg('rts_lang', $code);
				echo sprintf(
					'<a href="%s" class="rts-lang-flag %s" title="%s" data-lang="%s"><span class="rts-flag-emoji">%s</span> <span class="rts-lang-name">%s</span></a>',
					esc_url($url),
					esc_attr($active),
					esc_attr($lang['name']),
					esc_attr($code),
					$lang['flag'],
					esc_html($lang['native'])
				);
			}
			echo '</div>';

			// JavaScript for flag grid
		} else {
			// Custom styled dropdown with flags
			$current_lang_data = $this->supported_languages[$this->current_language] ?? $this->supported_languages['en'];

			echo '<div class="rts-language-switcher rts-dropdown">';
			echo '<div class="rts-lang-dropdown-wrapper">';
			echo sprintf(
				'<button type="button" class="rts-lang-dropdown-button" aria-haspopup="listbox" aria-expanded="false">
					<span class="rts-flag-emoji">%s</span>
					<span class="rts-lang-label">%s</span>
					<svg class="rts-dropdown-arrow" width="12" height="12" viewBox="0 0 12 12" fill="none" xmlns="http://www.w3.org/2000/svg">
						<path d="M3 4.5L6 7.5L9 4.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
					</svg>
				</button>',
				$current_lang_data['flag'],
				esc_html($current_lang_data['native'])
			);

			echo '<ul class="rts-lang-dropdown-menu" role="listbox" style="display:none;">';
			foreach ($this->supported_languages as $code => $lang) {
				$active = ($code === $this->current_language) ? ' aria-selected="true"' : '';
				$url = add_query_arg('rts_lang', $code);
				echo sprintf(
					'<li role="option"%s><a href="%s" class="rts-lang-option" data-lang="%s"><span class="rts-flag-emoji">%s</span> <span class="rts-lang-text">%s</span></a></li>',
					$active,
					esc_url($url),
					esc_attr($code),
					$lang['flag'],
					esc_html($lang['native'])
				);
			}
			echo '</ul>';
			echo '</div>'; // .rts-lang-dropdown-wrapper

			// JavaScript for dropdown functionality
			echo '</div>'; // .rts-language-switcher
		}

		return ob_get_clean();
	}

	/**
	 * Add language meta box to letter edit screen
	 */
	public function add_language_meta_box(): void {
		add_meta_box(
			'rts_letter_language',
			__('Letter Language'),
			[$this, 'render_language_meta_box'],
			'letter',
			'side',
			'default'
		);
	}

	public function render_language_meta_box(\WP_Post $post): void {
		wp_nonce_field('rts_language_meta', 'rts_language_nonce');

		$current_lang = get_post_meta($post->ID, 'rts_letter_language', true) ?: 'en';

		echo '<select name="rts_letter_language" style="width:100%">';
		foreach ($this->supported_languages as $code => $lang) {
			$selected = ($code === $current_lang) ? 'selected' : '';
			echo sprintf(
				'<option value="%s" %s>%s (%s)</option>',
				esc_attr($code),
				$selected,
				esc_html($lang['native']),
				esc_html($lang['name'])
			);
		}
		echo '</select>';

		echo '<p class="description">Used for language-specific moderation rules.</p>';
	}

	public function save_language_meta(int $post_id, \WP_Post $post): void {
		if (!isset($_POST['rts_language_nonce']) || !wp_verify_nonce($_POST['rts_language_nonce'], 'rts_language_meta')) {
			return;
		}

		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
			return;
		}

		if (!current_user_can('edit_post', $post_id)) {
			return;
		}

		if (!empty($_POST['rts_letter_language'])) {
			$lang = sanitize_key($_POST['rts_letter_language']);
			if (isset($this->supported_languages[$lang])) {
				update_post_meta($post_id, 'rts_letter_language', $lang);
			}
		}
	}

	/**
	 * Auto-translate content (if translation API available)
	 */
	public function maybe_translate_content(string $content): string {
		// This is a placeholder for integration with Google Translate API, DeepL, etc.
		// For MVP, we just add language class to content wrapper

		if (!is_singular('letter')) {
			return $content;
		}

		global $post;
		$letter_lang = get_post_meta($post->ID, 'rts_letter_language', true) ?: 'en';
		$dir = $this->supported_languages[$letter_lang]['dir'] ?? 'ltr';

		return sprintf(
			'<div class="rts-letter-content" lang="%s" dir="%s">%s</div>',
			esc_attr($letter_lang),
			esc_attr($dir),
			$content
		);
	}

	/**
	 * Enqueue RTL CSS if needed
	 */
	public function enqueue_language_assets(): void {
		$dir = $this->supported_languages[$this->current_language]['dir'] ?? 'ltr';

		if ($dir === 'rtl') {
			wp_enqueue_style(
				'rts-rtl',
				get_stylesheet_directory_uri() . '/assets/css/rts-rtl.css',
				['rts-system'],
				RTS_THEME_VERSION
			);
		}

		// Inline CSS for language switcher
		wp_add_inline_style('rts-system', '
/* Language Switcher Styles */
.rts-language-switcher {
	display: inline-block;
}

/* Compact Header Style */
.rts-lang-compact-wrapper {
	position: relative;
	display: inline-block;
}

.rts-lang-compact-button {
	display: flex;
	align-items: center;
	gap: 6px;
	padding: 8px 12px;
	background: transparent;
	color: #F1E3D3;
	border: 1px solid rgba(252, 163, 17, 0.3);
	border-radius: 6px;
	cursor: pointer;
	font-size: 0.9rem;
	font-weight: 500;
	transition: all 0.2s ease;
}

.rts-lang-compact-button:hover {
	background: rgba(252, 163, 17, 0.1);
	border-color: #FCA311;
}

.rts-lang-compact-button:focus {
	outline: 2px solid #FCA311;
	outline-offset: 2px;
}

.rts-lang-code {
	font-size: 0.85rem;
	text-transform: uppercase;
	letter-spacing: 0.5px;
}

 .rts-lang-compact-menu {
	position: absolute;
	top: calc(100% + 6px);
	right: 0;
	min-width: 360px;
	max-width: calc(100vw - 20px);
	background: #182437;
	border: 2px solid #FCA311;
	border-radius: 12px;
	box-shadow: 0 6px 16px rgba(0, 0, 0, 0.4);
	list-style: none;
	margin: 0;
	padding: 10px;
	z-index: 9999;
	max-height: 420px;
	overflow-y: auto;
	display: grid;
	grid-template-columns: repeat(4, minmax(0, 1fr));
	gap: 8px;
}

.rts-lang-compact-menu li {
	margin: 0;
	padding: 0;
}

.rts-lang-compact-option {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	gap: 6px;
	padding: 10px 8px;
	color: #F1E3D3;
	text-decoration: none;
	transition: background 0.15s ease, transform 0.15s ease;
	font-size: 0.85rem;
	text-align: center;
	border-radius: 10px;
	line-height: 1.1;
}

.rts-lang-compact-option .rts-lang-flag {
	font-size: 1.25rem;
}


.rts-lang-compact-option:hover {
	background: rgba(241, 227, 211, 0.12);
	border-radius: 999px;
	transform: translateY(-1px);
}


.rts-lang-compact-option:focus {
	background: rgba(241, 227, 211, 0.12);
	border-radius: 999px;
	outline: 2px solid #FCA311;
	outline-offset: 2px;
}

.rts-lang-compact-menu li[aria-selected="true"] .rts-lang-compact-option {
	background: rgba(252, 163, 17, 0.18);
	font-weight: 700;
}

@media (max-width: 520px) {
	.rts-lang-compact-menu {
		left: 0;
		right: auto;
		min-width: 0;
		width: calc(100vw - 20px);
		grid-template-columns: repeat(2, minmax(0, 1fr));
	}
}

/* Light mode for compact */
body:not(.rts-dark-mode) .rts-lang-compact-button {
	color: #2A2A2A;
	border-color: rgba(0, 0, 0, 0.15);
}

body:not(.rts-dark-mode) .rts-lang-compact-button:hover {
	background: rgba(252, 163, 17, 0.08);
	border-color: #FCA311;
}

body:not(.rts-dark-mode) .rts-lang-compact-menu {
	background: #FFFFFF;
	border-color: #DEDEDE;
	box-shadow: 0 6px 16px rgba(0, 0, 0, 0.15);
}

body:not(.rts-dark-mode) .rts-lang-compact-option {
	color: #2A2A2A;
}

body:not(.rts-dark-mode) .rts-lang-compact-option:hover,
body:not(.rts-dark-mode) .rts-lang-compact-option:focus {
	background: #F9F9F9;
}

body:not(.rts-dark-mode) .rts-lang-compact-menu li[aria-selected="true"] .rts-lang-compact-option {
	background: rgba(252, 163, 17, 0.1);
}

/* Dropdown Style */
.rts-lang-dropdown-wrapper {
	position: relative;
	display: inline-block;
}

.rts-lang-dropdown-button {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 10px 16px;
	background: #182437;
	color: #F1E3D3;
	border: 2px solid #FCA311;
	border-radius: 8px;
	cursor: pointer;
	font-size: 1rem;
	font-weight: 500;
	transition: all 0.2s ease;
}

.rts-lang-dropdown-button:hover {
	background: #1f2d45;
	border-color: #fdb847;
}

.rts-lang-dropdown-button:focus {
	outline: 2px solid #FCA311;
	outline-offset: 2px;
}

.rts-flag-emoji {
	font-size: 2em;
	padding: 5px 5px;
	line-height: 1;
	display: inline-flex;
	align-items: center;
	justify-content: center;
}

.rts-dropdown-arrow {
	margin-left: 4px;
	transition: transform 0.2s ease;
}

.rts-lang-dropdown-button[aria-expanded="true"] .rts-dropdown-arrow {
	transform: rotate(180deg);
}

.rts-lang-dropdown-menu {
	position: absolute;
	top: calc(100% + 4px);
	left: 0;
	min-width: 200px;
	background: #182437;
	border: 2px solid #FCA311;
	border-radius: 8px;
	box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
	list-style: none;
	margin: 0;
	padding: 8px 0;
	z-index: 1000;
	max-height: 400px;
	overflow-y: auto;
}

.rts-lang-dropdown-menu li {
	margin: 0;
	padding: 0;
}

.rts-lang-option {
	display: flex;
	align-items: center;
	gap: 10px;
	padding: 10px 16px;
	color: #F1E3D3;
	text-decoration: none;
	transition: background 0.15s ease;
}

.rts-lang-option:hover {
	background: #1f2d45;
}

.rts-lang-option:focus {
	background: #1f2d45;
	outline: 2px solid #FCA311;
	outline-offset: -2px;
}

.rts-lang-dropdown-menu li[aria-selected="true"] .rts-lang-option {
	background: rgba(252, 163, 17, 0.15);
	font-weight: 600;
}

/* Light mode styles for dropdown */
body:not(.rts-dark-mode) .rts-lang-dropdown-button {
	background: #FFFFFF;
	color: #2A2A2A;
	border-color: #DEDEDE;
}

body:not(.rts-dark-mode) .rts-lang-dropdown-button:hover {
	background: #F9F9F9;
	border-color: #FCA311;
}

body:not(.rts-dark-mode) .rts-lang-dropdown-menu {
	background: #FFFFFF;
	border-color: #DEDEDE;
	box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
}

body:not(.rts-dark-mode) .rts-lang-option {
	color: #2A2A2A;
}

body:not(.rts-dark-mode) .rts-lang-option:hover,
body:not(.rts-dark-mode) .rts-lang-option:focus {
	background: #F9F9F9;
}

body:not(.rts-dark-mode) .rts-lang-dropdown-menu li[aria-selected="true"] .rts-lang-option {
	background: rgba(252, 163, 17, 0.1);
}

/* Flag Grid Style */
.rts-language-switcher.rts-flags {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
	gap: 12px;
	max-width: 600px;
}

.rts-lang-flag {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 12px;
	background: #182437;
	color: #F1E3D3;
	border: 2px solid transparent;
	border-radius: 8px;
	text-decoration: none;
	transition: all 0.2s ease;
}

.rts-lang-flag:hover {
	border-color: #FCA311;
	transform: translateY(-2px);
	color: #F1E3D3;
}

.rts-lang-flag.active {
	border-color: #FCA311;
	background: rgba(252, 163, 17, 0.1);
	color: #F1E3D3;
}

/* Light mode fix - ensure dark text on light backgrounds */
body:not(.rts-dark-mode) .rts-lang-flag {
	background: #F9F9F9;
	color: #2A2A2A;
	border-color: #DEDEDE;
}

body:not(.rts-dark-mode) .rts-lang-flag:hover,
body:not(.rts-dark-mode) .rts-lang-flag.active {
	background: #FFFFFF;
	color: #2A2A2A;
	border-color: #FCA311;
}

.rts-lang-name {
	font-size: 0.95rem;
}

@media (max-width: 768px) {
	.rts-language-switcher.rts-flags {
		grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
		gap: 8px;
	}

	.rts-lang-flag {
		flex-direction: column;
		text-align: center;
		gap: 6px;
		padding: 10px;
	}

	.rts-flag-emoji {
		font-size: 1.8em;
	}
}


/* Click safety */
.rts-language-switcher, .rts-language-switcher * {
    pointer-events: auto !important;
}
.rts-lang-compact-menu, .rts-lang-dropdown-menu {
    z-index: 99999 !important;
}
');

		// Pass language to JavaScript
		wp_localize_script('rts-system', 'RTS_LANG', [
			'current' => $this->current_language,
			'dir' => $dir,
			'translations' => $this->translations,
		]);
	}

	/**
	 * Get multilingual safety keywords (for moderation)
	 */
	public static function get_safety_keywords(string $lang): array {
		$keywords = [
			'en' => [
				'abusive' => ['fuck you', 'kill yourself', 'worthless', 'selfish'],
				'supportive' => ['you are not alone', 'here for you', 'it gets better'],
			],
			'es' => [
				'abusive' => ['vete al infierno', 'mátate', 'inútil', 'egoísta'],
				'supportive' => ['no estás solo', 'estoy aquí para ti', 'mejorará'],
			],
			'fr' => [
				'abusive' => ['va te faire', 'tue-toi', 'inutile', 'égoïste'],
				'supportive' => ['vous n\'êtes pas seul', 'je suis là', 'ça ira mieux'],
			],
			// Add more languages as needed
		];

		return $keywords[$lang] ?? $keywords['en'];
	}
}

// Initialize
RTS_Multilingual::get_instance();

// Helper function for templates
if (!function_exists('rts__')) {
	function rts__(string $key, string $default = ''): string {
		return RTS_Multilingual::get_instance()->translate($key, $default);
	}
}
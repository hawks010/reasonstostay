<?php
/**
 * RTS Context-Aware Safety Scanner
 *
 * Designed specifically for suicide prevention / mental health support sites
 * where letters will naturally contain trigger words but are supportive, not harmful.
 *
 * PHILOSOPHY:
 * - "I want to kill myself" (cry for help) → ALLOW
 * - "You should kill yourself" (encouragement of harm) → BLOCK
 * - "I've thought about suicide" (sharing struggle) → ALLOW
 * - "Just do it, nobody will miss you" (abusive) → BLOCK
 */

if (!defined('ABSPATH')) { exit; }

class RTS_Context_Aware_Safety {

	/**
	 * Enhanced safety scan with sentence-level context analysis
	 *
	 * @param int $post_id Letter post ID
	 * @return array ['pass' => bool, 'flags' => array, 'details' => array]
	 */
	public static function scan(int $post_id): array {
		$content = (string) get_post_field('post_content', $post_id);
		$content = wp_strip_all_tags($content); // Remove HTML

		$flags = [];
		$details = []; // Human-readable explanations
		$severity_score = 0;

		// Split into sentences for context-aware analysis
		$sentences = self::split_into_sentences($content);

		// === CATEGORY 1: INSTANT BLOCK (Spam, Malicious, Abusive) ===
		$instant_block = self::check_instant_block($content);
		if (!empty($instant_block['flags'])) {
			$flags = array_merge($flags, $instant_block['flags']);
			$details = array_merge($details, $instant_block['details']);
			$severity_score += 100; // Auto-fail
		}

		// === CATEGORY 2: ENCOURAGEMENT OF HARM (Sentence-level) ===
		foreach ($sentences as $sentence) {
			$encouragement = self::check_encouragement($sentence);
			if (!empty($encouragement['flags'])) {
				$flags = array_merge($flags, $encouragement['flags']);
				$details[] = $encouragement['detail'] . " (Sentence: \"" . self::truncate($sentence, 60) . "\")";
				$severity_score += 50; // Very serious
			}
		}

		// === CATEGORY 3: ABUSIVE LANGUAGE TOWARD READER ===
		foreach ($sentences as $sentence) {
			$abuse = self::check_abusive_language($sentence);
			if (!empty($abuse['flags'])) {
				$flags = array_merge($flags, $abuse['flags']);
				$details[] = $abuse['detail'] . " (Sentence: \"" . self::truncate($sentence, 60) . "\")";
				$severity_score += 30;
			}
		}

		// === CATEGORY 4: IMMINENT DANGER (Specific plans + timing) ===
		$danger = self::check_imminent_danger($content);
		if (!empty($danger['flags'])) {
			$flags = array_merge($flags, $danger['flags']);
			$details = array_merge($details, $danger['details']);
			$severity_score += 20;
		}

		// === SUPPORTIVE CONTEXT DETECTION (Reduces severity) ===
		$supportive = self::check_supportive_context($content);
		if ($supportive['is_supportive']) {
			$severity_score = max(0, $severity_score - 15);
			$details[] = "✓ Supportive language detected (" . implode(', ', $supportive['markers']) . ")";
		}

		// === PAST TENSE / HISTORICAL CONTEXT (Reduces severity) ===
		if (self::is_past_tense($content)) {
			$severity_score = max(0, $severity_score - 10);
			$details[] = "✓ Past tense detected (discussing history, not current danger)";
		}

		// === DECISION THRESHOLD ===
		// Severity >= 40: Needs review
		// Severity >= 80: High priority review
		// Severity < 40: Safe to publish

		$needs_review = ($severity_score >= 40);

		return [
			'pass' => !$needs_review,
			'flags' => array_values(array_unique($flags)),
			'score' => $severity_score,
			'details' => $details, // NEW: Clear explanations for admins
		];
	}

	/**
	 * Split content into sentences for context-aware analysis
	 */
	private static function split_into_sentences(string $content): array {
		// Split on period, exclamation, question mark followed by space or newline
		$sentences = preg_split('/[.!?]+\s+/u', $content);
		return is_array($sentences) ? array_filter($sentences) : [];
	}

	/**
	 * CATEGORY 1: Instant block (spam, malicious code, obvious abuse)
	 */
	private static function check_instant_block(string $content): array {
		$content_lc = mb_strtolower($content);
		$flags = [];
		$details = [];

		$patterns = [
			'/\b(viagra|cialis|casino|poker|lottery|cheap\s+pills?)\b/i' => [
				'flag' => 'spam_keywords',
				'detail' => '🚫 Spam keywords detected (pharmaceutical/gambling)',
			],
			'/https?:\/\/[^\s]+\.(ru|cn|tk|ml|ga|gq)\b/i' => [
				'flag' => 'suspicious_links',
				'detail' => '🚫 Suspicious domain link (high-risk TLD)',
			],
			'/<script|javascript:|onclick=|onerror=/i' => [
				'flag' => 'malicious_code',
				'detail' => '🚫 Malicious code detected (XSS attempt)',
			],
		];

		foreach ($patterns as $pattern => $info) {
			if (@preg_match($pattern, $content_lc) === 1) {
				$flags[] = $info['flag'];
				$details[] = $info['detail'];
			}
		}

		return compact('flags', 'details');
	}

	/**
	 * CATEGORY 2: Encouragement of harm (sentence-level context)
	 *
	 * CRITICAL: Must differentiate between:
	 * - "I want to kill myself" → SAFE (first-person, cry for help)
	 * - "You should kill yourself" → BLOCK (second-person, encouragement)
	 */
	private static function check_encouragement(string $sentence): array {
		$sentence_lc = mb_strtolower($sentence);
		$flags = [];
		$detail = '';

		// Pattern: Second-person + harmful directive
		$harmful_patterns = [
			'/\b(you should|you need to|you have to|you must|just do it|go ahead and)\b.{0,50}\b(kill|die|end it|harm yourself)\b/i' =>
				'⚠️ Encouragement of self-harm detected',

			'/\b(nobody|no one)\b.{0,30}\b(will miss|cares about|loves)\b.{0,20}\byou\b/i' =>
				'⚠️ Harmful language: diminishing value of reader',

			'/\byou\b.{0,20}\b(better off dead|worthless|piece of (shit|trash))\b/i' =>
				'⚠️ Abusive/demeaning language toward reader',

			'/\b(world|everyone)\b.{0,30}\b(better (off )?without you|happier if you (were )?gone)\b/i' =>
				'⚠️ Encouragement that world is better without reader',
		];

		foreach ($harmful_patterns as $pattern => $message) {
			if (@preg_match($pattern, $sentence_lc) === 1) {
				$flags[] = 'encouragement_of_harm';
				$detail = $message;
				break; // One flag per sentence is enough
			}
		}

		return compact('flags', 'detail');
	}

	/**
	 * CATEGORY 3: Abusive language (non-harm but offensive)
	 */
	private static function check_abusive_language(string $sentence): array {
		$sentence_lc = mb_strtolower($sentence);
		$flags = [];
		$detail = '';

		$patterns = [
			'/\b(fuck you|fuck off|screw you|get lost)\b/i' =>
				'⚠️ Profanity directed at reader',

			'/\byou\'?re\b.{0,20}\b(selfish|stupid|pathetic|weak|coward)\b/i' =>
				'⚠️ Abusive name-calling',
		];

		foreach ($patterns as $pattern => $message) {
			if (@preg_match($pattern, $sentence_lc) === 1) {
				$flags[] = 'abusive_language';
				$detail = $message;
				break;
			}
		}

		return compact('flags', 'detail');
	}

	/**
	 * CATEGORY 4: Imminent danger (specific method + timing)
	 *
	 * Only flags if BOTH method AND timing are present
	 * (e.g., "I'm going to overdose tonight" vs "I've thought about pills")
	 */
	private static function check_imminent_danger(string $content): array {
		$content_lc = mb_strtolower($content);
		$flags = [];
		$details = [];

		// Specific methods
		$has_method = preg_match('/\b(overdose|pills? and alcohol|rope|noose|hanging|jump(ing)? (off|from)|gun|blade)\b/i', $content_lc);

		// Imminent timing
		$has_timing = preg_match('/\b(tonight|today|right now|in (a|an) hour|going to do it|about to)\b/i', $content_lc);

		if ($has_method && $has_timing) {
			$flags[] = 'imminent_danger';
			$details[] = '⚠️ Imminent danger: Specific method + immediate timing detected';
		} elseif ($has_method) {
			// Method alone is not imminent (could be past reflection)
			$details[] = 'ℹ️ Method mention detected (no immediate timing)';
		}

		return compact('flags', 'details');
	}

	/**
	 * SUPPORTIVE CONTEXT: Reduces severity if letter is clearly supportive
	 */
	private static function check_supportive_context(string $content): array {
		$content_lc = mb_strtolower($content);
		$markers = [];

		$supportive_phrases = [
			'/\byou are not alone\b/i' => 'not alone',
			'/\b(here for you|i\'m here)\b/i' => 'presence',
			'/\bit gets better\b/i' => 'hope',
			'/\bplease (stay|don\'t|hold on)\b/i' => 'plea',
			'/\b(helpline|crisis line|support|therapy|counseling)\b/i' => 'resources',
			'/\b(i understand|me too|i\'ve been there)\b/i' => 'empathy',
			'/\byou matter\b/i' => 'affirmation',
			'/\b(tomorrow|future|next (week|month|year))\b/i' => 'future-oriented',
		];

		foreach ($supportive_phrases as $pattern => $label) {
			if (@preg_match($pattern, $content_lc) === 1) {
				$markers[] = $label;
			}
		}

		return [
			'is_supportive' => !empty($markers),
			'markers' => $markers,
		];
	}

	/**
	 * PAST TENSE: Detect if discussing past events vs current danger
	 */
	private static function is_past_tense(string $content): bool {
		$content_lc = mb_strtolower($content);

		return (bool) preg_match('/\b(used to|in the past|when i was|back then|years ago|months ago|last (year|month)|previously)\b/i', $content_lc);
	}

	/**
	 * Helper: Truncate string for display
	 */
	private static function truncate(string $str, int $length): string {
		$str = trim($str);
		if (mb_strlen($str) <= $length) return $str;
		return mb_substr($str, 0, $length) . '...';
	}
}

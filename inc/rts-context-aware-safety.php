<?php
/**
 * RTS Context-Aware Safety Scanner - Enhanced Version
 *
 * Version: 2.1.0 - Improved Accuracy & Context Awareness
 * Date: 2026-02-04
 *
 * ENHANCEMENTS:
 * - Added positive vs negative context weighting
 * - Improved first-person detection with boundary markers
 * - Reduced false positives for supportive letters
 * - Enhanced pattern specificity
 */

if (!defined('ABSPATH')) { exit; }

if (!class_exists('RTS_Context_Aware_Safety')) {

class RTS_Context_Aware_Safety {

	/**
	 * Enhanced safety scan with improved accuracy
	 */
	public static function scan(int $post_id): array {
		$content = (string) get_post_field('post_content', $post_id);
		$content = wp_strip_all_tags($content);

		// Normalize whitespace
		$content = preg_replace('/\s+/', ' ', (string) $content);

		if (defined('WP_DEBUG') && WP_DEBUG) {
			error_log('RTS Enhanced Scan: Starting scan for post ' . $post_id);
		}

		$details = [];
		$severity_score = 0;

		// === PHASE 1: CONTEXT ANALYSIS ===
		$context_score = self::analyze_context($content);
		$details[] = 'Context analysis: ' . ($context_score['summary'] ?? '');

		// === PHASE 2: SENTENCE-LEVEL ANALYSIS ===
		$sentences = self::split_into_sentences($content);
		$sentence_flags = [];

		foreach ($sentences as $index => $sentence) {
			$sentence_analysis = self::analyze_sentence($sentence, (int) $index, $sentences);
			if (!empty($sentence_analysis['flags'])) {
				foreach ($sentence_analysis['flags'] as $flag) {
					$sentence_flags[] = $flag;
				}
				if (!empty($sentence_analysis['detail'])) {
					$details[] = $sentence_analysis['detail'] . ' [Sentence ' . ((int) $index + 1) . ']';
				}
			}
		}

		// === PHASE 3: INSTANT BLOCK CHECKS ===
		$instant_block = self::check_instant_block($content);
		if (!empty($instant_block['flags'])) {
			$sentence_flags = array_merge($sentence_flags, $instant_block['flags']);
			$details = array_merge($details, $instant_block['details'] ?? []);
		}

		// === PHASE 4: IMMINENT DANGER ===
		$danger = self::check_imminent_danger($content);
		if (!empty($danger['flags'])) {
			$sentence_flags = array_merge($sentence_flags, $danger['flags']);
			$details = array_merge($details, $danger['details'] ?? []);
		}

		// Remove duplicates and calculate scores
		$flags = array_values(array_unique($sentence_flags));

		foreach ($flags as $flag) {
			$weight = self::get_context_adjusted_weight((string) $flag, $context_score);
			$severity_score += (int) $weight;
		}

		// === CONTEXT-BASED ADJUSTMENT ===
		if (!empty($context_score['is_supportive'])) {
			$severity_score = max(0, (int) $severity_score - 25);
			$details[] = '✓ Strong supportive context reduces severity';
		}

		if (!empty($context_score['is_first_person'])) {
			$severity_score = max(0, (int) $severity_score - 15);
			$details[] = '✓ First-person perspective (cry for help) reduces severity';
		}

		// === DECISION ===
		$threshold = self::calculate_dynamic_threshold($context_score);
		$needs_review = ((int) $severity_score >= (int) $threshold);

		if (defined('WP_DEBUG') && WP_DEBUG) {
			error_log('RTS Enhanced Scan Result: score=' . $severity_score . ', threshold=' . $threshold . ', flags=' . implode(',', $flags));
		}

		return [
			'pass' => !$needs_review,
			'flags' => $flags,
			'score' => (int) $severity_score,
			'details' => $details,
			'threshold_used' => (int) $threshold,
			'context_summary' => (string) ($context_score['summary'] ?? ''),
		];
	}

	/**
	 * Analyze overall context of the letter
	 */
	private static function analyze_context(string $content): array {
		$content_lc = mb_strtolower($content);
		$word_count = str_word_count($content);

		$positive_indicators = [
			'/(you (are|re) (not alone|strong|brave|worthy|loved))/i',
			'/(i (understand|hear|see) you)/i',
			'/(please (stay|hold on|reach out|call))/i',
			'/(things (can|will) get better)/i',
			'/(you matter|your life matters)/i',
			'/((crisis|support) (line|hotline|text))/i',
			'/((therapy|therapist|counseling|support group))/i',
			'/(i (care|am here) (for you|to listen))/i',
			'/(you have (strength|hope|future))/i',
			'/((thank you|thanks) (for|to) (sharing|being here))/i',
		];

		$negative_indicators = [
			'/(i (hate|despise) (myself|my life))/i',
			'/(i want to (die|end it|be gone))/i',
			'/((cant|can\'t) (take|handle) (this|it) (anymore|any more))/i',
			'/((worthless|useless|failure|burden|disappointment))/i',
			'/((alone|lonely|isolated|empty) (all the time|always))/i',
		];

		$positive_count = 0;
		$negative_count = 0;

		foreach ($positive_indicators as $pattern) {
			if (preg_match_all($pattern, $content_lc, $matches)) {
				$positive_count += count($matches[0]);
			}
		}

		foreach ($negative_indicators as $pattern) {
			if (preg_match_all($pattern, $content_lc, $matches)) {
				$negative_count += count($matches[0]);
			}
		}

		// Determine if first-person perspective (both I/me and you/your present somewhere)
		$is_first_person = (bool) (preg_match('/\b(i|me|my|mine|myself)\b/i', $content_lc) && preg_match('/\b(you|your|yours|yourself)\b/i', $content_lc));

		// Supportive language patterns
		$is_supportive = ($positive_count > 0) || (bool) preg_match('/\b(here for you|i\'m here|not alone|you matter)\b/i', $content_lc);

		$is_past_tense = (bool) preg_match('/\b((used to|when i was|in the past|back then) (feel|thought|struggled|wanted))\b/i', $content_lc);
		$has_future_references = (bool) preg_match('/\b((tomorrow|next (week|month|year)|future|going to try|plan to))\b/i', $content_lc);

		$summary = 'Positive indicators: ' . $positive_count . ', Negative indicators: ' . $negative_count . ', ';
		$summary .= $is_first_person ? 'First-person, ' : 'Not first-person, ';
		$summary .= $is_supportive ? 'Supportive, ' : '';
		$summary .= $is_past_tense ? 'Past tense, ' : '';
		$summary .= $has_future_references ? 'Future-oriented' : '';

		return [
			'positive_count' => (int) $positive_count,
			'negative_count' => (int) $negative_count,
			'is_first_person' => (bool) $is_first_person,
			'is_supportive' => (bool) $is_supportive,
			'is_past_tense' => (bool) $is_past_tense,
			'has_future_references' => (bool) $has_future_references,
			'summary' => (string) $summary,
			'word_count' => (int) $word_count,
		];
	}

	/**
	 * Analyze individual sentence with context awareness
	 */
	private static function analyze_sentence(string $sentence, int $index, array $all_sentences): array {
		$sentence_lc = mb_strtolower($sentence);
		$flags = [];
		$detail = '';

		$is_first_person = self::is_first_person_statement($sentence_lc);

		// ENCOURAGEMENT CHECK (only for non-first-person)
		if (!$is_first_person) {
			$encouragement_check = self::check_encouragement_enhanced($sentence_lc, $all_sentences, $index);
			if (!empty($encouragement_check['flags'])) {
				$flags = array_merge($flags, $encouragement_check['flags']);
				$detail = (string) ($encouragement_check['detail'] ?? '');
			}
		}

		// ABUSIVE LANGUAGE CHECK
		$abuse_check = self::check_abusive_language_enhanced($sentence_lc, $is_first_person);
		if (!empty($abuse_check['flags'])) {
			$flags = array_merge($flags, $abuse_check['flags']);
			if (empty($detail)) {
				$detail = (string) ($abuse_check['detail'] ?? '');
			}
		}

		// Concerning first-person content (informational only)
		if (empty($flags) && $is_first_person && self::has_concerning_content($sentence_lc)) {
			$detail = 'ℹ️ First-person concerning content (not flagged): "' . self::truncate($sentence, 80) . '"';
		}

		return [
			'flags' => $flags,
			'detail' => $detail,
			'is_first_person' => $is_first_person,
		];
	}

	/**
	 * Improved first-person detection with boundary markers
	 */
	private static function is_first_person_statement(string $sentence_lc): bool {
		$first_person_pronouns = ['i', 'me', 'my', 'mine', 'myself'];
		$starts_with_first_person = (bool) preg_match('/^(i\s+|me\s+|my\s+)/', $sentence_lc);
		$concerning_words = ['kill', 'die', 'suicide', 'harm', 'end', 'overdose', 'cut', 'hurt'];

		foreach ($first_person_pronouns as $pronoun) {
			foreach ($concerning_words as $word) {
				$pattern = '/\b' . preg_quote($pronoun, '/') . '\b.{0,15}\b' . preg_quote($word, '/') . '\b/i';
				if (preg_match($pattern, $sentence_lc)) {
					return true;
				}
			}
		}

		return $starts_with_first_person;
	}

	/**
	 * Enhanced encouragement check with better context
	 */
	private static function check_encouragement_enhanced(string $sentence_lc, array $all_sentences, int $current_index): array {
		$flags = [];
		$detail = '';

		$prev_sentence = $current_index > 0 ? mb_strtolower((string) $all_sentences[$current_index - 1]) : '';
		$next_sentence = $current_index < (count($all_sentences) - 1) ? mb_strtolower((string) $all_sentences[$current_index + 1]) : '';

		$direct_encouragement = [
			'/^((you should|you need to|you must|just) (kill yourself|end your life|commit suicide|harm yourself))/i',
			'/((go ahead|do it|just do it) (and|and then)? (kill|end|harm))/i',
			'/((nobody|no one) (will miss|cares about|likes|loves|needs) you)/i',
			'/((the world|everyone|people) (would be|are) better (off )?without you)/i',
		];

		foreach ($direct_encouragement as $pattern) {
			if (preg_match($pattern, $sentence_lc)) {
				$is_in_supportive_context = (bool) (preg_match('/(don\'t|please don\'t|shouldn\'t)/i', $prev_sentence) || preg_match('/(i hope you|i want you to|stay)/i', $next_sentence));
				if (!$is_in_supportive_context) {
					$flags[] = 'encouragement_of_harm';
					$detail = '⚠️ Direct encouragement of self-harm detected';
					break;
				}
			}
		}

		if (empty($flags)) {
			$dehumanizing_patterns = [
				'/^you\'re (nothing|worthless|useless|a (waste|failure|disappointment))/i',
				'/^you are (a (piece of (shit|trash)|burden|loser))/i',
			];

			foreach ($dehumanizing_patterns as $pattern) {
				if (preg_match($pattern, $sentence_lc)) {
					$flags[] = 'encouragement_of_harm';
					$detail = '⚠️ Dehumanizing language toward reader';
					break;
				}
			}
		}

		return compact('flags', 'detail');
	}

	/**
	 * Enhanced abusive language check
	 */
	private static function check_abusive_language_enhanced(string $sentence_lc, bool $is_first_person): array {
		$flags = [];
		$detail = '';

		// Skip first-person abusive language (self-directed)
		if ($is_first_person) {
			return compact('flags', 'detail');
		}

		$direct_insults = [
			'/(fuck you|fuck off|screw you|go to hell|get lost)/i',
		];

		foreach ($direct_insults as $pattern) {
			if (preg_match($pattern, $sentence_lc)) {
				$flags[] = 'abusive_language';
				$detail = '⚠️ Profanity directed at reader';
				break;
			}
		}

		if (empty($flags)) {
			$insult_patterns = [
				'/^you are (so|really)? (stupid|idiot|moron|pathetic|weak|coward)/i',
				'/^you\'re (such a|such an)? (failure|disappointment|embarrassment)/i',
			];

			foreach ($insult_patterns as $pattern) {
				if (preg_match($pattern, $sentence_lc)) {
					$flags[] = 'abusive_language';
					$detail = '⚠️ Abusive name-calling directed at reader';
					break;
				}
			}
		}

		return compact('flags', 'detail');
	}

	/**
	 * Check for concerning content without flagging
	 */
	private static function has_concerning_content(string $sentence_lc): bool {
		$concerning_patterns = [
			'/(i (feel|wish) (like )?(dying|ending it|killing myself))/i',
			'/(i want to (die|end (it|my life)|kill myself))/i',
			'/((thinking|thoughts) of (suicide|killing myself))/i',
			'/(i don\'t want to (live|be here) anymore)/i',
		];

		foreach ($concerning_patterns as $pattern) {
			if (preg_match($pattern, $sentence_lc)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get context-adjusted weight for flags
	 */
	private static function get_context_adjusted_weight(string $flag, array $context_score): int {
		$base_weights = [
			'spam_keywords' => 100,
			'suspicious_links' => 100,
			'malicious_code' => 100,
			'encouragement_of_harm' => 50,
			'abusive_language' => 30,
			'imminent_danger' => 20,
		];

		$base_weight = (int) ($base_weights[$flag] ?? 20);

		if (!empty($context_score['is_supportive']) && in_array($flag, ['abusive_language', 'encouragement_of_harm'], true)) {
			$base_weight = (int) max(10, $base_weight * 0.5);
		}

		if (!empty($context_score['is_first_person']) && in_array($flag, ['abusive_language', 'encouragement_of_harm'], true)) {
			$base_weight = (int) max(5, $base_weight * 0.3);
		}

		return (int) $base_weight;
	}

	/**
	 * Calculate dynamic threshold based on context
	 */
	private static function calculate_dynamic_threshold(array $context_score): int {
		$base_threshold = 40;

		if (!empty($context_score['is_supportive'])) {
			$base_threshold += 10;
		}

		if (!empty($context_score['is_first_person'])) {
			$base_threshold += 15;
		}

		if (!empty($context_score['negative_count']) && (int) $context_score['negative_count'] > 2 && empty($context_score['is_first_person'])) {
			$base_threshold -= 5;
		}

		return (int) max(20, $base_threshold);
	}

	/**
	 * Split into sentences (improved version)
	 */
	private static function split_into_sentences(string $content): array {
		$sentences = preg_split('/(?<=[.!?])\s+(?=[A-Z])/', $content, -1, PREG_SPLIT_NO_EMPTY);
		if (!is_array($sentences)) return [];
		$sentences = array_map('trim', $sentences);
		$sentences = array_filter($sentences, static function($s){ return $s !== ''; });
		return array_values($sentences);
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
				'detail' => '🚫 Spam keywords detected',
			],
			'/https?:\/\/[^\s]+\.(ru|cn|tk|ml|ga|gq)\b/i' => [
				'flag' => 'suspicious_links',
				'detail' => '🚫 Suspicious domain link',
			],
			'/<script|javascript:|onclick=|onerror=/i' => [
				'flag' => 'malicious_code',
				'detail' => '🚫 Malicious code detected',
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
	 * CATEGORY 4: Imminent danger (specific method + timing)
	 */
	private static function check_imminent_danger(string $content): array {
		$content_lc = mb_strtolower($content);
		$flags = [];
		$details = [];

		$method_patterns = [
			'/\b(overdose|OD)\s+(on|with)\s+(pills|medication)/i',
			'/\b(take|taking|swallow).{0,20}\b(all|entire|whole).{0,20}\b(pills|tablets|medication)\b/i',
			'/\b(hang|hanging).{0,20}\b(myself|myself from|from (a|the))\b/i',
			'/\b(jump(ing)?|leap).{0,20}\b(off|from).{0,20}\b(building|bridge|cliff)\b/i',
			'/\b(use a|with a) (gun|pistol|rifle|firearm)\b/i',
		];

		$has_method = false;
		foreach ($method_patterns as $pattern) {
			if (@preg_match($pattern, $content_lc) === 1) {
				$has_method = true;
				break;
			}
		}

		$has_timing = (bool) preg_match('/\b(tonight|today|right now|in (a|an) hour|going to do it now)\b/i', $content_lc);

		if ($has_method && $has_timing) {
			$flags[] = 'imminent_danger';
			$details[] = '⚠️ Imminent danger: Specific method + immediate timing detected';
		} elseif ($has_method) {
			$details[] = 'ℹ️ Method mention detected (no immediate timing)';
		}

		return compact('flags', 'details');
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

} // class_exists

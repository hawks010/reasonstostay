<?php
/**
 * RTS Workflow Badges
 * Presentation-only helper for the Letters admin UI.
 *
 * Primary badge is driven by `_rts_workflow_stage`.
 * Secondary indicators: manual lock, snapshot present, learned.
 *
 * @package ReasonsToStay
 */

if (!defined('ABSPATH')) exit;

if (!function_exists('rts_get_workflow_badge_config')) {
    function rts_get_workflow_badge_config(): array {
        return [
            'ingested' => [
                'label' => 'New',
                'color' => 'blue',
                'icon'  => 'dashicons-plus-alt',
                'description' => 'Letter received, awaiting processing',
            ],
            'pending_review' => [
                'label' => 'Pending Review',
                'color' => 'orange',
                'icon'  => 'dashicons-clock',
                'description' => 'Ready for human review',
            ],
            'flagged_draft' => [
                'label' => 'Flagged',
                'color' => 'red',
                'icon'  => 'dashicons-flag',
                'description' => 'Held for moderation review',
            ],
            'approved_published' => [
				'label' => 'Workflow Published',
                'color' => 'green',
                'icon'  => 'dashicons-yes-alt',
                'description' => 'Live on site',
            ],
            'skipped_published' => [
                'label' => 'Completed',
                'color' => 'gray',
                'icon'  => 'dashicons-saved',
                'description' => 'Previously published before workflow system',
            ],
        ];
    }
}

if (!function_exists('rts_render_workflow_badge')) {
    /**
     * Render the workflow badge HTML for a given letter.
     */
    function rts_render_workflow_badge(int $post_id): string {
        $stage  = (string) get_post_meta($post_id, '_rts_workflow_stage', true);
        $badges = rts_get_workflow_badge_config();

        if (!$stage || !isset($badges[$stage])) {
            $label = $stage ? 'Unknown' : 'Not set';
            $desc  = $stage ? 'Workflow stage is not recognised' : 'Workflow stage not stamped yet';
            return sprintf(
                '<span class="rts-badge rts-badge-gray" title="%s"><span class="dashicons dashicons-warning"></span> %s</span>',
                esc_attr($desc),
                esc_html($label)
            );
        }

        $badge = $badges[$stage];

        $html  = '<span class="rts-badge rts-badge-' . esc_attr($badge['color']) . '" title="' . esc_attr($badge['description']) . '">';
        if (!empty($badge['icon'])) {
            $html .= '<span class="dashicons ' . esc_attr($badge['icon']) . '"></span> ';
        }
        $html .= esc_html($badge['label']);
        $html .= '</span>';

        // Secondary indicators (icons)
        if (get_post_meta($post_id, '_rts_manual_lock', true)) {
            $html .= ' <span class="rts-icon rts-icon-lock" title="Manually edited - AI will not overwrite"><span class="dashicons dashicons-lock"></span></span>';
        }

        if (get_post_meta($post_id, '_rts_bot_snapshot', true)) {
            $html .= ' <span class="rts-icon rts-icon-snapshot" title="AI snapshot saved for learning"><span class="dashicons dashicons-camera"></span></span>';
        }

        if (get_post_meta($post_id, '_rts_workflow_learned_at', true)) {
            $html .= ' <span class="rts-icon rts-icon-learned" title="System learned from human edits"><span class="dashicons dashicons-lightbulb"></span></span>';
        }

        return $html;
    }
}

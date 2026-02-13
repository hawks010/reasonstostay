<?php
/**
 * RTS Workflow Badges
 * Presentation-only helper for the Letters admin UI.
 *
 * Authoritative stage is driven by `rts_workflow_stage`.
 */

if (!defined('ABSPATH')) exit;

if (!function_exists('rts_get_workflow_badge_config')) {
    function rts_get_workflow_badge_config(): array {
        return [
            'unprocessed' => [
                'label' => 'Unprocessed',
                'color' => 'blue',
                'icon'  => 'dashicons-plus-alt',
                'description' => 'Imported/submitted and ready for scanning.',
            ],
            'processing' => [
                'label' => 'Processing',
                'color' => 'orange',
                'icon'  => 'dashicons-update',
                'description' => 'Currently being scanned/processed (locked).',
            ],
            'pending_review' => [
                'label' => 'Pending Review',
                'color' => 'purple',
                'icon'  => 'dashicons-yes-alt',
                'description' => 'Processed clean and awaiting human review. Never auto-reprocessed.',
            ],
            'quarantined' => [
                'label' => 'Quarantined',
                'color' => 'red',
                'icon'  => 'dashicons-warning',
                'description' => 'Flagged unsafe or errored. Only manual recheck may reprocess.',
            ],
            'published' => [
                'label' => 'Published',
                'color' => 'green',
                'icon'  => 'dashicons-visibility',
                'description' => 'Approved and visible on the front end.',
            ],
            'archived' => [
                'label' => 'Archived',
                'color' => 'gray',
                'icon'  => 'dashicons-archive',
                'description' => 'Retired content. Not eligible for processing.',
            ],
        ];
    }
}

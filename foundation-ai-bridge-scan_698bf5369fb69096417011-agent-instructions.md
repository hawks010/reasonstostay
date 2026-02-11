# AI Coding Agent Instructions for Foundation AI Bridge

## Project Overview
**Foundation AI Bridge** is a WordPress plugin that generates optimized, context-aware codebases for AI analysis and continued development.
Current version: **6.6.1**.

Scan target: **Hello-Elementor-Child-RTS-v5.2.27**.

## Key Technologies
- PHP 7.4+
- WordPress (Admin UI, AJAX, REST)
- JSON/Markdown output formats
- Secure capability + nonce patterns

## Architecture Overview
### Entry Points
- Identify the main bootstrap file and initialization hooks in the scan output.

### Structure Snapshot
- Scanned files: 50
- Detected classes: 44
- Detected hooks: 50

### Public AJAX Endpoints
- `rts_import_progress` (found in subscribers/includes/class-csv-importer.php)
- `rts_cancel_import` (found in subscribers/includes/class-csv-importer.php)
- `rts_newsletter_test_send` (found in subscribers/includes/class-newsletter-cpt.php)
- `rts_newsletter_send_all` (found in subscribers/includes/class-newsletter-cpt.php)
- `rts_newsletter_progress` (found in subscribers/includes/class-newsletter-cpt.php)
- `rts_insert_random_letter` (found in subscribers/includes/class-newsletter-cpt.php)
- `rts_test_smtp` (found in subscribers/includes/class-smtp-settings.php)
- `rts_handle_subscription` (found in subscribers/class-rts-subscriber-system.php)
- `nopriv_rts_handle_subscription` (found in subscribers/class-rts-subscriber-system.php)
- `rts_track_share` (found in inc/rts-moderation-engine.php)
- `nopriv_rts_track_share` (found in inc/rts-moderation-engine.php)
- `rts_record_feedback_ajax` (found in inc/rts-moderation-learning.php)
- `rts_random_letter` (found in inc/rts-rest-api.php)
- `nopriv_rts_random_letter` (found in inc/rts-rest-api.php)
- `nopriv_rts_submit_letter` (found in inc/shortcodes.php)
- `rts_submit_letter` (found in inc/shortcodes.php)

## Data Storage Map
This section lists persistent storage keys and where they are read/written.


Schema/defaults: not inferred by static scan unless explicitly declared near the option key (best practice: document defaults in a constants/config class).

## Permission and Security Rules
This section lists detected nonce and capability checks for admin actions/endpoints.

## Output Contract (AICB Generator)
When interacting with AICB generation endpoints, assume the following stable payload contract.

aicb_generate_context success payload (wp_send_json_success):
- success: true
- data.scan_id: string
- data.summary: object (file_count, total_size_raw, total_size, class_count, function_count, hook_count, est_tokens, complexity_score)
- data.context_format: 'json'|'md'
- data.context_truncated: bool
- data.context: string (valid JSON text only when not truncated)
- data.context_preview: string (present when truncated)

aicb_generate_context error payload (wp_send_json_error):
- success: false
- data.message: string
- data.code: string|int (optional)

## Scan Completeness Signals
A scan is considered *complete* when required sections are present and internally consistent.

Required sections: `summary`, `structure`, `index`
Missing required sections: none

Scan modes may omit or stub file contents for performance. Structural/index data should still be present for a complete scan.

## Critical Patterns & Conventions
1. Preserve capability checks and nonces for any admin actions.
2. Prefer additive changes that match existing structure and naming conventions.
3. Avoid breaking backwards compatibility (especially option keys, hooks, and public endpoints).
4. If the filesystem is restricted, ensure file-write features degrade gracefully.

## Development Workflows
### When adding new features
Follow the plugin’s existing architectural pattern (traits/classes) and keep:
- Security first (caps + nonce)
- Performance in mind (avoid scanning huge files raw when possible)
- Predictable output (stable keys + predictable sections)

### How to navigate this codebase
Start from entry points, then locate:
- Hook registrations (admin menu, ajax, rest)
- Storage layers (options, files, caches)
- Output formatting + download/export logic

## Interpretation Guide
Use this quick map to understand how the scan output is organised:
- Structure: what exists (files, classes, functions).
- Index: where functionality is registered (hooks, AJAX, REST, shortcodes).
- Behaviour Index: patterns that may affect runtime behaviour, security, or performance.

## Key Hooks Detected
- `admin_post_rts_toggle_pause_sending`
- `admin_menu`
- `admin_init`
- `admin_notices`
- `admin_post_rts_save_template`
- `admin_post_rts_test_template`
- `admin_post_rts_add_subscriber`
- `admin_enqueue_scripts`
- `after_switch_theme`
- `init`
- `rts_cron_health_check`
- `admin_post_rts_send_reconsent`
- `rts_send_reconsent_batch`
- `wp_ajax_rts_handle_subscription`
- `wp_ajax_nopriv_rts_handle_subscription`
- `rts_automated_drip`
- `updated_post_meta`
- `added_post_meta`
- `wp_enqueue_scripts`
- `cron_schedules`
- `publish_letter`
- `site_status_tests`
- `save_post_rts_subscriber`
- `delete_post`
- `admin_post_rts_import_csv`
- `admin_post_rts_export_csv`
- `wp_ajax_rts_import_progress`
- `wp_ajax_rts_cancel_import`
- `rts_process_import_chunk`
- `rts_cleanup_import_sessions`
- (+20 more)


## Important Notes for AI Agents
1. Do not invent endpoints, hooks, or option keys. Use what exists in the scan output.
2. Prefer small, reversible commits. Avoid sweeping refactors unless explicitly requested.
3. If a file appears truncated, request chunk summaries or use skeletonized output.
4. Always consider WordPress hosting constraints (file permissions, object caching, memory limits).

## File Index (Top 40)
- `subscribers/includes/class-analytics.php`
- `subscribers/includes/class-csv-importer.php`
- `subscribers/includes/class-email-engine.php`
- `subscribers/includes/class-email-queue.php`
- `subscribers/includes/class-email-renderer.php`
- `subscribers/includes/class-email-templates.php`
- `subscribers/includes/class-newsletter-cpt.php`
- `subscribers/includes/class-smtp-settings.php`
- `subscribers/includes/class-subscriber-cpt.php`
- `subscribers/includes/class-subscription-form.php`
- `subscribers/includes/class-unsubscribe.php`
- `subscribers/admin/class-admin-menu.php`
- `subscribers/admin/class-subscriber-list.php`
- `subscribers/class-database-installer.php`
- `subscribers/class-rts-subscriber-system.php`
- `inc/admin-preview.php`
- `inc/ally-widget.php`
- `inc/cpt-letters-complete.php`
- `inc/feedback-system.php`
- `inc/rts-admin-manual.php`
- `inc/rts-bulk-jobs.php`
- `inc/rts-content-refiner.php`
- `inc/rts-context-aware-safety.php`
- `inc/rts-embed-shortcodes.php`
- `inc/rts-google-translate.php`
- `inc/rts-learning-dashboard.php`
- `inc/rts-learning-engine.php`
- `inc/rts-moderation-engine.php`
- `inc/rts-moderation-learning.php`
- `inc/rts-multilingual.php`
- `inc/rts-quick-exit.php`
- `inc/rts-rest-api.php`
- `inc/rts-streaming-importer.php`
- `inc/rts-workflow-admin.php`
- `inc/rts-workflow-badges.php`
- `inc/rts-workflow.php`
- `inc/rts-zombie-queue-hard-reset.php`
- `inc/security.php`
- `inc/shortcodes.php`
- `assets/js/rts-dashboard.js`
- (+10 more)

## Scan Completeness Signals
A scan is considered *complete* when the following high-level sections are present (even if some are empty due to scan mode limits):
- `summary` (target label, mode, timestamps where available)
- `structure` (classes/functions/hooks indices)
- `index` (semantic index: ajax/rest/shortcodes/options/settings/assets/etc.)
- `behaviour_index` (runtime registries and risk flags where available)
- `integrations` (detected third-party integrations, if any)
- `files` (file index; full contents may be omitted or chunked in lighter modes)

### Mode Expectations
- Minimal/Fast modes may omit full file contents and deep behaviour parsing, but must still provide `structure`, `index`, and a file list.
- Balanced modes should include behaviour parsing and key registries (AJAX/REST/cron/shortcodes/admin).
- Deep/Max modes should include as much file content as allowed (or chunked), plus comprehensive indices.

## Data Storage Map (How state persists)
Use this to avoid breaking existing installs. Identify *exact* option keys and their schemas.

### Detected option usage (best-effort)

### Settings registration (schema hints)
- (No `register_setting()` usage detected. If this is a theme, settings may live in theme mods or customizer.)

### What AI must infer from code (do not guess)
For each persistent key, confirm:
- Schema shape (array/object/string)
- Default values and merge strategy
- Autoload flag (yes/no)
- Migration version gates (if any)

## Permission & Security Rules
AI must preserve security checks. For any admin-facing action, identify the capability and nonce rules directly from code.

### Required checks to locate
- Capability checks: `current_user_can(...)` (map capability per action)
- Nonce checks: `check_ajax_referer(...)` / `wp_verify_nonce(...)` (record action + field name)
- Admin-only endpoints: `wp_ajax_...` (and whether a `wp_ajax_nopriv_...` exists)

### Detected AJAX actions (entry points)
- `rts_import_progress` (in `subscribers/includes/class-csv-importer.php`)
- `rts_cancel_import` (in `subscribers/includes/class-csv-importer.php`)
- `rts_newsletter_test_send` (in `subscribers/includes/class-newsletter-cpt.php`)
- `rts_newsletter_send_all` (in `subscribers/includes/class-newsletter-cpt.php`)
- `rts_newsletter_progress` (in `subscribers/includes/class-newsletter-cpt.php`)
- `rts_insert_random_letter` (in `subscribers/includes/class-newsletter-cpt.php`)
- `rts_test_smtp` (in `subscribers/includes/class-smtp-settings.php`)
- `rts_handle_subscription` (in `subscribers/class-rts-subscriber-system.php`)
- `nopriv_rts_handle_subscription` (in `subscribers/class-rts-subscriber-system.php`)
- `rts_track_share` (in `inc/rts-moderation-engine.php`)
- `nopriv_rts_track_share` (in `inc/rts-moderation-engine.php`)
- `rts_record_feedback_ajax` (in `inc/rts-moderation-learning.php`)
- `rts_random_letter` (in `inc/rts-rest-api.php`)
- `nopriv_rts_random_letter` (in `inc/rts-rest-api.php`)
- `nopriv_rts_submit_letter` (in `inc/shortcodes.php`)
- `rts_submit_letter` (in `inc/shortcodes.php`)

### Admin-only determination
If an action is only registered via `wp_ajax_` and there is no `wp_ajax_nopriv_` handler, treat it as authenticated-only.

## Output Contract (API schema your UI expects)
When generating or consuming context packs, do not break response keys. The UI depends on stable contracts.

### `aicb_generate_context` success payload
The server returns JSON (via `wp_send_json_success`) with these keys:
- `scan_id` (string)
- `summary` (object: file_count, total_size, total_size_raw, class_count, function_count, hook_count, complexity_score, est_tokens)
- `context` (string; may be empty if `context_truncated=true`)
- `context_preview` (string preview; not guaranteed JSON-safe)
- `context_truncated` (boolean)
- `context_format` (string: json|md|text)
- `agent_md` (string; may be truncated in preview)
- `meta`, `stats`, `target` (objects)

### Truncation rules
- If clipboard output exceeds the server cap, `context` becomes an empty string and `context_truncated=true`.
- Use `summary` for dashboard stats; do not `JSON.parse(context)` when `context_truncated=true`.

### Error payload
On failure (`wp_send_json_error`), expect a structured error object. Preserve `message`, and any diagnostic keys, but do not assume shape beyond standard WP AJAX error responses.

### Download routes
Download endpoints should return raw file content (MD/JSON) and must not embed HTML. Prefer stable filenames and ensure content is generated from stored payloads.

## Extend Here
Add scan modules in `includes/Traits/ScanningEngine.php` (behaviour index + integrations) and `includes/Traits/AiOptimization.php` (semantic index).
Add output formats and download handlers in `includes/Traits/ContextLibrary.php` and `includes/Core/BridgeCore.php`.
Wizard UI lives in `assets/js/wizard-app.js` and must not depend on parsing large clipboard JSON for dashboard stats.

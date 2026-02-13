# RTS Subscriber + Newsletter Architecture

## Core Components
- `RTS_Subscriber_System`: boots classes, default options, DB version checks.
- `RTS_Database_Installer`: centralized schema install + additive migrations.
- `RTS_Email_Engine`: template rendering, queue send, tracking, bounce handling.
- `RTS_Email_Queue`: enqueue/claim/retry/dead-letter/retention cleanup.
- `RTS_Newsletter_CPT`: builder UI, newsletter queue orchestration.
- `RTS_Newsletter_API`: save/preview/analytics/templates/workflow + health endpoints.
- `RTS_CSV_Importer`: chunked import/export for large subscriber datasets.
- `RTS_Audit_Logger`: system audit trail + optional outbound webhooks.

## Data Tables
- `rts_email_queue`: pending/processing/sent delivery queue.
- `rts_email_logs`: per-send logs and outcomes.
- `rts_email_tracking`: open/click tracking events.
- `rts_email_bounces`: bounce/error history.
- `rts_dead_letter_queue`: permanently failed queue items.
- `rts_newsletter_analytics`: newsletter event stream.
- `rts_newsletter_templates`: visual template library.
- `rts_newsletter_versions`: snapshot history.
- `rts_newsletter_audit`: newsletter workflow/sending actions.
- `rts_system_audit`: cross-system operational audit trail.

## Performance Indexes (Required)
- `rts_email_queue.status_scheduled (status, scheduled_at)`
- `rts_email_queue.subscriber_id`
- `rts_newsletter_analytics.newsletter_event (newsletter_id, event_type)`
- `rts_newsletter_analytics.occurred_at`
- `rts_subscribers.status_next_send (status, next_send_date)`
- `rts_system_audit.entity_lookup (entity_type, entity_id)`

## Reliability Guards
- Runtime table bootstrap fallback in `RTS_Email_Engine::maybe_create_tables()`.
- Import lock uses DB option atomic lock with stale-lock takeover.
- Queue stale `processing` recovery uses `COALESCE(updated_at, created_at)`.
- API rate limiting via transient window counters (per user/IP + route key).

## Health Endpoints
- `GET /wp-json/rts-newsletter/v1/health`
- `GET /wp-json/rts-newsletter/v1/status`

## Retention Controls (Options)
- `rts_queue_retention_sent_days`
- `rts_queue_retention_cancelled_days`
- `rts_queue_stuck_timeout_minutes`
- `rts_retention_email_logs_days`
- `rts_retention_tracking_days`
- `rts_retention_bounce_days`

## Webhooks
- Enable: `rts_webhook_enabled` = `1`
- URL: `rts_webhook_url`
- Optional HMAC secret: `rts_webhook_secret` (`X-RTS-Signature`)

## A/B Subject Variants
- Stored on newsletter meta key: `_rts_newsletter_subject_variants` (JSON array).
- Queueing uses deterministic subscriber hash selection for stable split.

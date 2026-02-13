# Hello-Elementor-Child-RTS-v5.3.2

Production WordPress child theme for Reasons To Stay.

## Status
- Environment checked on server: `reasonstostay.co.uk`
- Active stylesheet: `Hello-Elementor-Child-RTS-v5.3.2`
- PHP syntax check: passed for all theme PHP files
- DB integrity check (`wp db check`): passed
- RTS core post types present: `letter`, `rts_subscriber`, `rts_newsletter`
- RTS cron jobs present and scheduled
- Key JS/CSS assets present and web-accessible

## Theme Structure
- `functions.php` - bootstrap and global hooks
- `inc/` - letters/admin/workflow/rest/security modules
- `subscribers/` - subscriber/newsletter/admin subsystem
- `assets/` - shared CSS/JS
- `embeds/` - embeddable widget API + loader

## Pre-deploy / Post-deploy Checklist
1. Confirm theme is active:
   - `wp option get stylesheet`
2. Confirm WordPress DB is healthy:
   - `wp db check`
3. Confirm RTS post types:
   - `wp post-type list --fields=name,label,public,show_ui | grep -E 'letter|rts_subscriber|rts_newsletter'`
4. Confirm admin pages require auth (expected 302 to login when unauthenticated):
   - `/wp-admin/edit.php?post_type=letter&page=rts-dashboard`
   - `/wp-admin/edit.php?post_type=rts_subscriber&page=rts-subscribers-dashboard`
5. In wp-admin (logged in), smoke test:
   - Letters dashboard loads
   - Subscribers dashboard loads
   - Add subscriber works
   - Newsletter builder opens and saves

## Notes
- This README reflects production validation run on 2026-02-12 (UTC).
- Test scaffold files were intentionally removed from this deployment copy.

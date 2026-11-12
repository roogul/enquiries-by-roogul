# Release Notes for Enquiries by ROOGUL

## 1.0.1 - 2026-11-12

### Fixed
- Referrers are stored and linked only when they are http or https URLs. A crafted Referer header can no longer run script when an admin clicks the page link in the control panel.
- URL fields in the submission view are linked only for http or https values.
- CSV export neutralises cells that spreadsheets would treat as formulas.

Sites already on 1.0.0 clear unsafe stored referrers when Craft runs the 1.0.1 migration.

## 1.0.0 - 2026-11-03

### Added
- Forms defined in the Control Panel and stored in project config, with field validation by type.
- Submissions stored as elements, with per-form sources, New / Read / Spam statuses, search, bulk actions and a flattened CSV/Excel exporter.
- Email notification, auto-reply, Slack, GoHighLevel, Brevo and webhook integrations, each optional per form.
- “Only send when…” condition on every integration, for consent checkboxes.
- Queued delivery with a per-submission log, automatic retries with back-off, and Retry/Resend buttons.
- Honeypot, signed time trap and optional Cloudflare Turnstile.
- Retention settings for submissions and spam.
- `EVENT_REGISTER_INTEGRATION_TYPES` for adding more CRMs, plus before/after submit events.
# Release Notes for Enquiries by ROOGUL

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

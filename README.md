# Enquiries by ROOGUL

Contact form handling for Craft CMS 5, without a form builder. You write the form markup in your own templates. The plugin validates and stores each submission, blocks spam, and sends it on to email, Slack, GoHighLevel, Brevo or any webhook.

- **Forms are code-light config.** Each form is a handle plus a list of fields (handle, label, type, required). It's saved to project config, so it deploys like sections do.
- **Submissions are elements.** You get search, sources per form, statuses (New / Read / Spam), bulk actions and CSV/Excel export.
- **Everything outbound is optional and per form.** Email notification, auto-reply, Slack, GoHighLevel, Brevo and webhook are each switched on separately.
- **Deliveries are queued, logged and retried.** Each submission shows what was sent where, with a Retry button.
- **More CRMs can be added** by registering an integration class.

## Requirements

Craft CMS 5.0+ and PHP 8.2+.

## Installation

```bash
composer require roogul/enquiries-by-roogul
php craft plugin/install enquiries-by-roogul
```

## 1. Create a form

Go to **Enquiries → Forms → New form**:

- **Settings:** name, handle, success message, an optional redirect and a submission label format (e.g. `{name} – {company}`).
- **Fields:** the fields your template posts. Only listed fields are stored, and anything else in the request is ignored.
- **Notifications:** email notification, auto-reply and Slack.
- **Integrations:** GoHighLevel, Brevo and webhook.
- **Spam:** Cloudflare Turnstile (optional).

Field types and how they're validated:

| Type | Validation |
|---|---|
| Text | max 1,000 characters |
| Multi-line text | max 20,000 characters |
| Email | valid email address |
| Phone | digits, spaces, `+ ( ) - .`, at least 6 digits |
| Number | numeric |
| URL | starts with `http://` or `https://` |
| Date | parseable date |
| Checkbox (yes/no) | stored as true/false; "required" means it must be ticked |
| Multiple values | stored as an array (checkbox groups, multi-selects) |

Forms are admin-only and need `allowAdminChanges`. On production, make changes locally and deploy them with project config.

## 2. Add the form to a template

```twig
{% set enquiry = craft.enquiries.submission('contact') %}
{% set success = craft.enquiries.success('contact') %}

{% if success %}
  <p class="notice">{{ success }}</p>
{% endif %}

<form method="post">
  {{ csrfInput() }}
  {{ craft.enquiries.inputs('contact') }}

  <label>Name
    <input name="fields[name]" value="{{ enquiry.value('name') }}">
  </label>
  {{ enquiry.getFirstError('name') }}

  <label>Email
    <input type="email" name="fields[email]" value="{{ enquiry.value('email') }}">
  </label>
  {{ enquiry.getFirstError('email') }}

  <label>Message
    <textarea name="fields[message]">{{ enquiry.value('message') }}</textarea>
  </label>
  {{ enquiry.getFirstError('message') }}

  <label>
    <input type="checkbox" name="fields[marketing]" value="1"> Keep me posted
  </label>

  {{ enquiry.getFirstError('turnstile') }}
  <button>Send</button>
</form>
```

`craft.enquiries.inputs()` outputs the action input, the form handle, the spam traps and, if it's enabled, the Turnstile widget. Pass `{ redirect: '/thanks' }` to override the form's redirect, or `{ turnstile: false }` to place the widget yourself with `craft.enquiries.turnstile('contact')`.

Multiple forms on one page work fine. `submission()` and `success()` only return data for the form whose handle you pass.

### AJAX

Send the request with an `Accept: application/json` header:

```js
form.addEventListener('submit', async (e) => {
  e.preventDefault();
  const res = await fetch('/', {
    method: 'POST',
    headers: { Accept: 'application/json' },
    body: new FormData(form),
  });
  const data = await res.json();
  // 200 → { success: true, message, form }
  // 400 → { success: false, message, form, errors: { email: ['Email must be a valid email address.'] } }
});
```

### Twig reference

| | |
|---|---|
| `craft.enquiries.inputs(handle, options)` | Required hidden inputs and spam traps |
| `craft.enquiries.submission(handle)` | The failed submission (to repopulate fields and show errors), or a blank one |
| `craft.enquiries.success(handle)` | The success message after a non-AJAX submit, or `null` |
| `craft.enquiries.turnstile(handle)` | The Turnstile widget on its own |
| `craft.enquiries.form(handle)` / `forms()` | Form models |
| `craft.enquiries.submissions({ form: 'contact' })` | A submission element query |

On a submission: `value(handle)`, `valueAsString(handle)`, `values`, `getDisplayValues()`, `form`, `getErrors(handle)`.

## 3. Connect integrations

Add credentials under **Settings → Plugins → Enquiries by ROOGUL**. Use environment variables so keys stay out of project config.

**GoHighLevel.** Create a Private Integration in the sub-account (Settings → Private Integrations) with the `contacts.write` scope. Then add the token and the location ID. For each form:

- Map fields to contact fields (Email or Phone is required).
- Optionally map custom fields. Field IDs are the most reliable option.
- Set tags and a source.
- Choose whether to add the full enquiry as a note.

Contacts are upserted, and tags are added through the add-tags endpoint, so a contact's existing tags are kept. The API version header defaults to `2021-07-28` and can be changed.

**Brevo.** Add an API key (SMTP & API → API keys). For each form, choose:

- the email field
- attribute mapping (`FIRSTNAME`, `LASTNAME`, `SMS` or your own attributes)
- list IDs
- whether existing contacts get updated

**Slack.** Create an incoming webhook for your Slack app. Set a default webhook in the plugin settings, or one per form to post to different channels.

**Webhook.** Each submission is POSTed as JSON. With a signing secret set, every request carries `X-Enquiries-Signature: sha256=<hmac of the raw body>`. Verify it like this:

```php
$expected = 'sha256=' . hash_hmac('sha256', file_get_contents('php://input'), $secret);
hash_equals($expected, $_SERVER['HTTP_X_ENQUIRIES_SIGNATURE'] ?? '');
```

### Consent

Every integration has an **Only send when…** option. Point it at a checkbox field, such as marketing consent, and that integration is skipped unless the box is ticked. The skip is recorded in the delivery log.

### Per-environment overrides

Plugin settings can also come from `config/enquiries-by-roogul.php`:

```php
return [
    'integrations' => [
        'gohighlevel' => ['token' => '$GHL_TOKEN', 'locationId' => '$GHL_LOCATION_ID'],
        'brevo' => ['apiKey' => '$BREVO_API_KEY'],
        'slack' => ['webhookUrl' => '$SLACK_WEBHOOK_URL'],
    ],
];
```

## Delivery, retries and the queue

Each enabled integration becomes its own queue job.

- **Retryable failures** (timeouts, HTTP 429, 5xx) are retried after 1, 5, 15 and 60 minutes, up to the configured number of attempts (default 3).
- **Permanent failures** (bad credentials, rejected data) fail straight away.

By default the plugin runs the queue once the visitor's response has been sent, so nothing waits for a Control Panel visit. If you run a dedicated queue worker (Craft Cloud, `php craft queue/listen`, a daemon), you can turn **Send straight after each submission** off. Delayed retries still need something to run the queue: a worker, a cron running `php craft queue/run`, or Control Panel traffic.

Each enquiry's page shows its delivery log with Retry and Resend buttons. The submissions index flags failed deliveries, and you can bulk-resend with the **Resend to integrations** action.

## Spam

- **Honeypot:** a hidden input that bots fill in. You can rename it in the settings.
- **Time trap:** submissions faster than N seconds after the page rendered (default 3) are rejected. The render time is signed.
- **Turnstile (optional, per form):** checked server-side. If Cloudflare can't be reached, the submission is allowed rather than lost.

Honeypot and time-trap hits get a normal success response, so bots learn nothing. They're stored as Spam (and never sent anywhere) unless you turn **Keep spam** off. To rescue a false positive, use **Not spam – send it** on its page.

If you full-page cache your form pages, the time trap still works. Cached timestamps only ever get older. Your CSRF token needs the usual handling for cached pages.

## Data and permissions

- **Retention:** delete submissions after N days, and spam after N days (default 30). This runs during Craft's garbage collection.
- **IP and user agent:** storing them is optional.
- **Permissions:** *View submissions* and *Delete submissions*. Forms and settings are admin-only.

## Adding a CRM

Extend `roogul\enquiries\integrations\BaseIntegration` and register it:

```php
use craft\events\RegisterComponentTypesEvent;
use roogul\enquiries\services\Integrations;
use yii\base\Event;

Event::on(Integrations::class, Integrations::EVENT_REGISTER_INTEGRATION_TYPES, function(RegisterComponentTypesEvent $e) {
    $e->types[] = \modules\HubSpot::class;
});
```

Implement `handle()`, `displayName()` and `send()`. Optionally add:

- `getSettingsHtml()` and `isConfigured()` for credentials
- `defaultFormConfig()`, `validateFormConfig()` and a template at `enquiries-by-roogul/integrations/<handle>` for per-form settings

Throw `IntegrationException($message, retryable: true)` for failures worth retrying. The `request()` helper does that for you based on the HTTP status.

## Events

`SubmitController::EVENT_BEFORE_SUBMIT` and `EVENT_AFTER_SUBMIT` pass a `SubmissionEvent`. In the before event you can:

- add errors with `$event->submission->addError('email', '…')`
- flag the submission as spam with `$event->isSpam = true`
- stop it with `$event->isValid = false`

<?php

namespace roogul\enquiries\integrations;

use Craft;
use craft\helpers\App;
use craft\helpers\Json;
use craft\web\View;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use roogul\enquiries\elements\Submission;
use roogul\enquiries\models\Form;
use yii\base\Component;

/**
 * Base class for anything a submission can be sent to: email, Slack, CRMs,
 * webhooks. Register your own with Integrations::EVENT_REGISTER_INTEGRATION_TYPES.
 *
 * Global credentials (API keys etc.) come from the plugin settings and are
 * passed in as `$settings`. Per-form settings (mapping, lists, recipients)
 * are passed to each method as `$config`.
 */
abstract class BaseIntegration extends Component
{
    public const CATEGORY_NOTIFICATION = 'notification';
    public const CATEGORY_CRM = 'crm';
    public const CATEGORY_OTHER = 'other';

    /** Global settings for this integration, as saved (env vars not yet parsed). */
    public array $settings = [];

    abstract public static function handle(): string;

    abstract public static function displayName(): string;

    public static function category(): string
    {
        return self::CATEGORY_CRM;
    }

    public static function description(): string
    {
        return '';
    }

    /**
     * Send the submission. Return a remote ID or short note for the sync log.
     *
     * @throws IntegrationException
     */
    abstract public function send(Submission $submission, Form $form, array $config): ?string;

    /**
     * Fields for the plugin settings page (API keys etc.). Return null for none.
     * Inputs should be named `integrations[<handle>][<key>]` (Craft adds the `settings` namespace).
     */
    public function getSettingsHtml(): ?string
    {
        return null;
    }

    /** Whether global credentials are in place. Unconfigured integrations can't be enabled. */
    public function isConfigured(): bool
    {
        return true;
    }

    /**
     * Per-form settings HTML. Inputs should be named `integrations[<handle>][<key>]`.
     */
    public function getFormSettingsHtml(Form $form, array $config): string
    {
        return Craft::$app->getView()->renderTemplate(
            'enquiries-by-roogul/integrations/' . static::handle(),
            [
                'integration' => $this,
                'form' => $form,
                'config' => array_merge($this->defaultFormConfig($form), $config),
                'fieldOptions' => $this->fieldOptions($form),
                'emailFieldOptions' => $this->fieldOptions($form, true),
                'namespace' => 'integrations[' . static::handle() . ']',
            ],
            View::TEMPLATE_MODE_CP,
        );
    }

    public function defaultFormConfig(Form $form): array
    {
        return [];
    }

    /**
     * Keys in the per-form config that are lightswitches, cast to booleans on save.
     *
     * @return string[]
     */
    protected static function booleanSettings(): array
    {
        return [];
    }

    /**
     * Cleans posted per-form config before it's validated and saved.
     */
    public function normaliseFormConfig(array $config): array
    {
        foreach (static::booleanSettings() as $key) {
            if (array_key_exists($key, $config)) {
                $config[$key] = in_array($config[$key], [true, 1, '1', 'true', 'on'], true);
            }
        }

        return $config;
    }

    /**
     * @return string[] Error messages. Only called when the integration is enabled on the form.
     */
    public function validateFormConfig(Form $form, array $config): array
    {
        $errors = [];

        if (!$this->isConfigured()) {
            $errors[] = Craft::t('enquiries-by-roogul', 'Add the credentials in the plugin settings first.');
        }

        $onlyIf = $config['onlyIfField'] ?? '';
        if ($onlyIf !== '' && !$form->getField($onlyIf)) {
            $errors[] = Craft::t('enquiries-by-roogul', '“{handle}” isn’t one of this form’s fields.', ['handle' => $onlyIf]);
        }

        return $errors;
    }

    /**
     * Whether this submission should be sent. Returns a reason string to skip.
     */
    public function shouldSkip(Submission $submission, array $config): ?string
    {
        $onlyIf = $config['onlyIfField'] ?? '';

        if ($onlyIf !== '' && !$submission->isTruthy($onlyIf)) {
            $label = $submission->getForm()?->getField($onlyIf)['label'] ?? $onlyIf;
            return Craft::t('enquiries-by-roogul', 'Skipped: “{field}” wasn’t ticked or filled in.', ['field' => $label]);
        }

        return null;
    }

    // Helpers ------------------------------------------------------------

    protected function setting(string $key, mixed $default = null): mixed
    {
        $value = $this->settings[$key] ?? null;

        if (is_string($value)) {
            $value = App::parseEnv($value);
        }

        return ($value === null || $value === '') ? $default : $value;
    }

    protected function parse(?string $value): string
    {
        return trim((string)App::parseEnv((string)$value));
    }

    /**
     * Turns a mapping table (`[['from' => 'formField', 'to' => 'remoteField'], ...]`)
     * into `remoteField => value`, skipping blanks.
     */
    protected function mapValues(Submission $submission, array $rows, bool $asString = true): array
    {
        $out = [];

        foreach ($rows as $row) {
            $from = trim((string)($row['from'] ?? ''));
            $to = trim((string)($row['to'] ?? ''));

            if ($from === '' || $to === '') {
                continue;
            }

            $value = $asString ? $submission->valueAsString($from) : $submission->value($from);

            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            $out[$to] = $value;
        }

        return $out;
    }

    /**
     * Validates that each `from` in a mapping table is a field on the form.
     *
     * @return string[]
     */
    protected function validateMapping(Form $form, array $rows): array
    {
        $errors = [];

        foreach ($rows as $row) {
            $from = trim((string)($row['from'] ?? ''));

            if ($from !== '' && !$form->getField($from)) {
                $errors[] = Craft::t('enquiries-by-roogul', '“{handle}” isn’t one of this form’s fields. Available: {handles}', [
                    'handle' => $from,
                    'handles' => implode(', ', $form->getFieldHandles()),
                ]);
            }
        }

        return $errors;
    }

    protected function fieldOptions(Form $form, bool $emailOnly = false): array
    {
        $options = [['label' => '', 'value' => '']];

        foreach ($form->fields as $field) {
            if ($emailOnly && $field['type'] !== Form::TYPE_EMAIL) {
                continue;
            }

            $options[] = ['label' => $field['label'] . ' (' . $field['handle'] . ')', 'value' => $field['handle']];
        }

        return $options;
    }

    /**
     * Sends an HTTP request and classifies failures as retryable or not.
     *
     * @throws IntegrationException
     */
    protected function request(string $method, string $url, array $options = []): array
    {
        $client = Craft::createGuzzleClient([
            'timeout' => 15,
            'connect_timeout' => 10,
        ]);

        try {
            $response = $client->request($method, $url, $options);
        } catch (ConnectException $e) {
            throw new IntegrationException('Could not connect: ' . $e->getMessage(), true, $e);
        } catch (RequestException $e) {
            $response = $e->getResponse();

            if (!$response) {
                throw new IntegrationException($e->getMessage(), true, $e);
            }

            $status = $response->getStatusCode();
            $body = mb_substr((string)$response->getBody(), 0, 1000);
            $retryable = $status === 429 || $status >= 500;

            throw new IntegrationException("HTTP $status: " . ($body !== '' ? $body : $response->getReasonPhrase()), $retryable, $e);
        }

        $body = (string)$response->getBody();

        return [
            'status' => $response->getStatusCode(),
            'body' => $body,
            'json' => Json::decodeIfJson($body),
        ];
    }

    /** Splits a comma/newline separated string into trimmed, non-empty parts. */
    protected static function splitList(?string $value): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[,\n]+/', (string)$value) ?: []), fn($v) => $v !== ''));
    }
}

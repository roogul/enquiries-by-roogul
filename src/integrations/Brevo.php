<?php

namespace roogul\enquiries\integrations;

use Craft;
use roogul\enquiries\elements\Submission;
use roogul\enquiries\models\Form;

/**
 * Creates or updates a Brevo contact and adds it to lists.
 */
class Brevo extends BaseIntegration
{
    public const DEFAULT_BASE_URL = 'https://api.brevo.com/v3';

    public static function handle(): string
    {
        return 'brevo';
    }

    public static function displayName(): string
    {
        return 'Brevo';
    }

    public static function description(): string
    {
        return Craft::t('enquiries-by-roogul', 'Create or update a Brevo contact and add it to lists.');
    }

    public function getSettingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('enquiries-by-roogul/integrations/_settings/brevo', [
            'settings' => $this->settings,
        ]);
    }

    public function isConfigured(): bool
    {
        return (bool)$this->setting('apiKey');
    }

    protected static function booleanSettings(): array
    {
        return ['updateEnabled'];
    }

    public function defaultFormConfig(Form $form): array
    {
        $attributes = [];
        $guesses = [
            'FIRSTNAME' => ['firstName', 'first_name', 'forename'],
            'LASTNAME' => ['lastName', 'last_name', 'surname'],
        ];

        foreach ($guesses as $to => $candidates) {
            foreach ($candidates as $candidate) {
                if ($form->getField($candidate)) {
                    $attributes[] = ['from' => $candidate, 'to' => $to];
                    break;
                }
            }
        }

        return [
            'emailField' => $form->getEmailFieldHandles()[0] ?? '',
            'attributes' => $attributes,
            'listIds' => '',
            'updateEnabled' => true,
            'onlyIfField' => '',
        ];
    }

    public function validateFormConfig(Form $form, array $config): array
    {
        $errors = parent::validateFormConfig($form, $config);
        $emailField = $config['emailField'] ?? '';

        if ($emailField === '' || !$form->getField($emailField)) {
            $errors[] = Craft::t('enquiries-by-roogul', 'Choose the field that holds the contact’s email address.');
        }

        foreach (self::splitList((string)($config['listIds'] ?? '')) as $id) {
            if (!ctype_digit($id)) {
                $errors[] = Craft::t('enquiries-by-roogul', 'List IDs must be numbers, separated by commas.');
                break;
            }
        }

        foreach ((array)($config['attributes'] ?? []) as $row) {
            $to = trim((string)($row['to'] ?? ''));
            if ($to !== '' && !preg_match('/^[A-Z0-9_]+$/', $to)) {
                $errors[] = Craft::t('enquiries-by-roogul', 'Brevo attribute names are uppercase, e.g. FIRSTNAME. “{name}” isn’t.', ['name' => $to]);
            }
        }

        return array_merge($errors, $this->validateMapping($form, (array)($config['attributes'] ?? [])));
    }

    public function send(Submission $submission, Form $form, array $config): ?string
    {
        $config = array_merge($this->defaultFormConfig($form), $config);
        $email = $submission->valueAsString((string)$config['emailField']);

        if ($email === '') {
            throw new IntegrationException('Submission has no email address.');
        }

        $payload = [
            'email' => $email,
            'updateEnabled' => (bool)$config['updateEnabled'],
        ];

        $attributes = [];
        foreach ((array)$config['attributes'] as $row) {
            $from = trim((string)($row['from'] ?? ''));
            $to = trim((string)($row['to'] ?? ''));

            if ($from === '' || $to === '') {
                continue;
            }

            $field = $form->getField($from);
            $value = $field && $field['type'] === Form::TYPE_BOOLEAN
                ? $submission->isTruthy($from)
                : $submission->valueAsString($from);

            if ($value !== '') {
                $attributes[$to] = $value;
            }
        }

        if ($attributes) {
            $payload['attributes'] = $attributes;
        }

        $listIds = array_map('intval', self::splitList((string)$config['listIds']));
        if ($listIds) {
            $payload['listIds'] = $listIds;
        }

        $base = rtrim((string)$this->setting('baseUrl', self::DEFAULT_BASE_URL), '/');
        $response = $this->request('POST', $base . '/contacts', [
            'headers' => [
                'api-key' => (string)$this->setting('apiKey'),
                'Accept' => 'application/json',
            ],
            'json' => $payload,
        ]);

        // 201 returns the new ID; 204 means an existing contact was updated.
        $id = $response['json']['id'] ?? null;

        return $id ? $id . ' (new)' : Craft::t('enquiries-by-roogul', 'Updated existing contact');
    }
}

<?php

namespace roogul\enquiries\integrations;

use Craft;
use roogul\enquiries\elements\Submission;
use roogul\enquiries\models\Form;

/**
 * Creates or updates a contact in a GoHighLevel sub-account using a Private
 * Integration token (API v2), then adds tags and an optional note.
 *
 * Token scopes needed: contacts.write (plus contacts.readonly).
 */
class GoHighLevel extends BaseIntegration
{
    public const DEFAULT_BASE_URL = 'https://services.leadconnectorhq.com';
    public const DEFAULT_API_VERSION = '2021-07-28';

    public static function handle(): string
    {
        return 'gohighlevel';
    }

    public static function displayName(): string
    {
        return 'GoHighLevel';
    }

    public static function description(): string
    {
        return Craft::t('enquiries-by-roogul', 'Create or update a contact in a GoHighLevel sub-account.');
    }

    /**
     * Standard contact fields the upsert endpoint accepts.
     */
    public static function contactFields(): array
    {
        return [
            'firstName' => 'First name',
            'lastName' => 'Last name',
            'name' => 'Full name',
            'email' => 'Email',
            'phone' => 'Phone',
            'companyName' => 'Company name',
            'website' => 'Website',
            'address1' => 'Address',
            'city' => 'City',
            'state' => 'State / county',
            'postalCode' => 'Postcode',
            'country' => 'Country (2-letter code)',
            'dateOfBirth' => 'Date of birth',
            'timezone' => 'Timezone',
        ];
    }

    public function getSettingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('enquiries-by-roogul/integrations/_settings/gohighlevel', [
            'settings' => $this->settings,
            'defaultVersion' => self::DEFAULT_API_VERSION,
        ]);
    }

    public function isConfigured(): bool
    {
        return $this->setting('token') && $this->setting('locationId');
    }

    protected static function booleanSettings(): array
    {
        return ['addNote'];
    }

    public function defaultFormConfig(Form $form): array
    {
        $mapping = [];
        $guesses = [
            'firstName' => ['firstName', 'first_name', 'forename'],
            'lastName' => ['lastName', 'last_name', 'surname'],
            'name' => ['name', 'fullName', 'full_name'],
            'email' => ['email', 'emailAddress', 'email_address'],
            'phone' => ['phone', 'telephone', 'tel', 'mobile', 'phoneNumber'],
            'companyName' => ['company', 'companyName', 'organisation', 'organization', 'business'],
            'website' => ['website', 'url'],
        ];

        foreach ($guesses as $to => $candidates) {
            foreach ($candidates as $candidate) {
                if ($form->getField($candidate)) {
                    $mapping[] = ['from' => $candidate, 'to' => $to];
                    break;
                }
            }
        }

        return [
            'mapping' => $mapping,
            'customFields' => [],
            'tags' => 'website enquiry',
            'source' => '{form.name} form',
            'addNote' => true,
            'onlyIfField' => '',
        ];
    }

    public function validateFormConfig(Form $form, array $config): array
    {
        $errors = parent::validateFormConfig($form, $config);
        $mapping = (array)($config['mapping'] ?? []);

        $errors = array_merge(
            $errors,
            $this->validateMapping($form, $mapping),
            $this->validateMapping($form, (array)($config['customFields'] ?? [])),
        );

        $targets = array_column($mapping, 'to');
        if (!in_array('email', $targets, true) && !in_array('phone', $targets, true)) {
            $errors[] = Craft::t('enquiries-by-roogul', 'Map a field to Email or Phone. GoHighLevel needs one to match contacts.');
        }

        return $errors;
    }

    public function send(Submission $submission, Form $form, array $config): ?string
    {
        $config = array_merge($this->defaultFormConfig($form), $config);

        $payload = $this->mapValues($submission, (array)$config['mapping']);
        $payload['locationId'] = (string)$this->setting('locationId');

        if (empty($payload['email']) && empty($payload['phone'])) {
            throw new IntegrationException('Submission has no email or phone to match a contact on.');
        }

        if ($source = $submission->renderObjectTemplate((string)$config['source'])) {
            $payload['source'] = mb_substr($source, 0, 255);
        }

        $customFields = [];
        foreach ($this->mapValues($submission, (array)$config['customFields']) as $key => $value) {
            // Accept either a field ID or a key like `contact.enquiry_type`.
            $customFields[] = preg_match('/^[A-Za-z0-9]{16,}$/', $key)
                ? ['id' => $key, 'field_value' => $value]
                : ['key' => preg_replace('/^contact\./', '', $key), 'field_value' => $value];
        }
        if ($customFields) {
            $payload['customFields'] = $customFields;
        }

        $response = $this->api('POST', '/contacts/upsert', $payload);
        $contactId = $response['json']['contact']['id'] ?? null;

        if (!$contactId) {
            throw new IntegrationException('GoHighLevel didn’t return a contact ID: ' . mb_substr($response['body'], 0, 500));
        }

        // Tags go through the add-tags endpoint so existing tags are kept.
        $tags = self::splitList($submission->renderObjectTemplate((string)$config['tags']));
        if ($tags) {
            $this->api('POST', "/contacts/$contactId/tags", ['tags' => $tags]);
        }

        if (!empty($config['addNote'])) {
            $lines = [Craft::t('enquiries-by-roogul', '{form} submission', ['form' => $form->name])];
            foreach ($submission->getDisplayValues() as $row) {
                if ($row['value'] !== '') {
                    $lines[] = $row['label'] . ': ' . $row['value'];
                }
            }
            $this->api('POST', "/contacts/$contactId/notes", ['body' => implode("\n", $lines)]);
        }

        $isNew = $response['json']['new'] ?? null;

        return $contactId . ($isNew === null ? '' : ($isNew ? ' (new)' : ' (updated)'));
    }

    private function api(string $method, string $path, array $json): array
    {
        $base = rtrim((string)$this->setting('baseUrl', self::DEFAULT_BASE_URL), '/');

        return $this->request($method, $base . $path, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->setting('token'),
                'Version' => (string)$this->setting('apiVersion', self::DEFAULT_API_VERSION),
                'Accept' => 'application/json',
            ],
            'json' => $json,
        ]);
    }
}

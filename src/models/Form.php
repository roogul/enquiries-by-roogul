<?php

namespace roogul\enquiries\models;

use Craft;
use craft\base\Model;
use craft\helpers\StringHelper;
use craft\validators\HandleValidator;
use roogul\enquiries\Plugin;

/**
 * A form definition. There's no form builder: the markup lives in your
 * templates, and this tells the plugin which fields to accept, how to
 * validate them, and where to send the submission.
 */
class Form extends Model
{
    public const TYPE_TEXT = 'text';
    public const TYPE_TEXTAREA = 'textarea';
    public const TYPE_EMAIL = 'email';
    public const TYPE_PHONE = 'phone';
    public const TYPE_NUMBER = 'number';
    public const TYPE_URL = 'url';
    public const TYPE_DATE = 'date';
    public const TYPE_BOOLEAN = 'boolean';
    public const TYPE_MULTI = 'multi';

    public ?int $id = null;
    public ?string $uid = null;
    public string $name = '';
    public string $handle = '';

    /**
     * @var array<int, array{handle: string, label: string, type: string, required: bool}>
     */
    public array $fields = [];

    /** Object template used to label submissions in the CP, e.g. `{name} – {company}`. */
    public string $titleFormat = '';

    public string $successMessage = '';

    /** Default redirect after a successful (non-AJAX) submission. Object template; `{id}` is available. */
    public string $redirect = '';

    public bool $turnstile = false;

    /**
     * Per-form integration config, keyed by integration handle. Each has an
     * `enabled` key plus integration-specific settings.
     *
     * @var array<string, array<string, mixed>>
     */
    public array $integrations = [];

    public static function fieldTypes(): array
    {
        return [
            self::TYPE_TEXT => Craft::t('enquiries-by-roogul', 'Text'),
            self::TYPE_TEXTAREA => Craft::t('enquiries-by-roogul', 'Multi-line text'),
            self::TYPE_EMAIL => Craft::t('enquiries-by-roogul', 'Email'),
            self::TYPE_PHONE => Craft::t('enquiries-by-roogul', 'Phone'),
            self::TYPE_NUMBER => Craft::t('enquiries-by-roogul', 'Number'),
            self::TYPE_URL => Craft::t('enquiries-by-roogul', 'URL'),
            self::TYPE_DATE => Craft::t('enquiries-by-roogul', 'Date'),
            self::TYPE_BOOLEAN => Craft::t('enquiries-by-roogul', 'Checkbox (yes/no)'),
            self::TYPE_MULTI => Craft::t('enquiries-by-roogul', 'Multiple values'),
        ];
    }

    public function __toString(): string
    {
        return $this->name;
    }

    public function setAttributes($values, $safeOnly = true): void
    {
        if (isset($values['fields'])) {
            $values['fields'] = self::normaliseFields($values['fields']);
        }

        parent::setAttributes($values, $safeOnly);
    }

    /**
     * Accepts editable-table input (or project config) and returns a clean list.
     */
    public static function normaliseFields(mixed $fields): array
    {
        if (!is_array($fields)) {
            return [];
        }

        $clean = [];

        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }

            $handle = trim((string)($field['handle'] ?? ''));

            if ($handle === '') {
                continue;
            }

            $clean[] = [
                'handle' => $handle,
                'label' => trim((string)($field['label'] ?? '')) ?: StringHelper::toTitleCase(StringHelper::toWords($handle)),
                'type' => array_key_exists($field['type'] ?? '', self::fieldTypes()) ? $field['type'] : self::TYPE_TEXT,
                'required' => (bool)($field['required'] ?? false),
            ];
        }

        return $clean;
    }

    public function getField(string $handle): ?array
    {
        foreach ($this->fields as $field) {
            if ($field['handle'] === $handle) {
                return $field;
            }
        }

        return null;
    }

    /**
     * @return string[]
     */
    public function getFieldHandles(): array
    {
        return array_column($this->fields, 'handle');
    }

    /**
     * @return string[] Handles of fields with the email type.
     */
    public function getEmailFieldHandles(): array
    {
        return array_values(array_map(
            fn(array $f) => $f['handle'],
            array_filter($this->fields, fn(array $f) => $f['type'] === self::TYPE_EMAIL),
        ));
    }

    public function getIntegrationConfig(string $handle): array
    {
        return $this->integrations[$handle] ?? [];
    }

    public function isIntegrationEnabled(string $handle): bool
    {
        return !empty($this->integrations[$handle]['enabled']);
    }

    public function getSuccessMessage(): string
    {
        return $this->successMessage !== ''
            ? Craft::t('site', $this->successMessage)
            : Craft::t('enquiries-by-roogul', 'Thanks, your message has been sent.');
    }

    public function getCpEditUrl(): string
    {
        return \craft\helpers\UrlHelper::cpUrl('enquiries-by-roogul/forms/' . $this->handle);
    }

    /**
     * Data written to project config.
     */
    public function getConfig(): array
    {
        return [
            'name' => $this->name,
            'handle' => $this->handle,
            'fields' => array_values($this->fields),
            'titleFormat' => $this->titleFormat,
            'successMessage' => $this->successMessage,
            'redirect' => $this->redirect,
            'turnstile' => $this->turnstile,
            'integrations' => $this->integrations,
        ];
    }

    protected function defineRules(): array
    {
        return [
            [['name', 'handle'], 'required'],
            [['name', 'handle'], 'string', 'max' => 255],
            [['handle'], HandleValidator::class, 'reservedWords' => ['id', 'uid', 'new']],
            [['handle'], 'validateUniqueHandle'],
            [['fields'], 'validateFields'],
            [['integrations'], 'validateIntegrations'],
            [['titleFormat', 'successMessage', 'redirect'], 'string'],
            [['turnstile'], 'boolean'],
        ];
    }

    public function validateUniqueHandle(string $attribute): void
    {
        $existing = Plugin::getInstance()->getForms()->getFormByHandle($this->handle);

        if ($existing && $existing->uid !== $this->uid) {
            $this->addError($attribute, Craft::t('enquiries-by-roogul', 'A form with this handle already exists.'));
        }
    }

    public function validateFields(string $attribute): void
    {
        if (empty($this->fields)) {
            $this->addError($attribute, Craft::t('enquiries-by-roogul', 'Add at least one field.'));
            return;
        }

        $seen = [];

        foreach ($this->fields as $field) {
            if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $field['handle'])) {
                $this->addError($attribute, Craft::t('enquiries-by-roogul', '“{handle}” isn’t a valid field handle. Use letters, numbers and underscores, starting with a letter.', ['handle' => $field['handle']]));
            }

            if (isset($seen[$field['handle']])) {
                $this->addError($attribute, Craft::t('enquiries-by-roogul', 'The field handle “{handle}” is used more than once.', ['handle' => $field['handle']]));
            }

            $seen[$field['handle']] = true;
        }
    }

    public function validateIntegrations(string $attribute): void
    {
        $integrations = Plugin::getInstance()->getIntegrations();

        foreach ($this->integrations as $handle => $config) {
            if (empty($config['enabled'])) {
                continue;
            }

            $integration = $integrations->getIntegration($handle);

            if (!$integration) {
                continue;
            }

            foreach ($integration->validateFormConfig($this, $config) as $error) {
                $this->addError('integrations.' . $handle, $error);
                $this->addError($attribute, $integration::displayName() . ': ' . $error);
            }
        }
    }
}

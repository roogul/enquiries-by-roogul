<?php

namespace roogul\enquiries\elements;

use Craft;
use craft\base\Element;
use craft\elements\actions\Delete;
use craft\elements\User;
use craft\enums\Color;
use craft\helpers\Db;
use craft\helpers\Html;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use DateTime;
use roogul\enquiries\elements\actions\Resend;
use roogul\enquiries\elements\actions\SetSubmissionStatus;
use roogul\enquiries\elements\db\SubmissionQuery;
use roogul\enquiries\exporters\SubmissionsExporter;
use roogul\enquiries\helpers\Urls;
use roogul\enquiries\migrations\Install;
use roogul\enquiries\models\Form;
use roogul\enquiries\Plugin;
use roogul\enquiries\services\Integrations;
use yii\base\InvalidConfigException;

/**
 * A stored form submission.
 *
 * In Twig: `submission.value('email')`, `submission.values`, `submission.form`.
 */
class Submission extends Element
{
    public const STATUS_NEW = 'new';
    public const STATUS_READ = 'read';
    public const STATUS_SPAM = 'spam';

    public const PERMISSION_VIEW = 'enquiriesByRoogul-viewSubmissions';
    public const PERMISSION_DELETE = 'enquiriesByRoogul-deleteSubmissions';

    public ?int $formId = null;
    public string $submissionStatus = self::STATUS_NEW;
    public ?string $label = null;
    public ?string $email = null;
    public ?string $ipAddress = null;
    public ?string $userAgent = null;
    public ?string $referrer = null;
    public ?int $userId = null;
    public ?int $sourceSiteId = null;

    /** Why it was flagged as spam (not stored, used in logs). */
    public ?string $spamReason = null;

    /** @var array<string, mixed> */
    private array $_values = [];

    public static function displayName(): string
    {
        return Craft::t('enquiries-by-roogul', 'Enquiry');
    }

    public static function lowerDisplayName(): string
    {
        return Craft::t('enquiries-by-roogul', 'enquiry');
    }

    public static function pluralDisplayName(): string
    {
        return Craft::t('enquiries-by-roogul', 'Enquiries');
    }

    public static function pluralLowerDisplayName(): string
    {
        return Craft::t('enquiries-by-roogul', 'enquiries');
    }

    public static function refHandle(): ?string
    {
        return 'enquiry';
    }

    public static function hasStatuses(): bool
    {
        return true;
    }

    public static function statuses(): array
    {
        return [
            self::STATUS_NEW => ['label' => Craft::t('enquiries-by-roogul', 'New'), 'color' => Color::Blue],
            self::STATUS_READ => ['label' => Craft::t('enquiries-by-roogul', 'Read'), 'color' => Color::Gray],
            self::STATUS_SPAM => ['label' => Craft::t('enquiries-by-roogul', 'Spam'), 'color' => Color::Red],
        ];
    }

    public static function find(): SubmissionQuery
    {
        return new SubmissionQuery(static::class);
    }

    public function getStatus(): ?string
    {
        return $this->submissionStatus;
    }

    public function isSpam(): bool
    {
        return $this->submissionStatus === self::STATUS_SPAM;
    }

    // Values -------------------------------------------------------------

    public function setData(mixed $data): void
    {
        $this->_values = is_array($data) ? $data : (Json::decodeIfJson((string)$data) ?: []);
    }

    /**
     * @return array<string, mixed> Raw submitted values, keyed by field handle.
     */
    public function getValues(): array
    {
        return $this->_values;
    }

    public function setValues(array $values): void
    {
        $this->_values = $values;
    }

    public function value(string $handle): mixed
    {
        return $this->_values[$handle] ?? null;
    }

    public function valueAsString(string $handle): string
    {
        $value = $this->value($handle);
        $field = $this->getForm()?->getField($handle);

        if (($field['type'] ?? null) === Form::TYPE_BOOLEAN) {
            return $this->isTruthy($handle) ? Craft::t('enquiries-by-roogul', 'Yes') : Craft::t('enquiries-by-roogul', 'No');
        }

        if (is_array($value)) {
            return implode(', ', array_map('strval', array_filter($value, fn($v) => $v !== '' && $v !== null)));
        }

        return trim((string)$value);
    }

    public function isTruthy(string $handle): bool
    {
        $value = $this->value($handle);

        if (is_array($value)) {
            return !empty(array_filter($value, fn($v) => $v !== '' && $v !== null));
        }

        return !in_array(strtolower(trim((string)$value)), ['', '0', 'false', 'no', 'off'], true);
    }

    /**
     * Label/value pairs for every field on the form, in form order, plus any
     * stored values for fields that have since been removed from the form.
     *
     * @return array<int, array{handle: string, label: string, value: string}>
     */
    public function getDisplayValues(): array
    {
        $rows = [];
        $seen = [];

        foreach ($this->getForm()?->fields ?? [] as $field) {
            $rows[] = ['handle' => $field['handle'], 'label' => $field['label'], 'value' => $this->valueAsString($field['handle'])];
            $seen[$field['handle']] = true;
        }

        foreach ($this->_values as $handle => $value) {
            if (!isset($seen[$handle])) {
                $rows[] = ['handle' => $handle, 'label' => $handle, 'value' => $this->valueAsString($handle)];
            }
        }

        return $rows;
    }

    /**
     * Renders an object template like `New enquiry from {name}` against this
     * submission. Available: field handles, `form`, `submission`, `siteName`, `id`.
     *
     * @param bool $encode HTML-encode submitted values (for HTML email bodies).
     */
    public function renderObjectTemplate(string $template, bool $encode = false): string
    {
        if (trim($template) === '') {
            return '';
        }

        $variables = [];

        foreach ($this->getDisplayValues() as $row) {
            $variables[$row['handle']] = $encode ? Html::encode($row['value']) : $row['value'];
        }

        $variables += [
            'form' => $this->getForm(),
            'submission' => $this,
            'id' => $this->id,
            'siteName' => Craft::$app->getSites()->getSiteById($this->sourceSiteId ?? 0)?->getName() ?? Craft::$app->getSystemName(),
        ];

        return Craft::$app->getView()->renderObjectTemplate($template, $variables, $variables);
    }

    public function getForm(): ?Form
    {
        return $this->formId ? Plugin::getInstance()->getForms()->getFormById($this->formId) : null;
    }

    /**
     * @return array<string, array> Sync log rows keyed by integration handle.
     */
    public function getSyncLog(): array
    {
        return $this->id ? Plugin::getInstance()->getIntegrations()->getLog($this->id) : [];
    }

    // Validation ---------------------------------------------------------

    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['formId'], 'required'];
        $rules[] = [['submissionStatus'], 'in', 'range' => array_keys(static::statuses())];

        return $rules;
    }

    /**
     * Validates submitted values against the form's field definitions.
     * Errors are keyed by field handle so templates can use `submission.getErrors('email')`.
     */
    public function validateValues(): bool
    {
        $form = $this->getForm();

        if (!$form) {
            throw new InvalidConfigException('Submission has no form.');
        }

        foreach ($form->fields as $field) {
            $handle = $field['handle'];
            $value = $this->_values[$handle] ?? null;
            $empty = $field['type'] === Form::TYPE_BOOLEAN
                ? !$this->isTruthy($handle)
                : ($value === null || $value === '' || $value === []);

            if ($empty) {
                if ($field['required']) {
                    $this->addError($handle, Craft::t('enquiries-by-roogul', '{label} is required.', ['label' => $field['label']]));
                }
                continue;
            }

            $string = is_array($value) ? '' : (string)$value;

            $error = match ($field['type']) {
                Form::TYPE_EMAIL => filter_var($string, FILTER_VALIDATE_EMAIL) ? null : Craft::t('enquiries-by-roogul', '{label} must be a valid email address.', ['label' => $field['label']]),
                Form::TYPE_URL => preg_match('#^https?://[^\s]+\.[^\s]+$#i', $string) ? null : Craft::t('enquiries-by-roogul', '{label} must be a valid URL, starting with http:// or https://.', ['label' => $field['label']]),
                Form::TYPE_NUMBER => is_numeric($string) ? null : Craft::t('enquiries-by-roogul', '{label} must be a number.', ['label' => $field['label']]),
                Form::TYPE_PHONE => preg_match('/^[0-9+().\-\s]{6,30}$/', $string) && preg_match_all('/\d/', $string) >= 6 ? null : Craft::t('enquiries-by-roogul', '{label} must be a valid phone number.', ['label' => $field['label']]),
                Form::TYPE_DATE => strtotime($string) !== false ? null : Craft::t('enquiries-by-roogul', '{label} must be a valid date.', ['label' => $field['label']]),
                default => null,
            };

            if ($error === null) {
                $length = is_array($value) ? mb_strlen(implode('', $value)) : mb_strlen($string);
                $max = $field['type'] === Form::TYPE_TEXTAREA ? 20000 : 1000;

                if ($length > $max) {
                    $error = Craft::t('enquiries-by-roogul', '{label} is too long.', ['label' => $field['label']]);
                }
            }

            if ($error !== null) {
                $this->addError($handle, $error);
            }
        }

        return !$this->hasErrors();
    }

    /**
     * The stored referrer when it is an http(s) URL, otherwise null.
     * Rows saved before that check can still hold another scheme.
     */
    public function getSafeReferrer(): ?string
    {
        return Urls::httpUrl($this->referrer);
    }

    /**
     * http(s) URL safe to use as an href, or null. Used for submitted URL fields.
     */
    public function safeHttpUrl(?string $url): ?string
    {
        return Urls::httpUrl($url);
    }

    // Saving -------------------------------------------------------------

    public function beforeSave(bool $isNew): bool
    {
        $this->referrer = Urls::httpUrl($this->referrer);

        if ($this->referrer !== null) {
            $this->referrer = mb_substr($this->referrer, 0, 2048);
        }

        $form = $this->getForm();

        if ($form) {
            $this->email = $this->resolveEmail($form);
            $this->label = mb_substr($this->resolveLabel($form), 0, 255) ?: null;
        }

        return parent::beforeSave($isNew);
    }

    public function afterSave(bool $isNew): void
    {
        $columns = [
            'formId' => $this->formId,
            'submissionStatus' => $this->submissionStatus,
            'label' => $this->label,
            'email' => $this->email ? mb_substr($this->email, 0, 255) : null,
            'data' => Json::encode($this->_values),
            'ipAddress' => $this->ipAddress,
            'userAgent' => $this->userAgent ? mb_substr($this->userAgent, 0, 255) : null,
            'referrer' => $this->referrer ? mb_substr($this->referrer, 0, 2048) : null,
            'userId' => $this->userId,
            'sourceSiteId' => $this->sourceSiteId,
        ];

        if ($isNew) {
            Db::insert(Install::SUBMISSIONS, ['id' => $this->id] + $columns);
        } else {
            Db::update(Install::SUBMISSIONS, $columns, ['id' => $this->id]);
        }

        parent::afterSave($isNew);
    }

    /**
     * Updates just the status column, without a full element save.
     */
    public function updateStatus(string $status): void
    {
        $this->submissionStatus = $status;
        Db::update(Install::SUBMISSIONS, ['submissionStatus' => $status], ['id' => $this->id]);
    }

    private function resolveEmail(Form $form): ?string
    {
        foreach ($form->getEmailFieldHandles() as $handle) {
            $value = $this->valueAsString($handle);
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function resolveLabel(Form $form): string
    {
        if ($form->titleFormat !== '') {
            try {
                // Trim separators left dangling by empty values, e.g. "Bob – ".
                $label = preg_replace('/^[\s\-–—|,:·•]+|[\s\-–—|,:·•]+$/u', '', $this->renderObjectTemplate($form->titleFormat)) ?? '';
                if ($label !== '') {
                    return $label;
                }
            } catch (\Throwable $e) {
                Craft::warning('Enquiries: title format failed: ' . $e->getMessage(), __METHOD__);
            }
        }

        foreach (['name', 'fullName', 'full_name'] as $handle) {
            if (($value = $this->valueAsString($handle)) !== '') {
                return $value;
            }
        }

        $name = trim($this->valueAsString('firstName') . ' ' . $this->valueAsString('lastName'));

        return $name !== '' ? $name : (string)$this->email;
    }

    // CP -----------------------------------------------------------------

    protected function uiLabel(): ?string
    {
        return $this->label ?: Craft::t('enquiries-by-roogul', 'Enquiry #{id}', ['id' => $this->id]);
    }

    protected function cpEditUrl(): ?string
    {
        return $this->id ? UrlHelper::cpUrl('enquiries-by-roogul/submissions/' . $this->id) : null;
    }

    public function getPostEditUrl(): ?string
    {
        return UrlHelper::cpUrl('enquiries-by-roogul');
    }

    public function canView(User $user): bool
    {
        return $user->can(self::PERMISSION_VIEW);
    }

    public function canSave(User $user): bool
    {
        return false;
    }

    public function canDuplicate(User $user): bool
    {
        return false;
    }

    public function canDelete(User $user): bool
    {
        return $user->can(self::PERMISSION_DELETE);
    }

    protected static function defineSources(string $context): array
    {
        $sources = [
            [
                'key' => '*',
                'label' => Craft::t('enquiries-by-roogul', 'All enquiries'),
                'criteria' => ['status' => [self::STATUS_NEW, self::STATUS_READ]],
                'defaultSort' => ['dateCreated', 'desc'],
            ],
        ];

        $forms = Plugin::getInstance()->getForms()->getAllForms();

        if ($forms) {
            $sources[] = ['heading' => Craft::t('enquiries-by-roogul', 'Forms')];
        }

        foreach ($forms as $form) {
            $sources[] = [
                'key' => 'form:' . $form->uid,
                'label' => Craft::t('site', $form->name),
                'criteria' => ['formId' => $form->id, 'status' => [self::STATUS_NEW, self::STATUS_READ]],
                'data' => ['handle' => $form->handle],
                'defaultSort' => ['dateCreated', 'desc'],
            ];
        }

        $sources[] = ['heading' => ''];
        $sources[] = [
            'key' => 'spam',
            'label' => Craft::t('enquiries-by-roogul', 'Spam'),
            'criteria' => ['status' => self::STATUS_SPAM],
            'defaultSort' => ['dateCreated', 'desc'],
        ];

        return $sources;
    }

    protected static function defineActions(string $source): array
    {
        return [
            ['type' => SetSubmissionStatus::class, 'status' => self::STATUS_READ],
            ['type' => SetSubmissionStatus::class, 'status' => self::STATUS_NEW],
            ['type' => SetSubmissionStatus::class, 'status' => self::STATUS_SPAM],
            Resend::class,
            Delete::class,
        ];
    }

    protected static function defineExporters(string $source): array
    {
        $exporters = parent::defineExporters($source);
        array_unshift($exporters, SubmissionsExporter::class);

        return $exporters;
    }

    protected static function defineSearchableAttributes(): array
    {
        return ['label', 'email', 'values'];
    }

    protected function searchKeywords(string $attribute): string
    {
        if ($attribute === 'values') {
            return implode(' ', array_map(fn($row) => $row['value'], $this->getDisplayValues()));
        }

        return parent::searchKeywords($attribute);
    }

    protected static function defineSortOptions(): array
    {
        return [
            ['label' => Craft::t('enquiries-by-roogul', 'Label'), 'orderBy' => 'enquiries_submissions.label', 'attribute' => 'label'],
            ['label' => Craft::t('enquiries-by-roogul', 'Email'), 'orderBy' => 'enquiries_submissions.email', 'attribute' => 'email'],
            [
                'label' => Craft::t('app', 'Date Created'),
                'orderBy' => 'elements.dateCreated',
                'attribute' => 'dateCreated',
                'defaultDir' => 'desc',
            ],
            ['label' => Craft::t('app', 'ID'), 'orderBy' => 'elements.id', 'attribute' => 'id'],
        ];
    }

    protected static function defineTableAttributes(): array
    {
        $attributes = [
            'form' => ['label' => Craft::t('enquiries-by-roogul', 'Form')],
            'email' => ['label' => Craft::t('enquiries-by-roogul', 'Email')],
            'dateCreated' => ['label' => Craft::t('app', 'Date Created')],
            'status' => ['label' => Craft::t('app', 'Status')],
            'sync' => ['label' => Craft::t('enquiries-by-roogul', 'Delivery')],
            'referrer' => ['label' => Craft::t('enquiries-by-roogul', 'Page')],
            'ipAddress' => ['label' => Craft::t('enquiries-by-roogul', 'IP address')],
            'id' => ['label' => Craft::t('app', 'ID')],
        ];

        // A column for every field across all forms.
        foreach (Plugin::getInstance()->getForms()->getAllForms() as $form) {
            foreach ($form->fields as $field) {
                $key = 'field:' . $field['handle'];
                $attributes[$key] ??= ['label' => $field['label']];
            }
        }

        return $attributes;
    }

    protected static function defineDefaultTableAttributes(string $source): array
    {
        if (str_starts_with($source, 'form:')) {
            $form = Plugin::getInstance()->getForms()->getFormByUid(substr($source, 5));
            $fieldColumns = array_map(
                fn($f) => 'field:' . $f['handle'],
                array_slice(array_values(array_filter($form?->fields ?? [], fn($f) => $f['type'] !== Form::TYPE_TEXTAREA)), 0, 3),
            );

            return array_merge($fieldColumns, ['dateCreated', 'sync']);
        }

        return ['form', 'email', 'dateCreated', 'sync'];
    }

    protected function attributeHtml(string $attribute): string
    {
        if (str_starts_with($attribute, 'field:')) {
            return Html::encode(mb_strimwidth($this->valueAsString(substr($attribute, 6)), 0, 80, '…'));
        }

        switch ($attribute) {
            case 'form':
                return Html::encode($this->getForm()?->name ?? '');
            case 'email':
                return $this->email ? Html::a(Html::encode($this->email), 'mailto:' . $this->email) : '';
            case 'referrer':
                $url = $this->getSafeReferrer();

                if (!$url) {
                    return $this->referrer ? Html::encode(mb_strimwidth($this->referrer, 0, 50, '…')) : '';
                }

                $label = parse_url($url, PHP_URL_PATH) ?: $url;

                return Html::a(Html::encode(mb_strimwidth($label, 0, 50, '…')), $url, ['target' => '_blank', 'rel' => 'noopener noreferrer']);
            case 'ipAddress':
                return Html::encode((string)$this->ipAddress);
            case 'sync':
                return $this->syncSummaryHtml();
        }

        return parent::attributeHtml($attribute);
    }

    private function syncSummaryHtml(): string
    {
        $log = $this->getSyncLog();

        if (!$log) {
            return '';
        }

        $integrations = Plugin::getInstance()->getIntegrations();
        $html = '';

        foreach ($log as $handle => $row) {
            $integration = $integrations->getIntegration($handle);
            $name = $integration ? $integration::displayName() : $handle;
            $color = match ($row['status']) {
                Integrations::STATUS_SUCCESS => 'green',
                Integrations::STATUS_FAILED => 'red',
                Integrations::STATUS_PENDING => 'orange',
                default => 'disabled',
            };

            $html .= Html::tag('span', Html::tag('span', '', ['class' => ['status', $color]]) . Html::encode($name), [
                'title' => $row['message'],
                'style' => ['white-space' => 'nowrap', 'margin-inline-end' => '10px'],
            ]);
        }

        return $html;
    }
}

<?php

namespace roogul\enquiries\services;

use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\helpers\Db;
use craft\web\Request;
use DateTime;
use roogul\enquiries\elements\Submission;
use roogul\enquiries\migrations\Install;
use roogul\enquiries\models\Form;
use roogul\enquiries\Plugin;
use yii\base\Component;

class Submissions extends Component
{
    /**
     * Builds an unsaved submission from posted values. Only fields defined on
     * the form are kept; anything else in the request is ignored.
     */
    public function createFromRequest(Form $form, Request $request): Submission
    {
        $posted = $request->getBodyParam('fields', []);
        $posted = is_array($posted) ? $posted : [];
        $values = [];

        foreach ($form->fields as $field) {
            $values[$field['handle']] = $this->normaliseValue($posted[$field['handle']] ?? null, $field['type']);
        }

        $settings = Plugin::getInstance()->getSettings();

        $submission = new Submission();
        $submission->formId = $form->id;
        $submission->setValues($values);
        $submission->sourceSiteId = Craft::$app->getSites()->getCurrentSite()->id;
        $submission->userId = Craft::$app->getUser()->getId();
        $submission->referrer = $request->getReferrer();

        if ($settings->storeIpAddress) {
            $submission->ipAddress = $request->getUserIP();
            $submission->userAgent = $request->getUserAgent();
        }

        return $submission;
    }

    public function normaliseValue(mixed $value, string $type): mixed
    {
        if ($type === Form::TYPE_BOOLEAN) {
            if (is_array($value)) {
                $value = end($value);
            }
            return !in_array(strtolower(trim((string)$value)), ['', '0', 'false', 'no', 'off'], true);
        }

        if ($type === Form::TYPE_MULTI) {
            $items = is_array($value) ? $value : ($value === null || $value === '' ? [] : [$value]);
            return array_values(array_filter(array_map(fn($v) => $this->cleanString($v), $items), fn($v) => $v !== ''));
        }

        if (is_array($value)) {
            $value = implode(', ', array_filter(array_map(fn($v) => $this->cleanString($v), $value)));
        }

        $value = $this->cleanString($value);

        if ($type !== Form::TYPE_TEXTAREA) {
            $value = preg_replace('/\s+/u', ' ', $value);
        }

        return $value;
    }

    private function cleanString(mixed $value): string
    {
        if (!is_scalar($value)) {
            return '';
        }

        // Normalise line endings and strip control characters (keep tabs/newlines).
        $value = str_replace(["\r\n", "\r"], "\n", (string)$value);
        $value = preg_replace('/[^\P{C}\n\t]/u', '', $value) ?? '';

        return trim($value);
    }

    public function save(Submission $submission): bool
    {
        return Craft::$app->getElements()->saveElement($submission, false);
    }

    public function getSubmissionById(int $id): ?Submission
    {
        return Submission::find()->id($id)->status(null)->one();
    }

    public function getUnreadCount(): int
    {
        if (!Craft::$app->getDb()->tableExists(Install::SUBMISSIONS)) {
            return 0;
        }

        return (int)(new Query())
            ->from(['s' => Install::SUBMISSIONS])
            ->innerJoin(['e' => Table::ELEMENTS], '[[e.id]] = [[s.id]]')
            ->where(['s.submissionStatus' => Submission::STATUS_NEW, 'e.dateDeleted' => null])
            ->count();
    }

    /**
     * Deletes submissions past the retention windows. Runs during Craft's garbage collection.
     */
    public function purgeExpired(): void
    {
        $settings = Plugin::getInstance()->getSettings();

        if ($settings->retentionDays > 0) {
            $this->purge(null, $settings->retentionDays);
        }

        if ($settings->spamRetentionDays > 0) {
            $this->purge(Submission::STATUS_SPAM, $settings->spamRetentionDays);
        }
    }

    private function purge(?string $status, int $days): void
    {
        $cutoff = Db::prepareDateForDb(new DateTime("-$days days"));

        $query = (new Query())
            ->select('s.id')
            ->from(['s' => Install::SUBMISSIONS])
            ->where(['<', 's.dateCreated', $cutoff]);

        if ($status) {
            $query->andWhere(['s.submissionStatus' => $status]);
        }

        foreach (array_chunk($query->column(), 500) as $ids) {
            Db::delete(Table::ELEMENTS, ['id' => $ids, 'type' => Submission::class]);
        }
    }
}

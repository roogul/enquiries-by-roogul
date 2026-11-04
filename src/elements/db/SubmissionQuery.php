<?php

namespace roogul\enquiries\elements\db;

use craft\db\Query;
use craft\elements\db\ElementQuery;
use craft\helpers\Db;
use roogul\enquiries\elements\Submission;
use roogul\enquiries\migrations\Install;
use roogul\enquiries\Plugin;

/**
 * @method Submission[]|array all($db = null)
 * @method Submission|array|null one($db = null)
 */
class SubmissionQuery extends ElementQuery
{
    /** Spam is excluded unless you ask for it with `.status('spam')` or `.status(null)`. */
    public array|string|null $status = [Submission::STATUS_NEW, Submission::STATUS_READ];

    public mixed $formId = null;
    public mixed $email = null;

    /**
     * Narrows results to one or more forms, by handle or model.
     */
    public function form(mixed $value): static
    {
        if ($value === null) {
            $this->formId = null;
            return $this;
        }

        $forms = Plugin::getInstance()->getForms();
        $ids = [];

        foreach ((array)$value as $item) {
            if ($item instanceof \roogul\enquiries\models\Form) {
                $ids[] = $item->id;
            } elseif (is_numeric($item)) {
                $ids[] = (int)$item;
            } elseif (is_string($item) && ($form = $forms->getFormByHandle($item))) {
                $ids[] = $form->id;
            }
        }

        // An unknown handle should return nothing, not everything.
        $this->formId = $ids ?: [0];

        return $this;
    }

    public function formId(mixed $value): static
    {
        $this->formId = $value;
        return $this;
    }

    public function email(mixed $value): static
    {
        $this->email = $value;
        return $this;
    }

    protected function beforePrepare(): bool
    {
        $this->joinElementTable('enquiries_submissions');

        $this->query->addSelect([
            'enquiries_submissions.formId',
            'enquiries_submissions.submissionStatus',
            'enquiries_submissions.label',
            'enquiries_submissions.email',
            'enquiries_submissions.data',
            'enquiries_submissions.ipAddress',
            'enquiries_submissions.userAgent',
            'enquiries_submissions.referrer',
            'enquiries_submissions.userId',
            'enquiries_submissions.sourceSiteId',
        ]);

        if ($this->formId !== null) {
            $this->subQuery->andWhere(Db::parseNumericParam('enquiries_submissions.formId', $this->formId));
        }

        if ($this->email !== null) {
            $this->subQuery->andWhere(Db::parseParam('enquiries_submissions.email', $this->email, caseInsensitive: true));
        }

        return parent::beforePrepare();
    }

    protected function statusCondition(string $status): mixed
    {
        return match ($status) {
            Submission::STATUS_NEW, Submission::STATUS_READ, Submission::STATUS_SPAM => [
                'enquiries_submissions.submissionStatus' => $status,
            ],
            // Treat Craft's generic "enabled" as "not spam".
            'enabled' => ['enquiries_submissions.submissionStatus' => [Submission::STATUS_NEW, Submission::STATUS_READ]],
            default => false,
        };
    }
}

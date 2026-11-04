<?php

namespace roogul\enquiries\elements\actions;

use Craft;
use craft\base\ElementAction;
use craft\elements\db\ElementQueryInterface;
use roogul\enquiries\elements\Submission;

/**
 * Marks submissions as new, read or spam. Registered once per status.
 */
class SetSubmissionStatus extends ElementAction
{
    public string $status = Submission::STATUS_READ;

    public function getTriggerLabel(): string
    {
        return match ($this->status) {
            Submission::STATUS_NEW => Craft::t('enquiries-by-roogul', 'Mark as new'),
            Submission::STATUS_SPAM => Craft::t('enquiries-by-roogul', 'Mark as spam'),
            default => Craft::t('enquiries-by-roogul', 'Mark as read'),
        };
    }

    public function performAction(ElementQueryInterface $query): bool
    {
        $user = Craft::$app->getUser()->getIdentity();

        if (!$user?->can(Submission::PERMISSION_VIEW)) {
            $this->setMessage(Craft::t('app', 'You don’t have the required permissions for this action.'));
            return false;
        }

        $count = 0;

        /** @var Submission $submission */
        foreach ($query->status(null)->all() as $submission) {
            $submission->updateStatus($this->status);
            $count++;
        }

        $this->setMessage(Craft::t('enquiries-by-roogul', '{count, plural, =1{Enquiry} other{Enquiries}} updated.', ['count' => $count]));

        return true;
    }
}

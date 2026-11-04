<?php

namespace roogul\enquiries\elements\actions;

use Craft;
use craft\base\ElementAction;
use craft\elements\db\ElementQueryInterface;
use roogul\enquiries\elements\Submission;
use roogul\enquiries\Plugin;

/**
 * Re-queues every enabled integration for the selected submissions.
 */
class Resend extends ElementAction
{
    public function getTriggerLabel(): string
    {
        return Craft::t('enquiries-by-roogul', 'Resend to integrations');
    }

    public function getConfirmationMessage(): ?string
    {
        return Craft::t('enquiries-by-roogul', 'Send the selected enquiries to every enabled integration again? Notifications will go out again too.');
    }

    public function performAction(ElementQueryInterface $query): bool
    {
        $user = Craft::$app->getUser()->getIdentity();

        if (!$user?->can(Submission::PERMISSION_VIEW)) {
            $this->setMessage(Craft::t('app', 'You don’t have the required permissions for this action.'));
            return false;
        }

        $integrations = Plugin::getInstance()->getIntegrations();
        $jobs = 0;

        foreach ($query->all() as $submission) {
            $jobs += $integrations->queueSubmission($submission);
        }

        $this->setMessage(Craft::t('enquiries-by-roogul', '{count, plural, =0{Nothing to send. Spam isn’t resent.} =1{1 delivery queued.} other{# deliveries queued.}}', ['count' => $jobs]));

        return true;
    }
}

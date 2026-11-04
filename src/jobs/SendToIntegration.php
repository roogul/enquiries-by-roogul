<?php

namespace roogul\enquiries\jobs;

use Craft;
use craft\queue\BaseJob;
use roogul\enquiries\Plugin;

/**
 * Sends one submission to one integration. Retries are handled by the
 * Integrations service (it re-queues with a delay), so this job never throws
 * for integration failures and the queue stays clean.
 */
class SendToIntegration extends BaseJob
{
    public int $submissionId;
    public string $integration;
    public int $attempt = 1;

    public function execute($queue): void
    {
        Plugin::getInstance()->getIntegrations()->process($this->submissionId, $this->integration, $this->attempt);
    }

    protected function defaultDescription(): ?string
    {
        $integration = Plugin::getInstance()->getIntegrations()->getIntegration($this->integration);

        return Craft::t('enquiries-by-roogul', 'Sending enquiry #{id} to {name}', [
            'id' => $this->submissionId,
            'name' => $integration ? $integration::displayName() : $this->integration,
        ]);
    }
}

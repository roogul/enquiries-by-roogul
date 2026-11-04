<?php

namespace roogul\enquiries\services;

use Craft;
use craft\db\Query;
use craft\events\RegisterComponentTypesEvent;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\Queue as QueueHelper;
use roogul\enquiries\elements\Submission;
use roogul\enquiries\integrations\AutoReply;
use roogul\enquiries\integrations\BaseIntegration;
use roogul\enquiries\integrations\Brevo;
use roogul\enquiries\integrations\EmailNotification;
use roogul\enquiries\integrations\GoHighLevel;
use roogul\enquiries\integrations\IntegrationException;
use roogul\enquiries\integrations\Slack;
use roogul\enquiries\integrations\Webhook;
use roogul\enquiries\jobs\SendToIntegration;
use roogul\enquiries\migrations\Install;
use roogul\enquiries\Plugin;
use yii\base\Component;

/**
 * Registry of integration types, plus the code that queues, sends and logs
 * each submission's deliveries.
 */
class Integrations extends Component
{
    /**
     * Register extra integration types:
     *
     * ```php
     * Event::on(Integrations::class, Integrations::EVENT_REGISTER_INTEGRATION_TYPES, function(RegisterComponentTypesEvent $e) {
     *     $e->types[] = HubSpot::class;
     * });
     * ```
     */
    public const EVENT_REGISTER_INTEGRATION_TYPES = 'registerIntegrationTypes';

    public const STATUS_PENDING = 'pending';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    /** Delay (seconds) before each retry. */
    private const RETRY_DELAYS = [60, 300, 900, 3600];

    /** @var array<string, BaseIntegration>|null */
    private ?array $_integrations = null;

    /**
     * @return array<string, BaseIntegration> Keyed by handle, notifications first.
     */
    public function getAllIntegrations(): array
    {
        if ($this->_integrations !== null) {
            return $this->_integrations;
        }

        $event = new RegisterComponentTypesEvent([
            'types' => [
                EmailNotification::class,
                AutoReply::class,
                Slack::class,
                GoHighLevel::class,
                Brevo::class,
                Webhook::class,
            ],
        ]);
        $this->trigger(self::EVENT_REGISTER_INTEGRATION_TYPES, $event);

        $settings = Plugin::getInstance()->getSettings();
        $order = [BaseIntegration::CATEGORY_NOTIFICATION => 0, BaseIntegration::CATEGORY_CRM => 1, BaseIntegration::CATEGORY_OTHER => 2];
        $integrations = [];

        foreach ($event->types as $class) {
            if (!is_subclass_of($class, BaseIntegration::class)) {
                continue;
            }

            $integrations[$class::handle()] = new $class([
                'settings' => $settings->getIntegrationSettings($class::handle()),
            ]);
        }

        uasort($integrations, fn($a, $b) => ($order[$a::category()] ?? 3) <=> ($order[$b::category()] ?? 3));

        return $this->_integrations = $integrations;
    }

    public function getIntegration(string $handle): ?BaseIntegration
    {
        return $this->getAllIntegrations()[$handle] ?? null;
    }

    /** Drop cached instances, e.g. after plugin settings change. */
    public function reset(): void
    {
        $this->_integrations = null;
    }

    /**
     * Queue a submission's enabled integrations.
     *
     * @param string[]|null $only Limit to these integration handles (e.g. for a retry).
     * @return int Number of jobs queued.
     */
    public function queueSubmission(Submission $submission, ?array $only = null): int
    {
        $form = $submission->getForm();

        if (!$form || $submission->isSpam()) {
            return 0;
        }

        $count = 0;

        foreach ($this->getAllIntegrations() as $handle => $integration) {
            if ($only !== null && !in_array($handle, $only, true)) {
                continue;
            }

            if (!$form->isIntegrationEnabled($handle)) {
                continue;
            }

            $this->writeLog($submission->id, $handle, self::STATUS_PENDING, Craft::t('enquiries-by-roogul', 'Queued'), resetAttempts: true);

            QueueHelper::push(new SendToIntegration([
                'submissionId' => $submission->id,
                'integration' => $handle,
            ]));

            $count++;
        }

        return $count;
    }

    /**
     * Runs one integration for one submission and records the result.
     * Called from the queue job.
     */
    public function process(int $submissionId, string $handle, int $attempt = 1): void
    {
        $submission = Submission::find()->id($submissionId)->status(null)->one();

        if (!$submission) {
            return;
        }

        $form = $submission->getForm();
        $integration = $this->getIntegration($handle);

        if (!$form || !$integration) {
            $this->writeLog($submissionId, $handle, self::STATUS_FAILED, 'The form or integration no longer exists.');
            return;
        }

        $config = $form->getIntegrationConfig($handle);

        if (empty($config['enabled'])) {
            $this->writeLog($submissionId, $handle, self::STATUS_SKIPPED, Craft::t('enquiries-by-roogul', 'Skipped: turned off on this form.'));
            return;
        }

        if (!$integration->isConfigured()) {
            $this->writeLog($submissionId, $handle, self::STATUS_FAILED, Craft::t('enquiries-by-roogul', '{name} has no credentials. Add them in the plugin settings, then retry.', ['name' => $integration::displayName()]));
            return;
        }

        if ($reason = $integration->shouldSkip($submission, $config)) {
            $this->writeLog($submissionId, $handle, self::STATUS_SKIPPED, $reason);
            return;
        }

        $this->incrementAttempts($submissionId, $handle);

        try {
            $result = $integration->send($submission, $form, $config);
            $this->writeLog($submissionId, $handle, self::STATUS_SUCCESS, $result ?? Craft::t('enquiries-by-roogul', 'Sent'), remoteId: $result);
        } catch (IntegrationException $e) {
            $max = Plugin::getInstance()->getSettings()->maxAttempts;

            if ($e->retryable && $attempt < $max) {
                $delay = self::RETRY_DELAYS[$attempt - 1] ?? end(self::RETRY_DELAYS);

                $this->writeLog($submissionId, $handle, self::STATUS_PENDING, Craft::t('enquiries-by-roogul', 'Attempt {n} failed, retrying in {mins} min: {error}', [
                    'n' => $attempt,
                    'mins' => (int)ceil($delay / 60),
                    'error' => $e->getMessage(),
                ]));

                QueueHelper::push(new SendToIntegration([
                    'submissionId' => $submissionId,
                    'integration' => $handle,
                    'attempt' => $attempt + 1,
                ]), null, $delay);

                return;
            }

            Craft::warning("Enquiries: {$handle} failed for submission {$submissionId}: {$e->getMessage()}", __METHOD__);
            $this->writeLog($submissionId, $handle, self::STATUS_FAILED, $e->getMessage());
        } catch (\Throwable $e) {
            Craft::error("Enquiries: {$handle} errored for submission {$submissionId}: {$e->getMessage()}", __METHOD__);
            Craft::$app->getErrorHandler()->logException($e);
            $this->writeLog($submissionId, $handle, self::STATUS_FAILED, get_class($e) . ': ' . $e->getMessage());
        }
    }

    /**
     * @return array<string, array> Log rows keyed by integration handle.
     */
    public function getLog(int $submissionId): array
    {
        $rows = (new Query())
            ->from(Install::SYNC_LOG)
            ->where(['submissionId' => $submissionId])
            ->orderBy(['id' => SORT_ASC])
            ->all();

        $out = [];
        foreach ($rows as $row) {
            $row['dateCreated'] = DateTimeHelper::toDateTime($row['dateCreated']);
            $row['dateUpdated'] = DateTimeHelper::toDateTime($row['dateUpdated']);
            $out[$row['integration']] = $row;
        }

        return $out;
    }

    /**
     * @param int[] $submissionIds
     * @return array<int, array<string, string>> submissionId => [integration => status]
     */
    public function getStatusesForSubmissions(array $submissionIds): array
    {
        if (!$submissionIds) {
            return [];
        }

        $out = [];
        $rows = (new Query())
            ->select(['submissionId', 'integration', 'status'])
            ->from(Install::SYNC_LOG)
            ->where(['submissionId' => $submissionIds])
            ->all();

        foreach ($rows as $row) {
            $out[(int)$row['submissionId']][$row['integration']] = $row['status'];
        }

        return $out;
    }

    public function getFailedCount(): int
    {
        if (!Craft::$app->getDb()->tableExists(Install::SYNC_LOG)) {
            return 0;
        }

        return (int)(new Query())
            ->from(Install::SYNC_LOG)
            ->where(['status' => self::STATUS_FAILED])
            ->count();
    }

    private function incrementAttempts(int $submissionId, string $handle): void
    {
        Db::update(Install::SYNC_LOG, [
            'attempts' => new \yii\db\Expression('[[attempts]] + 1'),
        ], ['submissionId' => $submissionId, 'integration' => $handle], [], false);
    }

    private function writeLog(int $submissionId, string $handle, string $status, string $message, bool $resetAttempts = false, ?string $remoteId = null): void
    {
        $columns = [
            'status' => $status,
            'message' => mb_substr($message, 0, 5000),
        ];

        if ($remoteId !== null) {
            $columns['remoteId'] = mb_substr($remoteId, 0, 255);
        }

        if ($resetAttempts) {
            $columns['attempts'] = 0;
        }

        Db::upsert(Install::SYNC_LOG, [
            'submissionId' => $submissionId,
            'integration' => $handle,
        ] + $columns, $columns);
    }
}

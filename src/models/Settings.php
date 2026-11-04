<?php

namespace roogul\enquiries\models;

use craft\base\Model;
use craft\helpers\App;

/**
 * Global plugin settings. Stored in project config, so keep secrets in
 * environment variables (e.g. `$BREVO_API_KEY`) rather than plain text.
 */
class Settings extends Model
{
    /** Label shown in the CP nav. */
    public string $cpNavLabel = 'Enquiries';

    // Spam -----------------------------------------------------------------

    /** Name of the hidden honeypot input. Bots fill it, humans never see it. */
    public string $honeypotName = 'enq_website';

    /** Submissions faster than this (seconds after page render) are treated as spam. 0 disables. */
    public int $minSubmitSeconds = 3;

    /** Keep spam submissions (marked as spam, never sent anywhere) so you can check for false positives. */
    public bool $storeSpam = true;

    /** Cloudflare Turnstile keys. Enable Turnstile per form. */
    public string $turnstileSiteKey = '';
    public string $turnstileSecretKey = '';

    // Data -----------------------------------------------------------------

    /** Delete submissions older than this many days. 0 keeps them forever. */
    public int $retentionDays = 0;

    /** Delete spam older than this many days. 0 keeps it forever. */
    public int $spamRetentionDays = 30;

    /** Store the submitter's IP address and user agent. */
    public bool $storeIpAddress = true;

    // Delivery -------------------------------------------------------------

    /**
     * Run the queue straight after a submission's response has been sent, so
     * notifications and CRM syncs go out without waiting for a CP visit or a
     * queue runner. Turn off if you run a dedicated queue worker.
     */
    public bool $runQueueAfterSubmit = true;

    /** Number of attempts for an integration before it's marked as failed. */
    public int $maxAttempts = 3;

    /**
     * Global credentials for each integration, keyed by integration handle.
     *
     * @var array<string, array<string, mixed>>
     */
    public array $integrations = [];

    public function getTurnstileSiteKey(): string
    {
        return (string)App::parseEnv($this->turnstileSiteKey);
    }

    public function getTurnstileSecretKey(): string
    {
        return (string)App::parseEnv($this->turnstileSecretKey);
    }

    public function getIntegrationSettings(string $handle): array
    {
        return $this->integrations[$handle] ?? [];
    }

    protected function defineRules(): array
    {
        return [
            [['honeypotName', 'cpNavLabel'], 'required'],
            [['honeypotName'], 'match', 'pattern' => '/^[A-Za-z][A-Za-z0-9_\-]*$/'],
            [['minSubmitSeconds', 'retentionDays', 'spamRetentionDays'], 'integer', 'min' => 0],
            [['maxAttempts'], 'integer', 'min' => 1, 'max' => 10],
        ];
    }
}

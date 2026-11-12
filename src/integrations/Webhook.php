<?php

namespace roogul\enquiries\integrations;

use Craft;
use craft\helpers\App;
use craft\helpers\DateTimeHelper;
use craft\helpers\Json;
use roogul\enquiries\elements\Submission;
use roogul\enquiries\models\Form;

/**
 * POSTs the submission as JSON to any URL (Zapier, Make, n8n, your own app).
 * With a secret set, the body is signed: `X-Enquiries-Signature: sha256=<hmac>`.
 */
class Webhook extends BaseIntegration
{
    public static function handle(): string
    {
        return 'webhook';
    }

    public static function displayName(): string
    {
        return Craft::t('enquiries-by-roogul', 'Webhook');
    }

    public static function category(): string
    {
        return self::CATEGORY_OTHER;
    }

    public static function description(): string
    {
        return Craft::t('enquiries-by-roogul', 'POST the submission as JSON to Zapier, Make, n8n or your own endpoint.');
    }

    public function defaultFormConfig(Form $form): array
    {
        return [
            'url' => '',
            'secret' => '',
            'onlyIfField' => '',
        ];
    }

    public function validateFormConfig(Form $form, array $config): array
    {
        $errors = parent::validateFormConfig($form, $config);
        $url = trim((string)App::parseEnv((string)($config['url'] ?? '')));

        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            $errors[] = Craft::t('enquiries-by-roogul', 'Add a valid URL.');
        }

        return $errors;
    }

    public function send(Submission $submission, Form $form, array $config): ?string
    {
        $url = $this->parse($config['url'] ?? '');
        $secret = $this->parse($config['secret'] ?? '');

        $body = Json::encode([
            'event' => 'submission.created',
            'form' => ['handle' => $form->handle, 'name' => $form->name],
            'submission' => [
                'id' => $submission->id,
                'uid' => $submission->uid,
                'dateCreated' => DateTimeHelper::toIso8601($submission->dateCreated),
                'site' => Craft::$app->getSites()->getSiteById($submission->sourceSiteId ?? 0)?->handle,
                'referrer' => $submission->getSafeReferrer(),
                'cpUrl' => $submission->getCpEditUrl(),
            ],
            'fields' => $submission->getValues(),
        ]);

        $headers = ['Content-Type' => 'application/json', 'User-Agent' => 'Enquiries-by-ROOGUL'];

        if ($secret !== '') {
            $headers['X-Enquiries-Signature'] = 'sha256=' . hash_hmac('sha256', $body, $secret);
        }

        $response = $this->request('POST', $url, ['headers' => $headers, 'body' => $body]);

        return 'HTTP ' . $response['status'];
    }
}

<?php

namespace roogul\enquiries\integrations;

use Craft;
use craft\helpers\App;
use roogul\enquiries\elements\Submission;
use roogul\enquiries\models\Form;

/**
 * Posts the submission to a Slack channel via an incoming webhook.
 */
class Slack extends BaseIntegration
{
    public static function handle(): string
    {
        return 'slack';
    }

    public static function displayName(): string
    {
        return 'Slack';
    }

    public static function category(): string
    {
        return self::CATEGORY_NOTIFICATION;
    }

    public static function description(): string
    {
        return Craft::t('enquiries-by-roogul', 'Post each submission to a Slack channel.');
    }

    public function getSettingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('enquiries-by-roogul/integrations/_settings/slack', [
            'settings' => $this->settings,
        ]);
    }

    public function defaultFormConfig(Form $form): array
    {
        return [
            'webhookUrl' => '',
            'title' => 'New {form.name} enquiry',
            'onlyIfField' => '',
        ];
    }

    public function validateFormConfig(Form $form, array $config): array
    {
        $errors = [];

        $onlyIf = $config['onlyIfField'] ?? '';
        if ($onlyIf !== '' && !$form->getField($onlyIf)) {
            $errors[] = Craft::t('enquiries-by-roogul', '“{handle}” isn’t one of this form’s fields.', ['handle' => $onlyIf]);
        }

        // Check the raw values so an env var that's only set on production still validates locally.
        if (trim((string)($config['webhookUrl'] ?? '')) === '' && trim((string)($this->settings['webhookUrl'] ?? '')) === '') {
            $errors[] = Craft::t('enquiries-by-roogul', 'Add a webhook URL here or a default one in the plugin settings.');
        }

        return $errors;
    }

    public function send(Submission $submission, Form $form, array $config): ?string
    {
        $config = array_merge($this->defaultFormConfig($form), $config);
        $url = $this->webhookUrl($config);

        if ($url === '') {
            throw new IntegrationException('No Slack webhook URL configured.');
        }

        $title = $submission->renderObjectTemplate((string)$config['title']) ?: $form->name;
        $blocks = [
            [
                'type' => 'header',
                'text' => ['type' => 'plain_text', 'text' => mb_substr($title, 0, 150), 'emoji' => true],
            ],
        ];

        foreach ($submission->getDisplayValues() as $row) {
            if ($row['value'] === '') {
                continue;
            }

            if (count($blocks) >= 48) {
                break;
            }

            $blocks[] = [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => mb_substr('*' . self::escape($row['label']) . "*\n" . self::escape($row['value']), 0, 2900),
                ],
            ];
        }

        $context = [Craft::$app->getSites()->getSiteById($submission->sourceSiteId ?? 0)?->getName() ?? Craft::$app->getSystemName()];

        if ($cpUrl = $submission->getCpEditUrl()) {
            $context[] = '<' . $cpUrl . '|View in Craft>';
        }

        $blocks[] = [
            'type' => 'context',
            'elements' => [['type' => 'mrkdwn', 'text' => implode('  •  ', $context)]],
        ];

        $this->request('POST', $url, [
            'json' => [
                'text' => $title,
                'blocks' => $blocks,
            ],
        ]);

        return null;
    }

    private function webhookUrl(array $config): string
    {
        $url = trim((string)App::parseEnv((string)($config['webhookUrl'] ?? '')));

        return $url !== '' ? $url : (string)$this->setting('webhookUrl', '');
    }

    /** Slack needs &, < and > escaped in message text. */
    private static function escape(string $text): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);
    }
}

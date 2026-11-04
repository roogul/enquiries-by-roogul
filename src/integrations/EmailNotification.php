<?php

namespace roogul\enquiries\integrations;

use Craft;
use craft\helpers\App;
use craft\mail\Message;
use craft\web\View;
use roogul\enquiries\elements\Submission;
use roogul\enquiries\models\Form;

/**
 * Emails the submission to one or more recipients via Craft's mailer.
 */
class EmailNotification extends BaseIntegration
{
    public static function handle(): string
    {
        return 'email';
    }

    public static function displayName(): string
    {
        return Craft::t('enquiries-by-roogul', 'Email notification');
    }

    public static function category(): string
    {
        return self::CATEGORY_NOTIFICATION;
    }

    public static function description(): string
    {
        return Craft::t('enquiries-by-roogul', 'Email each submission to your team.');
    }

    public function defaultFormConfig(Form $form): array
    {
        return [
            'recipients' => '',
            'subject' => 'New {form.name} enquiry',
            'replyToField' => $form->getEmailFieldHandles()[0] ?? '',
            'template' => '',
            'onlyIfField' => '',
        ];
    }

    public function validateFormConfig(Form $form, array $config): array
    {
        $errors = parent::validateFormConfig($form, $config);

        if (trim((string)($config['recipients'] ?? '')) === '') {
            $errors[] = Craft::t('enquiries-by-roogul', 'Add at least one recipient.');
        }

        $replyTo = $config['replyToField'] ?? '';
        if ($replyTo !== '' && !$form->getField($replyTo)) {
            $errors[] = Craft::t('enquiries-by-roogul', '“{handle}” isn’t one of this form’s fields.', ['handle' => $replyTo]);
        }

        return $errors;
    }

    public function send(Submission $submission, Form $form, array $config): ?string
    {
        $config = array_merge($this->defaultFormConfig($form), $config);
        $recipients = self::splitList(App::parseEnv((string)$config['recipients']));

        if (!$recipients) {
            throw new IntegrationException('No recipients configured.');
        }

        $subject = $submission->renderObjectTemplate((string)$config['subject']) ?: $form->name;
        $html = $this->renderBody($submission, $form, $config);

        $message = (new Message())
            ->setTo($recipients)
            ->setSubject($subject)
            ->setHtmlBody($html);

        $replyToField = $config['replyToField'] ?? '';
        $replyTo = $replyToField !== '' ? $submission->valueAsString($replyToField) : '';

        if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            $message->setReplyTo($replyTo);
        }

        try {
            $sent = Craft::$app->getMailer()->send($message);
        } catch (\Throwable $e) {
            throw new IntegrationException('Mailer error: ' . $e->getMessage(), true, $e);
        }

        if (!$sent) {
            throw new IntegrationException('The mailer couldn’t send the email. Check Settings → Email.', true);
        }

        return Craft::t('enquiries-by-roogul', 'Sent to {to}', ['to' => implode(', ', $recipients)]);
    }

    private function renderBody(Submission $submission, Form $form, array $config): string
    {
        $template = trim((string)($config['template'] ?? ''));
        $variables = [
            'submission' => $submission,
            'form' => $form,
            'values' => $submission->getDisplayValues(),
            'cpUrl' => $submission->getCpEditUrl(),
        ];

        if ($template !== '') {
            return Craft::$app->getView()->renderTemplate($template, $variables, View::TEMPLATE_MODE_SITE);
        }

        return Craft::$app->getView()->renderTemplate('enquiries-by-roogul/_emails/notification', $variables, View::TEMPLATE_MODE_CP);
    }
}

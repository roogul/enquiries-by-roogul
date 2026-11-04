<?php

namespace roogul\enquiries\integrations;

use Craft;
use craft\helpers\App;
use craft\mail\Message;
use craft\web\View;
use roogul\enquiries\elements\Submission;
use roogul\enquiries\models\Form;

/**
 * Sends a confirmation email to the person who filled in the form.
 */
class AutoReply extends BaseIntegration
{
    public static function handle(): string
    {
        return 'autoReply';
    }

    public static function displayName(): string
    {
        return Craft::t('enquiries-by-roogul', 'Auto-reply');
    }

    public static function category(): string
    {
        return self::CATEGORY_NOTIFICATION;
    }

    public static function description(): string
    {
        return Craft::t('enquiries-by-roogul', 'Send a confirmation email to the person who submitted the form.');
    }

    public function defaultFormConfig(Form $form): array
    {
        return [
            'toField' => $form->getEmailFieldHandles()[0] ?? '',
            'subject' => 'Thanks for getting in touch',
            'body' => "Hi {name},\n\nThanks for your message. We’ve received it and will get back to you soon.\n\n{siteName}",
            'replyTo' => '',
            'template' => '',
            'onlyIfField' => '',
        ];
    }

    public function validateFormConfig(Form $form, array $config): array
    {
        $errors = parent::validateFormConfig($form, $config);
        $toField = $config['toField'] ?? '';

        if ($toField === '') {
            $errors[] = Craft::t('enquiries-by-roogul', 'Choose the field that holds the submitter’s email address.');
        } elseif (($form->getField($toField)['type'] ?? null) !== Form::TYPE_EMAIL) {
            $errors[] = Craft::t('enquiries-by-roogul', 'The recipient field must be an Email field.');
        }

        if (trim((string)($config['subject'] ?? '')) === '') {
            $errors[] = Craft::t('enquiries-by-roogul', 'Add a subject.');
        }

        return $errors;
    }

    public function shouldSkip(Submission $submission, array $config): ?string
    {
        if ($reason = parent::shouldSkip($submission, $config)) {
            return $reason;
        }

        $to = $submission->valueAsString($config['toField'] ?? '');

        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return Craft::t('enquiries-by-roogul', 'Skipped: no valid email address to reply to.');
        }

        return null;
    }

    public function send(Submission $submission, Form $form, array $config): ?string
    {
        $config = array_merge($this->defaultFormConfig($form), $config);
        $to = $submission->valueAsString($config['toField']);

        $message = (new Message())
            ->setTo($to)
            ->setSubject($submission->renderObjectTemplate((string)$config['subject']))
            ->setHtmlBody($this->renderBody($submission, $form, $config));

        $replyTo = trim((string)App::parseEnv((string)$config['replyTo']));
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

        return Craft::t('enquiries-by-roogul', 'Sent to {to}', ['to' => $to]);
    }

    private function renderBody(Submission $submission, Form $form, array $config): string
    {
        $template = trim((string)($config['template'] ?? ''));

        if ($template !== '') {
            return Craft::$app->getView()->renderTemplate($template, [
                'submission' => $submission,
                'form' => $form,
                'values' => $submission->getDisplayValues(),
            ], View::TEMPLATE_MODE_SITE);
        }

        // Submitted values are HTML-encoded before they're dropped into the
        // copy, so nobody can use the auto-reply to send their own markup.
        // The copy itself is plain text: blank lines become paragraphs.
        $text = $submission->renderObjectTemplate((string)$config['body'], true);
        $paragraphs = preg_split('/\R{2,}/', trim($text)) ?: [];
        $html = implode("\n", array_map(fn($p) => '<p>' . nl2br(trim($p), false) . '</p>', $paragraphs));

        return Craft::$app->getView()->renderTemplate('enquiries-by-roogul/_emails/auto-reply', [
            'body' => $html,
            'submission' => $submission,
            'form' => $form,
        ], View::TEMPLATE_MODE_CP);
    }
}

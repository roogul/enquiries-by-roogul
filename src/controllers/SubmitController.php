<?php

namespace roogul\enquiries\controllers;

use Craft;
use craft\web\Controller;
use craft\web\Response;
use roogul\enquiries\elements\Submission;
use roogul\enquiries\events\SubmissionEvent;
use roogul\enquiries\models\Form;
use roogul\enquiries\Plugin;
use yii\web\BadRequestHttpException;
use yii\web\Response as YiiResponse;

/**
 * Front-end form handler: POST to `enquiries-by-roogul/submit`.
 */
class SubmitController extends Controller
{
    public const EVENT_BEFORE_SUBMIT = 'beforeSubmit';
    public const EVENT_AFTER_SUBMIT = 'afterSubmit';

    /** Lets templates post to the shorter `enquiries-by-roogul/submit`. */
    public $defaultAction = 'submit';

    protected array|bool|int $allowAnonymous = ['submit'];

    public function actionSubmit(): ?YiiResponse
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $form = $this->resolveForm();
        $submission = $plugin->getSubmissions()->createFromRequest($form, $this->request);

        $event = new SubmissionEvent(['submission' => $submission, 'form' => $form]);

        // Silent checks first: bots get a normal success response.
        $spamReason = $plugin->getSpam()->check($form, $this->request);

        if (!$spamReason) {
            $submission->validateValues();

            if ($plugin->getSpam()->turnstileEnabled($form) && ($error = $plugin->getSpam()->verifyTurnstile($this->request))) {
                $submission->addError('turnstile', $error);
            }

            $this->trigger(self::EVENT_BEFORE_SUBMIT, $event);

            if ($event->isSpam) {
                $spamReason = 'event';
            } elseif (!$event->isValid || $submission->hasErrors()) {
                return $this->failure($form, $submission);
            }
        }

        if ($spamReason) {
            $submission->spamReason = $spamReason;
            Craft::info("Enquiries: {$form->handle} submission flagged as spam ({$spamReason})", __METHOD__);

            if ($plugin->getSettings()->storeSpam) {
                $submission->submissionStatus = Submission::STATUS_SPAM;
                $plugin->getSubmissions()->save($submission);
            }

            return $this->success($form, $submission);
        }

        if (!$plugin->getSubmissions()->save($submission)) {
            Craft::error('Enquiries: could not save submission: ' . print_r($submission->getErrors(), true), __METHOD__);
            $submission->addError('form', Craft::t('enquiries-by-roogul', 'Something went wrong sending your message. Please try again.'));
            return $this->failure($form, $submission);
        }

        $queued = $plugin->getIntegrations()->queueSubmission($submission);

        if ($queued && $plugin->getSettings()->runQueueAfterSubmit) {
            $this->runQueueAfterResponse();
        }

        $this->trigger(self::EVENT_AFTER_SUBMIT, $event);

        return $this->success($form, $submission);
    }

    private function resolveForm(): Form
    {
        $handle = (string)$this->request->getRequiredBodyParam('form');
        $form = Plugin::getInstance()->getForms()->getFormByHandle($handle);

        if (!$form) {
            throw new BadRequestHttpException('Unknown form.');
        }

        return $form;
    }

    private function success(Form $form, Submission $submission): YiiResponse
    {
        $message = $form->getSuccessMessage();

        if ($this->request->getAcceptsJson()) {
            return $this->asJson([
                'success' => true,
                'message' => $message,
                'form' => $form->handle,
            ]);
        }

        Craft::$app->getSession()->setFlash('enquiries.success.' . $form->handle, $message);

        $default = $form->redirect !== ''
            ? Craft::$app->getView()->renderObjectTemplate($form->redirect, $submission, ['id' => $submission->id])
            : $this->request->getUrl();

        return $this->redirectToPostedUrl($submission, $default);
    }

    private function failure(Form $form, Submission $submission): ?YiiResponse
    {
        $message = Craft::t('enquiries-by-roogul', 'Please check the form and try again.');

        if ($this->request->getAcceptsJson()) {
            return $this->asJson([
                'success' => false,
                'message' => $message,
                'form' => $form->handle,
                'errors' => $submission->getErrors(),
            ])->setStatusCode(400);
        }

        // Re-render the page with the submission available as `enquiry`.
        Craft::$app->getUrlManager()->setRouteParams([
            'enquiry' => $submission,
        ]);

        return null;
    }

    /**
     * Sends the response to the browser, then works through the queue so
     * notifications and CRM syncs go out straight away.
     */
    private function runQueueAfterResponse(): void
    {
        Craft::$app->getResponse()->on(Response::EVENT_AFTER_SEND, function() {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }

            $queue = Craft::$app->getQueue();

            if ($queue instanceof \craft\queue\Queue) {
                try {
                    $queue->run();
                } catch (\Throwable $e) {
                    Craft::error('Enquiries: queue run after submit failed: ' . $e->getMessage(), __METHOD__);
                }
            }
        });
    }
}

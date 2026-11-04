<?php

namespace roogul\enquiries\controllers;

use Craft;
use craft\web\Controller;
use roogul\enquiries\elements\Submission;
use roogul\enquiries\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * CP: browse and act on submissions.
 */
class SubmissionsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission(Submission::PERMISSION_VIEW);

        return true;
    }

    public function actionIndex(): Response
    {
        return $this->renderTemplate('enquiries-by-roogul/submissions/index', [
            'failedCount' => Plugin::getInstance()->getIntegrations()->getFailedCount(),
        ]);
    }

    public function actionView(int $submissionId): Response
    {
        $submission = $this->findSubmission($submissionId);

        if ($submission->submissionStatus === Submission::STATUS_NEW) {
            $submission->updateStatus(Submission::STATUS_READ);
        }

        $plugin = Plugin::getInstance();

        return $this->renderTemplate('enquiries-by-roogul/submissions/_view', [
            'submission' => $submission,
            'form' => $submission->getForm(),
            'log' => $submission->getSyncLog(),
            'integrations' => $plugin->getIntegrations()->getAllIntegrations(),
            'site' => Craft::$app->getSites()->getSiteById($submission->sourceSiteId ?? 0),
            'user' => $submission->userId ? Craft::$app->getUsers()->getUserById($submission->userId) : null,
            'canDelete' => Craft::$app->getUser()->checkPermission(Submission::PERMISSION_DELETE),
        ]);
    }

    public function actionResend(): Response
    {
        $this->requirePostRequest();

        $submission = $this->findSubmission((int)$this->request->getRequiredBodyParam('id'));
        $integration = $this->request->getBodyParam('integration');

        if ($submission->isSpam()) {
            $submission->updateStatus(Submission::STATUS_READ);
        }

        $count = Plugin::getInstance()->getIntegrations()->queueSubmission($submission, $integration ? [$integration] : null);

        if ($count && Plugin::getInstance()->getSettings()->runQueueAfterSubmit) {
            Craft::$app->getQueue()->run();
        }

        return $this->asSuccess(
            $count
                ? Craft::t('enquiries-by-roogul', '{count, plural, =1{Delivery} other{# deliveries}} queued.', ['count' => $count])
                : Craft::t('enquiries-by-roogul', 'No enabled integrations to send to.'),
            redirect: $submission->getCpEditUrl(),
        );
    }

    public function actionSetStatus(): Response
    {
        $this->requirePostRequest();

        $submission = $this->findSubmission((int)$this->request->getRequiredBodyParam('id'));
        $status = $this->request->getRequiredBodyParam('status');

        if (!array_key_exists($status, Submission::statuses())) {
            return $this->asFailure('Invalid status.');
        }

        $submission->updateStatus($status);

        return $this->asSuccess(Craft::t('enquiries-by-roogul', 'Enquiry updated.'), redirect: $submission->getCpEditUrl());
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Submission::PERMISSION_DELETE);

        $submission = $this->findSubmission((int)$this->request->getRequiredBodyParam('id'));
        Craft::$app->getElements()->deleteElement($submission);

        return $this->asSuccess(Craft::t('enquiries-by-roogul', 'Enquiry deleted.'), redirect: 'enquiries-by-roogul');
    }

    private function findSubmission(int $id): Submission
    {
        $submission = Plugin::getInstance()->getSubmissions()->getSubmissionById($id);

        if (!$submission) {
            throw new NotFoundHttpException('Enquiry not found');
        }

        return $submission;
    }
}

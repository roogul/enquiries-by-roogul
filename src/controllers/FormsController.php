<?php

namespace roogul\enquiries\controllers;

use Craft;
use craft\web\Controller;
use roogul\enquiries\models\Form;
use roogul\enquiries\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * CP: create and edit forms. Saved to project config.
 */
class FormsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        // Forms live in project config, so they're admin-only like sections.
        $this->requireAdmin(false);

        return true;
    }

    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();

        return $this->renderTemplate('enquiries-by-roogul/forms/index', [
            'forms' => $plugin->getForms()->getAllForms(),
            'integrations' => $plugin->getIntegrations()->getAllIntegrations(),
            'readOnly' => !Craft::$app->getConfig()->getGeneral()->allowAdminChanges,
        ]);
    }

    public function actionEdit(?string $handle = null, ?Form $form = null): Response
    {
        $plugin = Plugin::getInstance();

        if (!$form) {
            if ($handle) {
                $form = $plugin->getForms()->getFormByHandle($handle);
                if (!$form) {
                    throw new NotFoundHttpException('Form not found');
                }
            } else {
                $form = new Form();
                $form->fields = [
                    ['handle' => 'name', 'label' => 'Name', 'type' => Form::TYPE_TEXT, 'required' => true],
                    ['handle' => 'email', 'label' => 'Email', 'type' => Form::TYPE_EMAIL, 'required' => true],
                    ['handle' => 'phone', 'label' => 'Phone', 'type' => Form::TYPE_PHONE, 'required' => false],
                    ['handle' => 'message', 'label' => 'Message', 'type' => Form::TYPE_TEXTAREA, 'required' => true],
                ];
            }
        }

        return $this->renderTemplate('enquiries-by-roogul/forms/_edit', [
            'form' => $form,
            'isNew' => !$form->id,
            'integrations' => $plugin->getIntegrations()->getAllIntegrations(),
            'fieldTypes' => Form::fieldTypes(),
            'readOnly' => !Craft::$app->getConfig()->getGeneral()->allowAdminChanges,
            'turnstileReady' => $plugin->getSettings()->getTurnstileSiteKey() !== '',
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $this->requireAdmin();

        $plugin = Plugin::getInstance();
        $uid = $this->request->getBodyParam('uid');
        $existing = $uid ? $plugin->getForms()->getFormByUid($uid) : null;

        $form = new Form();
        $form->id = $existing?->id;
        $form->uid = $existing?->uid;
        $form->name = trim((string)$this->request->getBodyParam('name'));
        $form->handle = trim((string)$this->request->getBodyParam('handle'));
        $form->fields = Form::normaliseFields($this->request->getBodyParam('fields', []));
        $form->titleFormat = trim((string)$this->request->getBodyParam('titleFormat'));
        $form->successMessage = trim((string)$this->request->getBodyParam('formSuccessMessage'));
        $form->redirect = trim((string)$this->request->getBodyParam('formRedirect'));
        $form->turnstile = (bool)$this->request->getBodyParam('turnstile');
        $form->integrations = $this->normaliseIntegrations((array)$this->request->getBodyParam('integrations', []));

        if (!$plugin->getForms()->saveForm($form)) {
            return $this->asModelFailure($form, Craft::t('enquiries-by-roogul', 'Couldn’t save the form.'), 'form');
        }

        return $this->asModelSuccess($form, Craft::t('enquiries-by-roogul', 'Form saved.'), 'form', [], 'enquiries-by-roogul/forms');
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requireAdmin();

        $id = (int)$this->request->getRequiredBodyParam('id');
        $form = Plugin::getInstance()->getForms()->getFormById($id);

        if ($form) {
            Plugin::getInstance()->getForms()->deleteForm($form);
        }

        return $this->asSuccess(Craft::t('enquiries-by-roogul', 'Form deleted.'));
    }

    /**
     * Tidies posted integration settings: casts lightswitches, drops blank
     * mapping rows and strips keys the integration doesn't know about.
     */
    private function normaliseIntegrations(array $posted): array
    {
        $out = [];
        $integrations = Plugin::getInstance()->getIntegrations();

        foreach ($posted as $handle => $config) {
            if (!is_array($config) || !$integrations->getIntegration($handle)) {
                continue;
            }

            $clean = ['enabled' => !empty($config['enabled'])];

            foreach ($config as $key => $value) {
                if ($key === 'enabled') {
                    continue;
                }

                if (is_array($value)) {
                    // Editable tables: keep rows with at least one value.
                    $value = array_values(array_filter(
                        array_map(fn($row) => is_array($row) ? array_map(fn($v) => is_string($v) ? trim($v) : $v, $row) : $row, $value),
                        fn($row) => !is_array($row) || array_filter($row, fn($v) => $v !== '' && $v !== null),
                    ));
                } elseif (is_string($value)) {
                    $value = trim($value);
                }

                $clean[$key] = $value;
            }

            $out[$handle] = $integrations->getIntegration($handle)->normaliseFormConfig($clean);
        }

        return $out;
    }
}

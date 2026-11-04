<?php

namespace roogul\enquiries\services;

use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\events\ConfigEvent;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use roogul\enquiries\elements\Submission;
use roogul\enquiries\migrations\Install;
use roogul\enquiries\models\Form;
use yii\base\Component;

/**
 * Forms live in project config (`enquiriesByRoogul.forms.<uid>`) and are
 * mirrored into a DB table so submissions can reference them by ID.
 */
class Forms extends Component
{
    public const CONFIG_KEY = 'enquiriesByRoogul.forms';

    /** @var Form[]|null */
    private ?array $_forms = null;

    /**
     * @return Form[]
     */
    public function getAllForms(): array
    {
        if ($this->_forms !== null) {
            return $this->_forms;
        }

        $this->_forms = [];

        if (!Craft::$app->getDb()->tableExists(Install::FORMS)) {
            return $this->_forms;
        }

        $rows = (new Query())
            ->select(['id', 'uid', 'name', 'handle', 'settings'])
            ->from(Install::FORMS)
            ->orderBy(['name' => SORT_ASC])
            ->all();

        foreach ($rows as $row) {
            $this->_forms[] = $this->createForm($row);
        }

        return $this->_forms;
    }

    public function getFormById(int $id): ?Form
    {
        foreach ($this->getAllForms() as $form) {
            if ($form->id === $id) {
                return $form;
            }
        }

        return null;
    }

    public function getFormByHandle(string $handle): ?Form
    {
        foreach ($this->getAllForms() as $form) {
            if ($form->handle === $handle) {
                return $form;
            }
        }

        return null;
    }

    public function getFormByUid(string $uid): ?Form
    {
        foreach ($this->getAllForms() as $form) {
            if ($form->uid === $uid) {
                return $form;
            }
        }

        return null;
    }

    public function saveForm(Form $form, bool $runValidation = true): bool
    {
        if ($runValidation && !$form->validate()) {
            return false;
        }

        if (!$form->uid) {
            $form->uid = StringHelper::UUID();
        }

        Craft::$app->getProjectConfig()->set(
            self::CONFIG_KEY . '.' . $form->uid,
            $form->getConfig(),
            "Save Enquiries form “{$form->handle}”",
        );

        // Pick up the ID the project config handler assigned.
        $this->_forms = null;
        $form->id = $this->getFormByUid($form->uid)?->id;

        return true;
    }

    public function deleteForm(Form $form): bool
    {
        Craft::$app->getProjectConfig()->remove(
            self::CONFIG_KEY . '.' . $form->uid,
            "Delete Enquiries form “{$form->handle}”",
        );

        $this->_forms = null;

        return true;
    }

    // Project config handlers --------------------------------------------

    public function handleChangedForm(ConfigEvent $event): void
    {
        $uid = $event->tokenMatches[0];
        $data = $event->newValue;
        $db = Craft::$app->getDb();

        $id = (new Query())->select('id')->from(Install::FORMS)->where(['uid' => $uid])->scalar();

        $settings = $data;
        unset($settings['name'], $settings['handle']);

        $columns = [
            'name' => $data['name'],
            'handle' => $data['handle'],
            'settings' => Json::encode($settings),
        ];

        if ($id) {
            Db::update(Install::FORMS, $columns, ['id' => $id]);
        } else {
            Db::insert(Install::FORMS, $columns + ['uid' => $uid]);
        }

        $this->_forms = null;
    }

    public function handleDeletedForm(ConfigEvent $event): void
    {
        $uid = $event->tokenMatches[0];
        $id = (new Query())->select('id')->from(Install::FORMS)->where(['uid' => $uid])->scalar();

        if (!$id) {
            return;
        }

        // Hard-delete the form's submissions from the elements table; the
        // submissions + sync log rows cascade from there.
        $submissionIds = (new Query())
            ->select('id')
            ->from(Install::SUBMISSIONS)
            ->where(['formId' => $id])
            ->column();

        foreach (array_chunk($submissionIds, 500) as $chunk) {
            Db::delete(Table::ELEMENTS, ['id' => $chunk, 'type' => Submission::class]);
        }

        Db::delete(Install::FORMS, ['id' => $id]);
        $this->_forms = null;
    }

    private function createForm(array $row): Form
    {
        $settings = Json::decodeIfJson($row['settings'] ?? '') ?: [];

        $form = new Form();
        $form->id = (int)$row['id'];
        $form->uid = $row['uid'];
        $form->name = $row['name'];
        $form->handle = $row['handle'];
        $form->fields = Form::normaliseFields($settings['fields'] ?? []);
        $form->titleFormat = (string)($settings['titleFormat'] ?? '');
        $form->successMessage = (string)($settings['successMessage'] ?? '');
        $form->redirect = (string)($settings['redirect'] ?? '');
        $form->turnstile = (bool)($settings['turnstile'] ?? false);
        $form->integrations = is_array($settings['integrations'] ?? null) ? $settings['integrations'] : [];

        return $form;
    }
}

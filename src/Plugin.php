<?php

namespace roogul\enquiries;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\events\RebuildConfigEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\Elements;
use craft\services\Gc;
use craft\services\ProjectConfig;
use craft\services\UserPermissions;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use roogul\enquiries\elements\Submission;
use roogul\enquiries\models\Settings;
use roogul\enquiries\services\Forms;
use roogul\enquiries\services\Integrations;
use roogul\enquiries\services\Spam;
use roogul\enquiries\services\Submissions;
use roogul\enquiries\variables\EnquiriesVariable;
use yii\base\Event;

/**
 * Enquiries by ROOGUL
 *
 * Handles contact forms you build in your own templates: validation, spam
 * protection, storage, notifications and CRM syncing.
 *
 * @property-read Forms $forms
 * @property-read Submissions $submissions
 * @property-read Integrations $integrations
 * @property-read Spam $spam
 * @method static Plugin getInstance()
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;
    public bool $hasCpSection = true;

    public static function config(): array
    {
        return [
            'components' => [
                'forms' => Forms::class,
                'submissions' => Submissions::class,
                'integrations' => Integrations::class,
                'spam' => Spam::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        $this->registerElementType();
        $this->registerProjectConfigHandlers();
        $this->registerPermissions();
        $this->registerVariable();
        $this->registerCpRoutes();
        $this->registerGarbageCollection();
    }

    public function getForms(): Forms
    {
        return $this->get('forms');
    }

    public function getSubmissions(): Submissions
    {
        return $this->get('submissions');
    }

    public function getIntegrations(): Integrations
    {
        return $this->get('integrations');
    }

    public function getSpam(): Spam
    {
        return $this->get('spam');
    }

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $item['label'] = Craft::t('site', $this->getSettings()->cpNavLabel);

        $user = Craft::$app->getUser();
        $unread = $this->getSubmissions()->getUnreadCount();

        if ($unread) {
            $item['badgeCount'] = $unread;
        }

        $item['subnav'] = [];

        if ($user->checkPermission(Submission::PERMISSION_VIEW)) {
            $item['subnav']['submissions'] = [
                'label' => Craft::t('enquiries-by-roogul', 'Submissions'),
                'url' => 'enquiries-by-roogul',
            ];
        }

        if ($user->getIsAdmin()) {
            $item['subnav']['forms'] = [
                'label' => Craft::t('enquiries-by-roogul', 'Forms'),
                'url' => 'enquiries-by-roogul/forms',
            ];
        }

        if ($user->getIsAdmin() && Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
            $item['subnav']['settings'] = [
                'label' => Craft::t('enquiries-by-roogul', 'Settings'),
                'url' => 'settings/plugins/' . $this->handle,
            ];
        }

        return $item;
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('enquiries-by-roogul/settings', [
            'settings' => $this->getSettings(),
            'integrations' => $this->getIntegrations()->getAllIntegrations(),
        ]);
    }

    public function afterSaveSettings(): void
    {
        parent::afterSaveSettings();
        $this->getIntegrations()->reset();
    }

    private function registerElementType(): void
    {
        Craft::setAlias('@roogul/enquiries', __DIR__);

        Event::on(Elements::class, Elements::EVENT_REGISTER_ELEMENT_TYPES, function(RegisterComponentTypesEvent $event) {
            $event->types[] = Submission::class;
        });
    }

    private function registerProjectConfigHandlers(): void
    {
        $forms = $this->getForms();

        Craft::$app->getProjectConfig()
            ->onAdd(Forms::CONFIG_KEY . '.{uid}', [$forms, 'handleChangedForm'])
            ->onUpdate(Forms::CONFIG_KEY . '.{uid}', [$forms, 'handleChangedForm'])
            ->onRemove(Forms::CONFIG_KEY . '.{uid}', [$forms, 'handleDeletedForm']);

        Event::on(ProjectConfig::class, ProjectConfig::EVENT_REBUILD, function(RebuildConfigEvent $event) {
            foreach ($this->getForms()->getAllForms() as $form) {
                $event->config['enquiriesByRoogul']['forms'][$form->uid] = $form->getConfig();
            }
        });
    }

    private function registerPermissions(): void
    {
        Event::on(UserPermissions::class, UserPermissions::EVENT_REGISTER_PERMISSIONS, function(RegisterUserPermissionsEvent $event) {
            $event->permissions[] = [
                'heading' => Craft::t('enquiries-by-roogul', 'Enquiries by ROOGUL'),
                'permissions' => [
                    Submission::PERMISSION_VIEW => [
                        'label' => Craft::t('enquiries-by-roogul', 'View submissions'),
                        'nested' => [
                            Submission::PERMISSION_DELETE => [
                                'label' => Craft::t('enquiries-by-roogul', 'Delete submissions'),
                            ],
                        ],
                    ],
                ],
            ];
        });
    }

    private function registerVariable(): void
    {
        Event::on(CraftVariable::class, CraftVariable::EVENT_INIT, function(Event $event) {
            /** @var CraftVariable $variable */
            $variable = $event->sender;
            $variable->set('enquiries', EnquiriesVariable::class);
        });
    }

    private function registerCpRoutes(): void
    {
        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES, function(RegisterUrlRulesEvent $event) {
            $event->rules['enquiries-by-roogul'] = 'enquiries-by-roogul/submissions/index';
            $event->rules['enquiries-by-roogul/submissions/<submissionId:\d+>'] = 'enquiries-by-roogul/submissions/view';
            $event->rules['enquiries-by-roogul/forms'] = 'enquiries-by-roogul/forms/index';
            $event->rules['enquiries-by-roogul/forms/new'] = 'enquiries-by-roogul/forms/edit';
            $event->rules['enquiries-by-roogul/forms/<handle:{handle}>'] = 'enquiries-by-roogul/forms/edit';
        });
    }

    private function registerGarbageCollection(): void
    {
        Event::on(Gc::class, Gc::EVENT_RUN, function() {
            $this->getSubmissions()->purgeExpired();
        });
    }
}

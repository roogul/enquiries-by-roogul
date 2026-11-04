<?php

namespace roogul\enquiries\migrations;

use craft\db\Migration;
use craft\db\Table;

class Install extends Migration
{
    public const FORMS = '{{%enquiries_forms}}';
    public const SUBMISSIONS = '{{%enquiries_submissions}}';
    public const SYNC_LOG = '{{%enquiries_sync_log}}';

    public function safeUp(): bool
    {
        if (!$this->db->tableExists(self::FORMS)) {
            $this->createTable(self::FORMS, [
                'id' => $this->primaryKey(),
                'name' => $this->string()->notNull(),
                'handle' => $this->string()->notNull(),
                'settings' => $this->text(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->createIndex(null, self::FORMS, ['handle'], true);
            $this->createIndex(null, self::FORMS, ['uid'], true);
        }

        if (!$this->db->tableExists(self::SUBMISSIONS)) {
            $this->createTable(self::SUBMISSIONS, [
                'id' => $this->integer()->notNull(),
                'formId' => $this->integer()->notNull(),
                'submissionStatus' => $this->string(20)->notNull()->defaultValue('new'),
                'label' => $this->string(),
                'email' => $this->string(),
                'data' => $this->text(),
                'ipAddress' => $this->string(45),
                'userAgent' => $this->string(),
                'referrer' => $this->string(2048),
                'userId' => $this->integer(),
                'sourceSiteId' => $this->integer(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
                'PRIMARY KEY([[id]])',
            ]);
            $this->createIndex(null, self::SUBMISSIONS, ['formId']);
            $this->createIndex(null, self::SUBMISSIONS, ['submissionStatus']);
            $this->createIndex(null, self::SUBMISSIONS, ['email']);
            $this->addForeignKey(null, self::SUBMISSIONS, ['id'], Table::ELEMENTS, ['id'], 'CASCADE');
            $this->addForeignKey(null, self::SUBMISSIONS, ['formId'], self::FORMS, ['id'], 'CASCADE');
            $this->addForeignKey(null, self::SUBMISSIONS, ['userId'], Table::USERS, ['id'], 'SET NULL');
            $this->addForeignKey(null, self::SUBMISSIONS, ['sourceSiteId'], Table::SITES, ['id'], 'SET NULL');
        }

        if (!$this->db->tableExists(self::SYNC_LOG)) {
            $this->createTable(self::SYNC_LOG, [
                'id' => $this->primaryKey(),
                'submissionId' => $this->integer()->notNull(),
                'integration' => $this->string(64)->notNull(),
                'status' => $this->string(20)->notNull()->defaultValue('pending'),
                'attempts' => $this->integer()->notNull()->defaultValue(0),
                'message' => $this->text(),
                'remoteId' => $this->string(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);
            $this->createIndex(null, self::SYNC_LOG, ['submissionId', 'integration'], true);
            $this->createIndex(null, self::SYNC_LOG, ['status']);
            $this->addForeignKey(null, self::SYNC_LOG, ['submissionId'], self::SUBMISSIONS, ['id'], 'CASCADE');
        }

        // Reinstalling, or project config arrived before the plugin: rebuild
        // the forms table from any form config that's already there.
        $forms = \roogul\enquiries\Plugin::getInstance()?->getForms();
        $existing = \Craft::$app->getProjectConfig()->get(\roogul\enquiries\services\Forms::CONFIG_KEY) ?? [];

        if ($forms && is_array($existing)) {
            foreach ($existing as $uid => $config) {
                $forms->handleChangedForm(new \craft\events\ConfigEvent([
                    'path' => \roogul\enquiries\services\Forms::CONFIG_KEY . '.' . $uid,
                    'tokenMatches' => [$uid],
                    'newValue' => $config,
                ]));
            }
        }

        return true;
    }

    public function safeDown(): bool
    {
        // Remove submission elements first so nothing is left orphaned in the elements table.
        if ($this->db->tableExists(self::SUBMISSIONS)) {
            $this->delete(Table::ELEMENTS, ['type' => \roogul\enquiries\elements\Submission::class]);
        }

        $this->dropTableIfExists(self::SYNC_LOG);
        $this->dropTableIfExists(self::SUBMISSIONS);
        $this->dropTableIfExists(self::FORMS);

        return true;
    }
}

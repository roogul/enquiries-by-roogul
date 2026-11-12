<?php

namespace roogul\enquiries\migrations;

use craft\db\Migration;
use craft\db\Query;
use roogul\enquiries\helpers\Urls;

/**
 * Drops referrers that are not http(s) URLs. Rows saved before the scheme
 * check can hold a javascript: (or other) URL from the Referer header.
 */
class m261112_120000_sanitize_referrers extends Migration
{
    public function safeUp(): bool
    {
        $lastId = 0;

        while (true) {
            $rows = (new Query())
                ->select(['id', 'referrer'])
                ->from(Install::SUBMISSIONS)
                ->where(['not', ['referrer' => null]])
                ->andWhere(['>', 'id', $lastId])
                ->orderBy(['id' => SORT_ASC])
                ->limit(500)
                ->all($this->db);

            if (!$rows) {
                break;
            }

            foreach ($rows as $row) {
                $lastId = (int)$row['id'];
                $safe = Urls::httpUrl($row['referrer']);

                if ($safe !== null) {
                    $safe = mb_substr($safe, 0, 2048);
                }

                if ($safe !== $row['referrer']) {
                    $this->update(Install::SUBMISSIONS, ['referrer' => $safe], ['id' => $row['id']]);
                }
            }
        }

        return true;
    }

    public function safeDown(): bool
    {
        echo "m261112_120000_sanitize_referrers cannot be reverted.\n";

        return false;
    }
}

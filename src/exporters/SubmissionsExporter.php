<?php

namespace roogul\enquiries\exporters;

use Craft;
use craft\base\ElementExporter;
use craft\elements\db\ElementQueryInterface;
use craft\helpers\DateTimeHelper;
use roogul\enquiries\elements\Submission;

/**
 * Flattens submissions into one column per field, so CSV/Excel exports are usable.
 */
class SubmissionsExporter extends ElementExporter
{
    public static function displayName(): string
    {
        return Craft::t('enquiries-by-roogul', 'Enquiries (one column per field)');
    }

    public function export(ElementQueryInterface $query): mixed
    {
        $rows = [];
        $columns = [];

        /** @var Submission $submission */
        foreach ($query->each() as $submission) {
            $row = [
                'ID' => $submission->id,
                'Form' => $submission->getForm()?->name,
                'Status' => $submission->submissionStatus,
                'Date' => DateTimeHelper::toIso8601($submission->dateCreated),
            ];

            foreach ($submission->getDisplayValues() as $value) {
                $row[$value['label']] = $value['value'];
                $columns[$value['label']] = true;
            }

            $row['Page'] = $submission->referrer;
            $rows[] = $row;
        }

        // Give every row the same columns, in a stable order.
        $template = array_fill_keys(array_merge(['ID', 'Form', 'Status', 'Date'], array_keys($columns), ['Page']), '');

        return array_map(fn($row) => array_merge($template, $row), $rows);
    }
}

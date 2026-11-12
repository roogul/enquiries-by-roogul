<?php

namespace roogul\enquiries\helpers;

/**
 * Values written into CSV / Excel exports.
 */
class Spreadsheet
{
    /**
     * Spreadsheets treat a leading =, +, - or @ as a formula. Prefix those
     * cells so a submitted value is shown as text when the file is opened.
     */
    public static function cell(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_array($value)) {
            $value = implode(', ', $value);
        } elseif (is_bool($value)) {
            $value = $value ? '1' : '0';
        }

        $value = (string)$value;

        if (preg_match('/\A[\t\r\n ]*[=+@-]/', $value) === 1) {
            return "'" . $value;
        }

        return $value;
    }
}

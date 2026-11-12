<?php

namespace roogul\enquiries\helpers;

/**
 * Decides which strings are safe to store as a page URL and to put in an href.
 */
class Urls
{
    /**
     * An absolute http or https URL with a host, or null.
     *
     * javascript:, data:, and any other scheme are rejected. So are whitespace,
     * control characters, and quotes, which can change the scheme a browser
     * applies or break out of an attribute.
     */
    public static function httpUrl(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        $url = trim($url);

        if ($url === '') {
            return null;
        }

        if (preg_match('/[<>"`\\\\]/', $url) === 1 || preg_match('/\s/u', $url) === 1 || preg_match('/\p{C}/u', $url) !== 0) {
            return null;
        }

        if (preg_match('#\Ahttps?://#i', $url) !== 1) {
            return null;
        }

        $parts = parse_url($url);

        if (!is_array($parts)) {
            return null;
        }

        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = (string)($parts['host'] ?? '');

        if (($scheme !== 'http' && $scheme !== 'https') || $host === '' || preg_match('/[\\\\@]/', $host) === 1) {
            return null;
        }

        return $url;
    }
}

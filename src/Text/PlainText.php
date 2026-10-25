<?php

declare(strict_types=1);

namespace Dock\AltGenerator\Text;

/**
 * Turns text from outside (the API, a user) into one safe line of plain text.
 *
 * The result is still to be escaped where it is printed - this is not HTML escaping. It removes
 * what escaping does not: markup that would survive as text, control characters, invisible
 * bidirectional overrides that make an alt read differently than it is stored ("Trojan Source"),
 * zero-width characters and invalid UTF-8.
 *
 * @internal
 */
final class PlainText
{
    /**
     * C0/C1 controls, bidi embeddings, overrides and isolates, zero-width and BOM characters.
     * Line and paragraph separators become spaces instead (they separate words).
     */
    private const INVISIBLE = '/[\x{0000}-\x{0008}\x{000E}-\x{001F}\x{007F}-\x{009F}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{2066}-\x{206F}\x{FEFF}\x{FFF9}-\x{FFFB}]/u';

    public static function clean(string $text, int $maxLength): string
    {
        $text = self::validUtf8($text);
        $text = strip_tags($text);
        $text = (string) preg_replace('/[\x{2028}\x{2029}]/u', ' ', $text);
        $text = (string) preg_replace(self::INVISIBLE, '', $text);
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return self::truncate($text, $maxLength);
    }

    /**
     * Invalid byte sequences are dropped rather than the whole text: a single broken character
     * must not throw away an otherwise good alt.
     */
    private static function validUtf8(string $text): string
    {
        if (preg_match('//u', $text) === 1) {
            return $text;
        }

        // Keep only well-formed UTF-8 sequences - without mbstring, and the same on every PHP version.
        preg_match_all('/[\x00-\x7F]|[\xC2-\xDF][\x80-\xBF]|\xE0[\xA0-\xBF][\x80-\xBF]|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}|\xED[\x80-\x9F][\x80-\xBF]|\xF0[\x90-\xBF][\x80-\xBF]{2}|[\xF1-\xF3][\x80-\xBF]{3}|\xF4[\x80-\x8F][\x80-\xBF]{2}/', $text, $matches);

        return implode('', $matches[0]);
    }

    private static function truncate(string $text, int $maxLength): string
    {
        if ($maxLength <= 0) {
            return '';
        }

        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            return mb_strlen($text, 'UTF-8') > $maxLength ? rtrim(mb_substr($text, 0, $maxLength, 'UTF-8')) : $text;
        }

        if (preg_match('/^.{0,' . $maxLength . '}/us', $text, $match) === 1) {
            return rtrim($match[0]);
        }

        return '';
    }
}

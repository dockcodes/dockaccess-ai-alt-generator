<?php

declare(strict_types=1);

namespace Dock\AltGenerator;

/**
 * Languages the generator writes in, and the mapping from the locales CMSs use (`pl_PL`, `en-GB`,
 * `zh-TW`) onto them - the same rule as the API: an exact code wins, then the primary subtag.
 */
final class Language
{
    /** Codes the API accepts (`lang` of `POST /api/generate`). */
    public const CODES = [
        'af', 'sq', 'ar', 'hy', 'az', 'eu', 'be', 'bn', 'bs', 'bg', 'my', 'ca', 'zh-Hans', 'zh-Hant', 'hr', 'cs',
        'da', 'nl', 'en', 'eo', 'et', 'fil', 'fi', 'fr', 'gl', 'ka', 'de', 'el', 'gu', 'he', 'hi', 'hu', 'is', 'id',
        'ga', 'it', 'ja', 'kn', 'kk', 'km', 'ko', 'ku', 'ky', 'lo', 'la', 'lv', 'lt', 'mk', 'ms', 'ml', 'mr', 'mn',
        'ne', 'no', 'fa', 'pl', 'csb', 'szl', 'pt', 'pa', 'ro', 'ru', 'gd', 'sr', 'sd', 'si', 'sk', 'sl', 'so', 'es',
        'sw', 'sv', 'ta', 'te', 'th', 'tr', 'uk', 'ur', 'uz', 'vi', 'cy', 'xh', 'yo', 'zu',
    ];

    /** Locales whose primary subtag alone would pick the wrong script or language. */
    private const ALIASES = [
        'zh'      => 'zh-Hans',
        'zh-cn'   => 'zh-Hans',
        'zh-sg'   => 'zh-Hans',
        'zh-tw'   => 'zh-Hant',
        'zh-hk'   => 'zh-Hant',
        'zh-mo'   => 'zh-Hant',
        'nb'      => 'no',
        'nn'      => 'no',
        'iw'      => 'he',
        'in'      => 'id',
        'tl'      => 'fil',
    ];

    private function __construct()
    {
    }

    /**
     * The generator code for a locale, or null when it has none.
     */
    public static function tryNormalize(string $locale): ?string
    {
        $tag = strtolower(str_replace('_', '-', trim($locale)));

        if ($tag === '' || preg_match('/^[a-z0-9-]{1,35}$/D', $tag) !== 1) {
            return null;
        }

        $byLowercase = array_change_key_case(array_combine(self::CODES, self::CODES), CASE_LOWER);
        $parts       = explode('-', $tag);

        foreach ([$tag, $parts[0] . (isset($parts[1]) ? '-' . $parts[1] : ''), $parts[0]] as $candidate) {
            if (isset(self::ALIASES[$candidate])) {
                return self::ALIASES[$candidate];
            }

            if (isset($byLowercase[$candidate])) {
                return $byLowercase[$candidate];
            }
        }

        return null;
    }

    /**
     * The generator code for a locale, or `$fallback` - for CMS locales that may be anything.
     */
    public static function normalize(string $locale, string $fallback = 'en'): string
    {
        return self::tryNormalize($locale) ?? $fallback;
    }
}

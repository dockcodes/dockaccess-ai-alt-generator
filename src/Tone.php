<?php

declare(strict_types=1);

namespace Dock\AltGenerator;

/**
 * Tones the generator writes in (`tone` of `POST /api/generate`).
 */
final class Tone
{
    public const NEUTRAL       = 'neutral';
    public const CONCISE       = 'concise';
    public const DESCRIPTIVE   = 'descriptive';
    public const DIRECT        = 'direct';
    public const SIMPLIFIED    = 'simplified';
    public const FORMAL        = 'formal';
    public const PROFESSIONAL  = 'professional';
    public const FRIENDLY      = 'friendly';
    public const CASUAL        = 'casual';
    public const ENGAGING      = 'engaging';
    public const EMPATHETIC    = 'empathetic';
    public const DIPLOMATIC    = 'diplomatic';
    public const ACADEMIC      = 'academic';
    public const MIDDLE_SCHOOL = 'middle school';
    public const HIGH_SCHOOL   = 'high school';
    public const VIVID         = 'vivid';
    public const LUXURY        = 'luxury';
    public const PERSUASIVE    = 'persuasive';
    public const CONFIDENT     = 'confident';

    public const ALL = [
        self::NEUTRAL, self::CONCISE, self::DESCRIPTIVE, self::DIRECT, self::SIMPLIFIED, self::FORMAL,
        self::PROFESSIONAL, self::FRIENDLY, self::CASUAL, self::ENGAGING, self::EMPATHETIC, self::DIPLOMATIC,
        self::ACADEMIC, self::MIDDLE_SCHOOL, self::HIGH_SCHOOL, self::VIVID, self::LUXURY, self::PERSUASIVE,
        self::CONFIDENT,
    ];

    private function __construct()
    {
    }

    public static function isValid(string $tone): bool
    {
        return in_array($tone, self::ALL, true);
    }
}

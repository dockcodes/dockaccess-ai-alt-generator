<?php

declare(strict_types=1);

namespace Dock\AltGenerator\Result;

/**
 * One generated alt: plain text, one line, no markup or invisible characters - still to be escaped
 * where it is printed.
 */
final class Alt
{
    /** @var string */
    private $text;

    /** @var string */
    private $locale;

    public function __construct(string $text, string $locale)
    {
        $this->text   = $text;
        $this->locale = $locale;
    }

    public function text(): string
    {
        return $this->text;
    }

    public function locale(): string
    {
        return $this->locale;
    }

    public function __toString(): string
    {
        return $this->text;
    }
}

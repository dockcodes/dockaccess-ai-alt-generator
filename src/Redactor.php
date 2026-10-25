<?php

declare(strict_types=1);

namespace Dock\AltGenerator;

/**
 * Last line of defence for anything that becomes an exception message: the key in any of the forms
 * it may appear in, and query strings of URLs (the key travels in one).
 *
 * @internal
 */
final class Redactor
{
    /** @var Secret[] */
    private $secrets;

    public function __construct(Secret ...$secrets)
    {
        $this->secrets = $secrets;
    }

    public function redact(string $text): string
    {
        foreach ($this->secrets as $secret) {
            $value = $secret->reveal();

            foreach (array_unique([$value, rawurlencode($value), urlencode($value)]) as $form) {
                $text = str_replace($form, Secret::REDACTED, $text);
            }
        }

        // A URL keeps its scheme, host and path; whatever followed "?" is gone.
        return (string) preg_replace('#(https?://[^\s?\#"\'<>]*)\?[^\s"\'<>]*#i', '$1?' . Secret::REDACTED, $text);
    }
}

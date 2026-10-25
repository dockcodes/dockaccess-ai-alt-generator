<?php

declare(strict_types=1);

namespace Dock\AltGenerator;

use Dock\AltGenerator\Exception\ConfigurationException;
use Dock\AltGenerator\Text\PlainText;

/**
 * What to generate: language, tone, context, how many variants and which image set the image joins.
 * Immutable; every `with*()` validates and returns a copy.
 */
final class GenerateOptions
{
    /** The API refuses longer contexts; a longer one is cut, not refused - it is a hint, not content. */
    public const MAX_CONTEXT_LENGTH = 500;

    public const MAX_SET_REF_LENGTH = 255;

    /** @var string */
    private $language = 'en';

    /** @var string */
    private $tone = Tone::NEUTRAL;

    /** @var string */
    private $context = '';

    /** @var int */
    private $variants = 1;

    /** @var string|null */
    private $setRef;

    /** @var string|null */
    private $setUuid;

    public static function create(): self
    {
        return new self();
    }

    /**
     * @param string $locale A generator code or any locale (`pl_PL`, `en-GB`); see Language::normalize()
     *                       for CMS locales that may not be supported.
     */
    public function withLanguage(string $locale): self
    {
        $code = Language::tryNormalize($locale);

        if ($code === null) {
            throw new ConfigurationException('The alt generator does not write in this language.');
        }

        $copy           = clone $this;
        $copy->language = $code;

        return $copy;
    }

    public function withTone(string $tone): self
    {
        if (!Tone::isValid($tone)) {
            throw new ConfigurationException('Unknown tone; use one of Tone::ALL.');
        }

        $copy       = clone $this;
        $copy->tone = $tone;

        return $copy;
    }

    /**
     * A hint about the site or the image ("an online shop with handmade ceramics"). Markup and control
     * characters are removed and the text is cut to MAX_CONTEXT_LENGTH characters.
     */
    public function withContext(string $context): self
    {
        $copy          = clone $this;
        $copy->context = PlainText::clean($context, self::MAX_CONTEXT_LENGTH);

        return $copy;
    }

    /**
     * How many alternative alts to generate for the image (1 to 3). Every image counts once against
     * the daily limit, whatever the number.
     */
    public function withVariants(int $variants): self
    {
        if ($variants < 1 || $variants > 3) {
            throw new ConfigurationException('Variants must be between 1 and 3.');
        }

        $copy           = clone $this;
        $copy->variants = $variants;

        return $copy;
    }

    /**
     * An identifier of your own - a site token, a shop id - that keeps every image of it in one image
     * set of the account. Uploading the same file name and bytes again under the same ref replaces
     * that image's alts instead of adding a copy.
     */
    public function withSetRef(string $ref): self
    {
        $ref = trim($ref);

        if ($ref === '' || strlen($ref) > self::MAX_SET_REF_LENGTH || preg_match('/[\x00-\x1F\x7F]/', $ref) === 1) {
            throw new ConfigurationException('The set ref must be 1 to 255 bytes without control characters.');
        }

        $copy         = clone $this;
        $copy->setRef = $ref;

        return $copy;
    }

    /**
     * Adds the image to an existing set of the account.
     */
    public function withSetUuid(string $uuid): self
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/Di', $uuid) !== 1) {
            throw new ConfigurationException('The set uuid is not a UUID.');
        }

        $copy          = clone $this;
        $copy->setUuid = strtolower($uuid);

        return $copy;
    }

    public function language(): string
    {
        return $this->language;
    }

    public function tone(): string
    {
        return $this->tone;
    }

    public function context(): string
    {
        return $this->context;
    }

    public function variants(): int
    {
        return $this->variants;
    }

    public function setRef(): ?string
    {
        return $this->setRef;
    }

    public function setUuid(): ?string
    {
        return $this->setUuid;
    }

    /**
     * The query parameters of `POST /api/generate`, without the key.
     *
     * @return array<string, string>
     */
    public function toQuery(): array
    {
        $query = [
            'lang'     => $this->language,
            'tone'     => $this->tone,
            'variants' => (string) $this->variants,
        ];

        if ($this->context !== '') {
            $query['context'] = $this->context;
        }

        if ($this->setRef !== null) {
            $query['set_ref'] = $this->setRef;
        }

        if ($this->setUuid !== null) {
            $query['set_uuid'] = $this->setUuid;
        }

        return $query;
    }
}

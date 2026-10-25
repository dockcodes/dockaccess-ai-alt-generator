<?php

declare(strict_types=1);

namespace Dock\AltGenerator;

use Dock\AltGenerator\Exception\ConfigurationException;

/**
 * The account key, kept out of everything that ends up in logs.
 *
 * Casting to string, `var_dump()`, `print_r()`, `json_encode()` and exception traces show
 * "[redacted]"; serialising throws, so the key cannot end up in a cache or a session by accident.
 * In memory it is held XOR-masked with a random pad, so even `var_export()` or a heap dump does
 * not show it in plain text. Only `reveal()` returns the value, at the moment a request is built.
 */
final class Secret implements \JsonSerializable
{
    public const REDACTED = '[redacted]';

    /** URL-unreserved characters only: the key travels in a query string. */
    private const PATTERN = '/^[A-Za-z0-9._~-]{8,255}$/D';

    /** @var string */
    private $masked;

    /** @var string */
    private $pad;

    public function __construct(
        #[\SensitiveParameter]
        string $value
    ) {
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw new ConfigurationException('The account key must be 8 to 255 characters from A-Z, a-z, 0-9, ".", "_", "~" and "-".');
        }

        $this->pad    = random_bytes(strlen($value));
        $this->masked = $value ^ $this->pad;
    }

    public function reveal(): string
    {
        return $this->masked ^ $this->pad;
    }

    public function __toString(): string
    {
        return self::REDACTED;
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['value' => self::REDACTED];
    }

    public function jsonSerialize(): string
    {
        return self::REDACTED;
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new \LogicException('An account key is never serialised.');
    }

    /**
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('An account key is never unserialised.');
    }

    /**
     * @return string[]
     */
    public function __sleep(): array
    {
        throw new \LogicException('An account key is never serialised.');
    }

    public function __wakeup(): void
    {
        throw new \LogicException('An account key is never unserialised.');
    }
}

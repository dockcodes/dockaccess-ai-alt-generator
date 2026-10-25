<?php

declare(strict_types=1);

namespace Dock\AltGenerator\Http;

/**
 * An answer of the API: status, headers (names in lowercase) and body.
 */
final class Response
{
    /** @var int */
    private $status;

    /** @var array<string, string> */
    private $headers;

    /** @var string */
    private $body;

    /**
     * @param array<string, string> $headers
     */
    public function __construct(int $status, array $headers, string $body)
    {
        $this->status  = $status;
        $this->headers = array_change_key_case($headers, CASE_LOWER);
        $this->body    = $body;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function body(): string
    {
        return $this->body;
    }
}

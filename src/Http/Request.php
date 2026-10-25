<?php

declare(strict_types=1);

namespace Dock\AltGenerator\Http;

use Dock\AltGenerator\Secret;

/**
 * One request to the API. The URL carries the account key in its query, so dumping a request shows
 * the URL without its query and the body only by size.
 */
final class Request
{
    /** @var string */
    private $method;

    /** @var string */
    private $url;

    /** @var array<string, string> */
    private $headers;

    /** @var string|null */
    private $body;

    /**
     * @param array<string, string> $headers
     */
    public function __construct(string $method, string $url, array $headers = [], ?string $body = null)
    {
        $this->method  = strtoupper($method);
        $this->url     = $url;
        $this->headers = $headers;
        $this->body    = $body;
    }

    public function method(): string
    {
        return $this->method;
    }

    public function url(): string
    {
        return $this->url;
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    public function body(): ?string
    {
        return $this->body;
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        $query = strpos($this->url, '?');

        return [
            'method'  => $this->method,
            'url'     => $query === false ? $this->url : substr($this->url, 0, $query) . '?' . Secret::REDACTED,
            'headers' => array_keys($this->headers),
            'body'    => $this->body === null ? null : strlen($this->body) . ' bytes',
        ];
    }
}

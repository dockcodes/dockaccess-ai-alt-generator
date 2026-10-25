<?php

declare(strict_types=1);

namespace Dock\AltGenerator;

use Dock\AltGenerator\Exception\ConfigurationException;
use Dock\AltGenerator\Image\ImagePolicy;

/**
 * How the client talks to the generator. Immutable; every `with*()` validates and returns a copy.
 *
 * Secure by default: HTTPS only, certificate and host verified, no redirects, bounded timeouts.
 * Plain HTTP is accepted for a loopback host only, and only after allowInsecureLoopback() - for
 * local tests against a stub, never for a real deployment.
 */
final class Config
{
    public const DEFAULT_BASE_URL = 'https://alt.dockaccess.org';

    /** @var Secret */
    private $accountKey;

    /** @var string */
    private $baseUrl = self::DEFAULT_BASE_URL;

    /** @var bool */
    private $insecureLoopback = false;

    /** @var int */
    private $connectTimeoutMs = 5000;

    /**
     * Generation includes an AI model call; the API itself waits up to about a minute for it.
     *
     * @var int
     */
    private $timeoutMs = 90000;

    /** @var string|null */
    private $caBundle;

    /** @var string|null */
    private $proxy;

    /** @var string */
    private $userAgentSuffix = '';

    /** @var int */
    private $maxAltLength = 2000;

    /** @var int */
    private $connectRetries = 1;

    /** @var ImagePolicy */
    private $imagePolicy;

    public function __construct(Secret $accountKey)
    {
        $this->accountKey  = $accountKey;
        $this->imagePolicy = ImagePolicy::secure();
    }

    public static function create(
        #[\SensitiveParameter]
        string $accountKey
    ): self {
        return new self(new Secret($accountKey));
    }

    public function withBaseUrl(string $baseUrl): self
    {
        $copy          = clone $this;
        $copy->baseUrl = self::validatedBaseUrl($baseUrl, $this->insecureLoopback);

        return $copy;
    }

    /**
     * Lets withBaseUrl() take http://127.0.0.1, http://[::1] or http://localhost. Tests only.
     */
    public function allowInsecureLoopback(): self
    {
        $copy                   = clone $this;
        $copy->insecureLoopback = true;

        return $copy;
    }

    public function withTimeouts(int $connectTimeoutMs, int $timeoutMs): self
    {
        if ($connectTimeoutMs < 100 || $connectTimeoutMs > 60000 || $timeoutMs < $connectTimeoutMs || $timeoutMs > 300000) {
            throw new ConfigurationException('Timeouts must satisfy 100 <= connect <= 60000 and connect <= total <= 300000 ms.');
        }

        $copy                   = clone $this;
        $copy->connectTimeoutMs = $connectTimeoutMs;
        $copy->timeoutMs        = $timeoutMs;

        return $copy;
    }

    /**
     * A CA bundle to verify the API certificate with, e.g. WordPress's wp-includes/certificates/ca-bundle.crt
     * on hosts whose system store is outdated. Verification itself is never switched off.
     */
    public function withCaBundle(string $path): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new ConfigurationException('The CA bundle is not a readable file.');
        }

        $copy           = clone $this;
        $copy->caBundle = $path;

        return $copy;
    }

    /**
     * An outgoing proxy, `host:port` or `http://user:pass@host:port`, as the platform configures it
     * (WP_PROXY_*, Joomla's Global Configuration). Never shown in messages.
     */
    public function withProxy(
        #[\SensitiveParameter]
        string $proxy
    ): self {
        if ($proxy === '' || preg_match('/[\s\x00-\x1F\x7F]/', $proxy) === 1) {
            throw new ConfigurationException('The proxy is empty or contains whitespace.');
        }

        $copy        = clone $this;
        $copy->proxy = $proxy;

        return $copy;
    }

    /**
     * Who is calling, e.g. "wordpress/6.8 dockaccess-wp/1.2.0". Shown to the API in the User-Agent.
     */
    public function withUserAgentSuffix(string $suffix): self
    {
        $suffix = trim((string) preg_replace('/[^A-Za-z0-9 ._\/+()-]/', '', $suffix));

        $copy                  = clone $this;
        $copy->userAgentSuffix = substr($suffix, 0, 120);

        return $copy;
    }

    /**
     * The longest alt the client hands back (characters). Platforms store less: PrestaShop legends hold 128.
     */
    public function withMaxAltLength(int $maxLength): self
    {
        if ($maxLength < 16 || $maxLength > 2000) {
            throw new ConfigurationException('The alt length must be between 16 and 2000 characters.');
        }

        $copy               = clone $this;
        $copy->maxAltLength = $maxLength;

        return $copy;
    }

    /**
     * How many times a request that never reached the API (DNS, refused connection) is tried again.
     * Requests the API may have processed are never repeated, so a retry cannot be charged twice.
     */
    public function withConnectRetries(int $retries): self
    {
        if ($retries < 0 || $retries > 3) {
            throw new ConfigurationException('Connect retries must be between 0 and 3.');
        }

        $copy                 = clone $this;
        $copy->connectRetries = $retries;

        return $copy;
    }

    public function withImagePolicy(ImagePolicy $policy): self
    {
        $copy              = clone $this;
        $copy->imagePolicy = $policy;

        return $copy;
    }

    public function accountKey(): Secret
    {
        return $this->accountKey;
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    public function isInsecureLoopbackAllowed(): bool
    {
        return $this->insecureLoopback;
    }

    public function connectTimeoutMs(): int
    {
        return $this->connectTimeoutMs;
    }

    public function timeoutMs(): int
    {
        return $this->timeoutMs;
    }

    public function caBundle(): ?string
    {
        return $this->caBundle;
    }

    public function proxy(): ?string
    {
        return $this->proxy;
    }

    public function userAgent(): string
    {
        return trim('dockaccess-alt-generator-php/' . AltGenerator::VERSION . ' ' . $this->userAgentSuffix);
    }

    public function maxAltLength(): int
    {
        return $this->maxAltLength;
    }

    public function connectRetries(): int
    {
        return $this->connectRetries;
    }

    public function imagePolicy(): ImagePolicy
    {
        return $this->imagePolicy;
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'accountKey'       => Secret::REDACTED,
            'baseUrl'          => $this->baseUrl,
            'insecureLoopback' => $this->insecureLoopback,
            'connectTimeoutMs' => $this->connectTimeoutMs,
            'timeoutMs'        => $this->timeoutMs,
            'caBundle'         => $this->caBundle,
            'proxy'            => $this->proxy === null ? null : Secret::REDACTED,
            'userAgent'        => $this->userAgent(),
            'maxAltLength'     => $this->maxAltLength,
            'connectRetries'   => $this->connectRetries,
            'imagePolicy'      => $this->imagePolicy,
        ];
    }

    private static function validatedBaseUrl(string $baseUrl, bool $insecureLoopback): string
    {
        $parts = parse_url($baseUrl);

        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            throw new ConfigurationException('The base URL is not an absolute URL.');
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new ConfigurationException('The base URL must not carry credentials, a query or a fragment.');
        }

        $scheme   = strtolower($parts['scheme']);
        $host     = strtolower($parts['host']);
        $loopback = in_array($host, ['127.0.0.1', '[::1]', 'localhost'], true);

        if ($scheme !== 'https' && !($scheme === 'http' && $insecureLoopback && $loopback)) {
            throw new ConfigurationException('The base URL must use HTTPS.');
        }

        if (preg_match('/^[a-z0-9.\-\[\]:]+$/D', $host) !== 1) {
            throw new ConfigurationException('The base URL host is not valid.');
        }

        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = rtrim($parts['path'] ?? '', '/');

        return $scheme . '://' . $host . $port . $path;
    }
}

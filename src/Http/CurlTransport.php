<?php

declare(strict_types=1);

namespace Dock\AltGenerator\Http;

use Dock\AltGenerator\Config;
use Dock\AltGenerator\Exception\ConfigurationException;
use Dock\AltGenerator\Exception\TransportException;

/**
 * cURL, locked down: HTTPS only (HTTP only for an explicitly allowed loopback stub), peer and host
 * verified, TLS 1.2 or newer, redirects never followed (a redirect would carry the key and the image
 * somewhere else), connect and total timeouts, and answers cut off at 1 MiB of body and 64 KiB of
 * headers. Error messages come from curl_strerror() - generic texts without the URL.
 */
final class CurlTransport implements TransportInterface
{
    public const MAX_BODY_BYTES = 1048576;

    private const MAX_HEADER_BYTES = 65536;

    /** cURL error numbers raised before anything was sent: a retry cannot be processed twice. */
    private const BEFORE_SENDING = [
        5, // CURLE_COULDNT_RESOLVE_PROXY
        6, // CURLE_COULDNT_RESOLVE_HOST
        7, // CURLE_COULDNT_CONNECT
    ];

    /** @var Config */
    private $config;

    public function __construct(Config $config)
    {
        if (!function_exists('curl_init')) {
            throw new ConfigurationException('The curl extension is required.');
        }

        $this->config = $config;
    }

    public function send(Request $request): Response
    {
        $handle = curl_init();

        if ($handle === false) {
            throw new TransportException('cURL could not be initialised.');
        }

        $body        = '';
        $headers     = [];
        $headerBytes = 0;
        $overflow    = false;

        $options = [
            CURLOPT_URL            => $request->url(),
            CURLOPT_CUSTOMREQUEST  => $request->method(),
            CURLOPT_HTTPHEADER     => $this->headerLines($request),
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS      => 0,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_SSLVERSION     => CURL_SSLVERSION_TLSv1_2,
            CURLOPT_CONNECTTIMEOUT_MS => $this->config->connectTimeoutMs(),
            CURLOPT_TIMEOUT_MS     => $this->config->timeoutMs(),
            CURLOPT_NOSIGNAL       => true,
            CURLOPT_USERAGENT      => $this->config->userAgent(),
            CURLOPT_WRITEFUNCTION  => static function ($curl, string $chunk) use (&$body, &$overflow): int {
                if (strlen($body) + strlen($chunk) > self::MAX_BODY_BYTES) {
                    $overflow = true;

                    return 0; // aborts the transfer
                }

                $body .= $chunk;

                return strlen($chunk);
            },
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$headers, &$headerBytes, &$overflow): int {
                $headerBytes += strlen($line);

                if ($headerBytes > self::MAX_HEADER_BYTES) {
                    $overflow = true;

                    return 0;
                }

                // A new status line (100 Continue, a proxy's CONNECT answer) starts a new header block.
                if (strncmp($line, 'HTTP/', 5) === 0) {
                    $headers = [];
                } elseif (strpos($line, ':') !== false) {
                    [$name, $value]                 = explode(':', $line, 2);
                    $headers[strtolower(trim($name))] = trim($value);
                }

                return strlen($line);
            },
        ];

        $this->restrictProtocols($options, $request);

        if ($request->body() !== null) {
            $options[CURLOPT_POSTFIELDS] = $request->body();
        }

        if ($this->config->caBundle() !== null) {
            $options[CURLOPT_CAINFO] = $this->config->caBundle();
        }

        if ($this->config->proxy() !== null) {
            $options[CURLOPT_PROXY] = $this->config->proxy();
        }

        if (!curl_setopt_array($handle, $options)) {
            throw new TransportException('cURL refused the request options.');
        }

        $ok          = curl_exec($handle);
        $errno       = curl_errno($handle);
        $status      = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $pretransfer = (float) curl_getinfo($handle, CURLINFO_PRETRANSFER_TIME);
        // Freed with its last reference (a PHP 7 resource too); curl_close() is deprecated in PHP 8.5.
        unset($handle);

        if ($overflow) {
            throw new TransportException('The answer of the alt generator is larger than allowed.');
        }

        if ($ok === false || $errno !== 0) {
            // A timeout before the request went out (DNS, TCP, TLS) is as safe to repeat as a refused connection.
            $retryable = in_array($errno, self::BEFORE_SENDING, true) || ($errno === 28 && $pretransfer <= 0.0);

            throw new TransportException(sprintf('Could not reach the alt generator: %s (cURL error %d).', (string) curl_strerror($errno), $errno), $retryable, $errno);
        }

        return new Response($status, $headers, $body);
    }

    /**
     * @return list<string>
     */
    private function headerLines(Request $request): array
    {
        $lines = [];

        foreach ($request->headers() as $name => $value) {
            if (preg_match('/^[A-Za-z0-9-]+$/D', $name) !== 1 || preg_match('/[\r\n\0]/', $value) === 1) {
                throw new TransportException('A request header is not valid.');
            }

            $lines[] = $name . ': ' . $value;
        }

        // No "Expect: 100-continue" pause before every upload.
        $lines[] = 'Expect:';

        return $lines;
    }

    /**
     * Requests are built only from the validated base and connection URLs, so plain HTTP here means
     * a loopback stub that allowInsecureLoopback() let through.
     *
     * @param array<int, mixed> $options
     */
    private function restrictProtocols(array &$options, Request $request): void
    {
        $insecure = $this->config->isInsecureLoopbackAllowed() && strncmp($request->url(), 'http://', 7) === 0;

        if (defined('CURLOPT_PROTOCOLS_STR')) {
            $options[(int) constant('CURLOPT_PROTOCOLS_STR')]       = $insecure ? 'http,https' : 'https';
            $options[(int) constant('CURLOPT_REDIR_PROTOCOLS_STR')] = 'https';
        } else {
            $options[CURLOPT_PROTOCOLS]       = $insecure ? (CURLPROTO_HTTP | CURLPROTO_HTTPS) : CURLPROTO_HTTPS;
            $options[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTPS;
        }
    }
}

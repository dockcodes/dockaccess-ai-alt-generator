<?php

declare(strict_types=1);

namespace Dock\AltGenerator\Http;

use Dock\AltGenerator\Exception\TransportException;

/**
 * Sends a request and returns the answer, whatever its status. An implementation for a framework's
 * HTTP client (Laravel's, Symfony's, WordPress's) must keep the guarantees of CurlTransport: HTTPS
 * with certificate and host verification, no redirects, bounded timeouts and response size, and
 * messages that never contain the URL's query.
 */
interface TransportInterface
{
    /**
     * @throws TransportException when no complete answer arrived
     */
    public function send(Request $request): Response;
}

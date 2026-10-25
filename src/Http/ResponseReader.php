<?php

declare(strict_types=1);

namespace Dock\AltGenerator\Http;

use Dock\AltGenerator\Exception\ApiException;
use Dock\AltGenerator\Exception\AuthenticationException;
use Dock\AltGenerator\Exception\DailyLimitReachedException;
use Dock\AltGenerator\Exception\ImageRejectedException;
use Dock\AltGenerator\Exception\RateLimitedException;
use Dock\AltGenerator\Exception\ServerException;
use Dock\AltGenerator\Exception\UnexpectedResponseException;
use Dock\AltGenerator\Redactor;
use Dock\AltGenerator\Text\PlainText;

/**
 * Reads an answer of the API: a successful one as decoded JSON, a refusal as the exception that
 * names it. Whatever the API says goes through PlainText and the Redactor before it reaches a message.
 *
 * @internal
 */
final class ResponseReader
{
    private const MAX_MESSAGE = 300;

    private const DEFAULT_RETRY_AFTER = 60;

    /** @var Redactor */
    private $redactor;

    public function __construct(Redactor $redactor)
    {
        $this->redactor = $redactor;
    }

    /**
     * @return array<mixed>
     */
    public function json(Response $response): array
    {
        $status = $response->status();

        if ($status >= 200 && $status < 300) {
            $data = $this->decode($response->body());

            if ($data === null) {
                throw new UnexpectedResponseException('The alt generator answered with something that is not JSON.', $status);
            }

            return $data;
        }

        $message = $this->message($response);

        if ($status === 429) {
            if ($response->header('X-Alt-Reason') === 'limit_reached') {
                $limit = $response->header('X-Alt-Daily-Limit');

                throw new DailyLimitReachedException($status, $message, $limit !== null && ctype_digit($limit) ? (int) $limit : null);
            }

            throw new RateLimitedException($status, $message, self::retryAfter($response->header('Retry-After')));
        }

        if ($status === 401 || ($status === 403 && stripos($message, 'invalid key') !== false)) {
            throw new AuthenticationException($status, $message);
        }

        if ($status === 400 || $status === 413 || $status === 415) {
            throw new ImageRejectedException($status, $message);
        }

        if ($status >= 500 && $status < 600) {
            throw new ServerException($status, $message);
        }

        if ($status >= 400 && $status < 500) {
            throw new ApiException($status, $message);
        }

        throw new UnexpectedResponseException(sprintf('The alt generator answered with HTTP %d.', $status), $status);
    }

    /**
     * @return array<mixed>|null
     */
    private function decode(string $body): ?array
    {
        if ($body === '') {
            return null;
        }

        $data = json_decode($body, true, 32);

        return is_array($data) ? $data : null;
    }

    /**
     * The API's `detail` - a string, or FastAPI's list of validation errors - cleaned for a message.
     */
    private function message(Response $response): string
    {
        $data   = $this->decode($response->body());
        $detail = $data['detail'] ?? null;

        if (is_array($detail) && isset($detail[0]) && is_array($detail[0]) && is_string($detail[0]['msg'] ?? null)) {
            $detail = $detail[0]['msg'];
        }

        if (!is_string($detail) || trim($detail) === '') {
            return 'No details given.';
        }

        $clean = PlainText::clean($this->redactor->redact($detail), self::MAX_MESSAGE);

        return $clean === '' ? 'No details given.' : $clean;
    }

    private static function retryAfter(?string $value): int
    {
        if ($value === null || !ctype_digit(trim($value))) {
            return self::DEFAULT_RETRY_AFTER;
        }

        return max(1, min(3600, (int) trim($value)));
    }
}

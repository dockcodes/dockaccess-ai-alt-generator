<?php

declare(strict_types=1);

namespace Dock\AltGenerator\Tests\Support;

use Dock\AltGenerator\Exception\TransportException;
use Dock\AltGenerator\Http\Request;
use Dock\AltGenerator\Http\Response;
use Dock\AltGenerator\Http\TransportInterface;

/**
 * Answers from a queue and remembers every request.
 */
final class FakeTransport implements TransportInterface
{
    /** @var Request[] */
    public $requests = [];

    /** @var list<Response|TransportException> */
    private $answers;

    /**
     * @param Response|TransportException ...$answers
     */
    public function __construct(...$answers)
    {
        $this->answers = $answers;
    }

    public static function json(int $status, array $body, array $headers = []): Response
    {
        return new Response($status, $headers, (string) json_encode($body));
    }

    public function send(Request $request): Response
    {
        $this->requests[] = $request;
        $answer           = array_shift($this->answers);

        if ($answer instanceof TransportException) {
            throw $answer;
        }

        if ($answer === null) {
            throw new \LogicException('No answer queued.');
        }

        return $answer;
    }
}

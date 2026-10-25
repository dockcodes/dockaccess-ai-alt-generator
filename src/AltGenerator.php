<?php

declare(strict_types=1);

namespace Dock\AltGenerator;

use Dock\AltGenerator\Exception\TransportException;
use Dock\AltGenerator\Exception\UnexpectedResponseException;
use Dock\AltGenerator\Http\CurlTransport;
use Dock\AltGenerator\Http\Multipart;
use Dock\AltGenerator\Http\Request;
use Dock\AltGenerator\Http\Response;
use Dock\AltGenerator\Http\ResponseReader;
use Dock\AltGenerator\Http\TransportInterface;
use Dock\AltGenerator\Image\ImageSanitizer;
use Dock\AltGenerator\Image\ImageSource;
use Dock\AltGenerator\Image\PreparedImage;
use Dock\AltGenerator\Result\Alt;
use Dock\AltGenerator\Result\GenerationResult;
use Dock\AltGenerator\Result\Usage;
use Dock\AltGenerator\Text\PlainText;

/**
 * The dockaccess.org alt generator.
 *
 *     $generator = new AltGenerator(Config::create($accountKey));
 *     $image     = ImageSource::fromFile($path, [$uploadsDirectory]);
 *     $result    = $generator->generate($image, GenerateOptions::create()->withLanguage('pl_PL'));
 *     echo htmlspecialchars($result->alt()->text());
 *
 * Every image is verified and sanitized locally before it is sent (see ImageSanitizer); a file that
 * is not a genuine image never leaves the server.
 */
final class AltGenerator
{
    public const VERSION = '1.0.0';

    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/Di';

    /** @var Config */
    private $config;

    /** @var TransportInterface */
    private $transport;

    /** @var Redactor */
    private $redactor;

    /** @var ResponseReader */
    private $reader;

    /** @var ImageSanitizer */
    private $sanitizer;

    public function __construct(Config $config, ?TransportInterface $transport = null, ?ImageSanitizer $sanitizer = null)
    {
        if (PHP_INT_SIZE < 8) {
            throw new Exception\ConfigurationException('A 64-bit PHP is required.');
        }

        $this->config    = $config;
        $this->transport = $transport ?? new CurlTransport($config);
        $this->redactor  = new Redactor($config->accountKey());
        $this->reader    = new ResponseReader($this->redactor);
        $this->sanitizer = $sanitizer ?? new ImageSanitizer($config->imagePolicy());
    }

    /**
     * Verifies and sanitizes an image without sending it - to refuse a bad file at upload time, or
     * before queueing a bulk run.
     *
     * @throws Exception\InvalidImageException
     */
    public function prepare(ImageSource $image): PreparedImage
    {
        return $this->sanitizer->prepare($image);
    }

    /**
     * Generates alt text for one image. Counts against the account's daily limit when it succeeds.
     *
     * @throws Exception\InvalidImageException       the file was refused locally, nothing was sent
     * @throws Exception\AuthenticationException     the account key was not accepted
     * @throws Exception\DailyLimitReachedException  the daily quota is spent
     * @throws Exception\RateLimitedException        the per minute cap; try again after getRetryAfter()
     * @throws Exception\ImageRejectedException      the API refused the image
     * @throws Exception\ApiException                any other refusal
     * @throws Exception\TransportException          the API could not be reached
     * @throws Exception\UnexpectedResponseException the answer broke the contract
     */
    public function generate(ImageSource $image, ?GenerateOptions $options = null): GenerationResult
    {
        $options   = $options ?? GenerateOptions::create();
        $prepared  = $this->sanitizer->prepare($image);
        $multipart = new Multipart('file', $prepared);

        $response = $this->send(new Request(
            'POST',
            $this->url('/api/generate', $options->toQuery()),
            ['Content-Type' => $multipart->contentType(), 'Accept' => 'application/json'],
            $multipart->body()
        ));

        return $this->generationResult($this->reader->json($response), $options, $prepared, $response->status());
    }

    /**
     * Today's usage of the account against its daily limit.
     */
    public function usage(): Usage
    {
        $response = $this->send(new Request('GET', $this->url('/api/user', []), ['Accept' => 'application/json']));
        $data     = $this->reader->json($response);
        $requests = $data['requests'] ?? null;

        if (!is_array($requests) || !is_int($requests['used'] ?? null) || !is_int($requests['limit'] ?? null)) {
            throw new UnexpectedResponseException('The usage answer lacks its numbers.', $response->status());
        }

        return new Usage($requests['used'], $requests['limit']);
    }

    /**
     * @param array<string, string> $query
     */
    private function url(string $path, array $query): string
    {
        $query['key'] = $this->config->accountKey()->reveal();

        return $this->config->baseUrl() . $path . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Sends, repeating only what certainly never reached the API.
     */
    private function send(Request $request): Response
    {
        $attempt = 0;

        while (true) {
            try {
                return $this->transport->send($request);
            } catch (TransportException $e) {
                if (!$e->isRetryable() || $attempt >= $this->config->connectRetries()) {
                    throw new TransportException($this->redactor->redact($e->getMessage()), $e->isRetryable(), $e->getCode());
                }

                usleep(250000 * (2 ** $attempt));
                $attempt++;
            }
        }
    }

    /**
     * @param array<mixed> $data
     */
    private function generationResult(array $data, GenerateOptions $options, PreparedImage $prepared, int $status): GenerationResult
    {
        $payload = $data['data'] ?? null;
        $image   = is_array($payload) ? ($payload['image'] ?? null) : null;

        if (!is_array($payload) || !is_array($image)) {
            throw new UnexpectedResponseException('The answer has no image.', $status);
        }

        $imageUuid = $image['uuid'] ?? null;
        $setUuid   = $payload['set_uuid'] ?? null;
        $setRef    = $payload['set_ref'] ?? null;

        if (!is_string($imageUuid) || preg_match(self::UUID, $imageUuid) !== 1 || !is_string($setUuid) || preg_match(self::UUID, $setUuid) !== 1) {
            throw new UnexpectedResponseException('The answer lacks valid image or set identifiers.', $status);
        }

        $alts = [];

        foreach (is_array($image['alts'] ?? null) ? $image['alts'] : [] as $item) {
            if (!is_array($item) || !is_string($item['alt'] ?? null)) {
                continue;
            }

            $text   = PlainText::clean($item['alt'], $this->config->maxAltLength());
            $locale = is_string($item['locale'] ?? null) ? Language::normalize($item['locale'], $options->language()) : $options->language();

            if ($text !== '') {
                $alts[] = new Alt($text, $locale);
            }
        }

        if ($alts === []) {
            throw new UnexpectedResponseException('The generator returned no usable alt text.', $status);
        }

        return new GenerationResult(
            strtolower($imageUuid),
            strtolower($setUuid),
            is_string($setRef) ? $setRef : null,
            $alts,
            $prepared
        );
    }
}

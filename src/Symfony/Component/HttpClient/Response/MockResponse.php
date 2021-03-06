<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\HttpClient\Response;

use Symfony\Component\HttpClient\Chunk\ErrorChunk;
use Symfony\Component\HttpClient\Chunk\FirstChunk;
use Symfony\Component\HttpClient\Exception\InvalidArgumentException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * A test-friendly response.
 *
 * @author Nicolas Grekas <p@tchwork.com>
 */
class MockResponse implements ResponseInterface
{
    use ResponseTrait;

    private $body;
    private $requestOptions = [];

    private static $mainMulti;
    private static $idSequence = 0;

    /**
     * @param string|string[]|iterable $body The response body as a string or an iterable of strings,
     *                                       yielding an empty string simulates a timeout,
     *                                       exceptions are turned to TransportException
     *
     * @see ResponseInterface::getInfo() for possible info, e.g. "raw_headers"
     */
    public function __construct($body = '', array $info = [])
    {
        $this->body = \is_iterable($body) ? $body : (string) $body;
        $this->info = $info + $this->info;

        if (!isset($info['raw_headers'])) {
            return;
        }

        $rawHeaders = [];

        foreach ($info['raw_headers'] as $k => $v) {
            foreach ((array) $v as $v) {
                $rawHeaders[] = (\is_string($k) ? $k.': ' : '').$v;
            }
        }

        $info['raw_headers'] = $rawHeaders;
    }

    /**
     * Returns the options used when doing the request.
     */
    public function getRequestOptions(): array
    {
        return $this->requestOptions;
    }

    /**
     * {@inheritdoc}
     */
    public function getInfo(string $type = null)
    {
        return null !== $type ? $this->info[$type] ?? null : $this->info;
    }

    /**
     * {@inheritdoc}
     */
    protected function close(): void
    {
        $this->body = [];
    }

    /**
     * @internal
     */
    public static function fromRequest(string $method, string $url, array $options, ResponseInterface $mock): self
    {
        $response = new self([]);
        $response->requestOptions = $options;
        $response->id = ++self::$idSequence;
        $response->content = ($options['buffer'] ?? true) ? fopen('php://temp', 'w+') : null;
        $response->initializer = static function (self $response) {
            if (null !== $response->info['error']) {
                throw new TransportException($response->info['error']);
            }

            if (\is_array($response->body[0] ?? null)) {
                // Consume the first chunk if it's not yielded yet
                self::stream([$response])->current();
            }
        };

        $response->info['redirect_count'] = 0;
        $response->info['redirect_url'] = null;
        $response->info['start_time'] = microtime(true);
        $response->info['http_method'] = $method;
        $response->info['http_code'] = 0;
        $response->info['user_data'] = $options['user_data'] ?? null;
        $response->info['url'] = $url;

        self::writeRequest($response, $options, $mock);
        $response->body[] = [$options, $mock];

        return $response;
    }

    /**
     * {@inheritdoc}
     */
    protected static function schedule(self $response, array &$runningResponses): void
    {
        if (!$response->id) {
            throw new InvalidArgumentException('MockResponse instances must be issued by MockHttpClient before processing.');
        }

        $multi = self::$mainMulti ?? self::$mainMulti = (object) [
            'handlesActivity' => [],
            'openHandles' => [],
        ];

        if (!isset($runningResponses[0])) {
            $runningResponses[0] = [$multi, []];
        }

        $runningResponses[0][1][$response->id] = $response;
    }

    /**
     * {@inheritdoc}
     */
    protected static function perform(\stdClass $multi, array &$responses): void
    {
        foreach ($responses as $response) {
            $id = $response->id;

            if (!$response->body) {
                // Last chunk
                $multi->handlesActivity[$id][] = null;
                $multi->handlesActivity[$id][] = null !== $response->info['error'] ? new TransportException($response->info['error']) : null;
            } elseif (null === $chunk = array_shift($response->body)) {
                // Last chunk
                $multi->handlesActivity[$id][] = null;
                $multi->handlesActivity[$id][] = array_shift($response->body);
            } elseif (\is_array($chunk)) {
                // First chunk
                try {
                    $offset = 0;
                    $chunk[1]->getStatusCode();
                    $response->headers = $chunk[1]->getHeaders(false);
                    $multi->handlesActivity[$id][] = new FirstChunk();
                    self::readResponse($response, $chunk[0], $chunk[1], $offset);
                } catch (\Throwable $e) {
                    $multi->handlesActivity[$id][] = null;
                    $multi->handlesActivity[$id][] = $e;
                }
            } else {
                // Data or timeout chunk
                $multi->handlesActivity[$id][] = $chunk;

                if (\is_string($chunk) && null !== $response->content) {
                    // Buffer response body
                    fwrite($response->content, $chunk);
                }
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    protected static function select(\stdClass $multi, float $timeout): int
    {
        return 42;
    }

    /**
     * Simulates sending the request.
     */
    private static function writeRequest(self $response, array $options, ResponseInterface $mock)
    {
        $onProgress = $options['on_progress'] ?? static function () {};
        $response->info += $mock->getInfo() ?: [];

        // simulate "size_upload" if it is set
        if (isset($response->info['size_upload'])) {
            $response->info['size_upload'] = 0.0;
        }

        // simulate "total_time" if it is set
        if (isset($response->info['total_time'])) {
            $response->info['total_time'] = microtime(true) - $response->info['start_time'];
        }

        // "notify" DNS resolution
        $onProgress(0, 0, $response->info);

        // consume the request body
        if (\is_resource($body = $options['body'] ?? '')) {
            $data = stream_get_contents($body);
            if (isset($response->info['size_upload'])) {
                $response->info['size_upload'] += \strlen($data);
            }
        } elseif ($body instanceof \Closure) {
            while ('' !== $data = $body(16372)) {
                if (!\is_string($data)) {
                    throw new TransportException(sprintf('Return value of the "body" option callback must be string, %s returned.', \gettype($data)));
                }

                // "notify" upload progress
                if (isset($response->info['size_upload'])) {
                    $response->info['size_upload'] += \strlen($data);
                }

                $onProgress(0, 0, $response->info);
            }
        }
    }

    /**
     * Simulates reading the response.
     */
    private static function readResponse(self $response, array $options, ResponseInterface $mock, int &$offset)
    {
        $onProgress = $options['on_progress'] ?? static function () {};

        // populate info related to headers
        $info = $mock->getInfo() ?: [];
        $response->info['http_code'] = ($info['http_code'] ?? 0) ?: $mock->getStatusCode(false) ?: 200;
        $response->addRawHeaders($info['raw_headers'] ?? [], $response->info, $response->headers);
        $dlSize = (int) ($response->headers['content-length'][0] ?? 0);

        $response->info = [
            'start_time' => $response->info['start_time'],
            'user_data' => $response->info['user_data'],
            'http_code' => $response->info['http_code'],
        ] + $info + $response->info;

        if (isset($response->info['total_time'])) {
            $response->info['total_time'] = microtime(true) - $response->info['start_time'];
        }

        // "notify" headers arrival
        $onProgress(0, $dlSize, $response->info);

        // cast response body to activity list
        $body = $mock instanceof self ? $mock->body : $mock->getContent(false);

        if (!\is_string($body)) {
            foreach ($body as $chunk) {
                if ('' === $chunk = (string) $chunk) {
                    // simulate a timeout
                    $response->body[] = new ErrorChunk($offset);
                } else {
                    $response->body[] = $chunk;
                    $offset += \strlen($chunk);
                    // "notify" download progress
                    $onProgress($offset, $dlSize, $response->info);
                }
            }
        } elseif ('' !== $body) {
            $response->body[] = $body;
            $offset = \strlen($body);
        }

        if (isset($response->info['total_time'])) {
            $response->info['total_time'] = microtime(true) - $response->info['start_time'];
        }

        // "notify" completion
        $onProgress($offset, $dlSize, $response->info);

        if (isset($response->headers['content-length']) && $offset !== $dlSize) {
            throw new TransportException(sprintf('Transfer closed with %s bytes remaining to read.', $dlSize - $offset));
        }
    }
}

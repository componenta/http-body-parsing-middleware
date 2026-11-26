<?php

declare(strict_types=1);

namespace Componenta\Http\Middleware\Body;

use Componenta\Http\Middleware\BodyParsingException;
use Componenta\Http\Middleware\BodyParsingLimits;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;

/** Bounded MIME multipart parser with a shared streaming delimiter implementation. */
final readonly class MultipartParser
{
    public function __construct(private BodyParsingLimits $limits = new BodyParsingLimits()) {}

    /** @return list<MultipartPart> */
    public function parse(string $body, string $boundary): array
    {
        $this->validateBoundary($boundary);
        if (strlen($body) > $this->limits->maxBodyBytes) {
            throw BodyParsingException::tooLarge('Multipart body exceeds configured limit.');
        }
        $offset = 0;
        $reader = new MultipartStreamReader(static function (int $length) use ($body, &$offset): string {
            $chunk = substr($body, $offset, $length);
            $offset += strlen($chunk);
            return $chunk;
        }, $this->limits);
        $parts = [];
        foreach ($reader->parts($boundary) as [$headers, $resource]) {
            try {
                $contents = stream_get_contents($resource);
                if ($contents === false) {
                    throw new \RuntimeException('Could not read multipart part stream.');
                }
                $parts[] = $this->part($headers, $contents);
            } finally {
                fclose($resource);
            }
        }
        return $parts;
    }

    /**
     * The input must be positioned at the beginning of the body. Consume the
     * entire generator before accepting the request, including its epilogue.
     * The caller owns yielded PSR-7 streams.
     *
     * @internal
     * @return \Generator<int, array{MultipartPart, StreamInterface}>
     * The first item contains metadata only (its body is empty).
     */
    public function parseStream(
        StreamInterface $source,
        string $boundary,
        StreamFactoryInterface $factory,
        BodyParsingLimits $outerLimits,
    ): \Generator {
        $this->validateBoundary($boundary);
        $limits = new BodyParsingLimits(
            maxBodyBytes: min($this->limits->maxBodyBytes, $outerLimits->maxBodyBytes),
            maxPartBytes: min($this->limits->maxPartBytes, $outerLimits->maxPartBytes),
            maxParts: min($this->limits->maxParts, $outerLimits->maxParts),
            maxHeaderBytes: min($this->limits->maxHeaderBytes, $outerLimits->maxHeaderBytes),
            maxNestingDepth: min($this->limits->maxNestingDepth, $outerLimits->maxNestingDepth),
        );
        $reader = new MultipartStreamReader(static function (int $length) use ($source): string {
            $chunk = $source->read($length);
            if ($chunk === '' && !$source->eof()) {
                throw BodyParsingException::malformed('Request body stream made no progress.');
            }
            return $chunk;
        }, $limits);
        foreach ($reader->parts($boundary) as [$headers, $resource]) {
            try {
                $metadata = $this->part($headers, '');
                $stream = $factory->createStreamFromResource($resource);
            } catch (\Throwable $exception) {
                if (is_resource($resource)) {
                    fclose($resource);
                }
                throw $exception;
            }
            yield [$metadata, $stream];
        }
    }

    /** @param array<string, string> $headers */
    private function part(array $headers, string $contents): MultipartPart
    {
        $part = new MultipartPart($headers, $contents);
        if ($part->getName() === null) {
            throw BodyParsingException::malformed('Multipart form-data part requires a name.');
        }
        return $part;
    }

    public function extractBoundary(string $contentType): ?string
    {
        [$type, $parameters] = HeaderParameters::parse($contentType);
        if (!str_starts_with($type, 'multipart/')) {
            return null;
        }
        $boundary = $parameters['boundary'] ?? null;
        if ($boundary !== null) {
            $this->validateBoundary($boundary);
        }
        return $boundary;
    }

    private function validateBoundary(string $boundary): void
    {
        if (strlen($boundary) > 70 || str_ends_with($boundary, ' ')
            || preg_match("~^[0-9A-Za-z'()+_,./:=? -]+$~D", $boundary) !== 1) {
            throw BodyParsingException::malformed('Invalid multipart boundary.');
        }
    }
}

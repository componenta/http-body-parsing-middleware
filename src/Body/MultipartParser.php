<?php

declare(strict_types=1);

namespace Componenta\Http\Middleware\Body;

use Componenta\Http\Middleware\BodyParsingException;
use Componenta\Http\Middleware\BodyParsingLimits;

/**
 * Bounded MIME multipart parser. CRLF and bare LF delimiter lines are
 * supported, but incomplete or malformed multipart messages are rejected.
 */
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
        $delimiter = '--' . $boundary;
        $first = $this->findDelimiter($body, $delimiter, 0, true);
        if ($first === null) {
            throw BodyParsingException::malformed('Missing opening multipart delimiter.');
        }
        [, $offset, $closed] = $first;
        $parts = [];
        while (!$closed) {
            if (count($parts) >= $this->limits->maxParts) {
                throw BodyParsingException::tooLarge('Too many multipart parts.');
            }
            $next = $this->findDelimiter($body, $delimiter, $offset, false);
            if ($next === null) {
                throw BodyParsingException::malformed('Missing closing multipart delimiter.');
            }
            [$end, $after, $closed] = $next;
            if ($end < $offset) {
                throw BodyParsingException::malformed('Invalid multipart part.');
            }
            if ($end - $offset > $this->limits->maxPartBytes + $this->limits->maxHeaderBytes + 4.0) {
                throw BodyParsingException::tooLarge('Multipart part exceeds configured limit.');
            }
            $parts[] = $this->parsePart(substr($body, $offset, $end - $offset));
            $offset = $after;
        }
        return $parts;
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

    /** @return array{int, int, bool}|null */
    private function findDelimiter(string $body, string $delimiter, int $offset, bool $first): ?array
    {
        if ($first && str_starts_with($body, $delimiter)) {
            $line = $this->delimiterEnd($body, strlen($delimiter));
            if ($line !== null) {
                return [0, $line[0], $line[1]];
            }
        }
        $needle = "\n" . $delimiter;
        $length = strlen($body);
        while ($offset < $length && ($pos = strpos($body, $needle, $offset)) !== false) {
            $line = $this->delimiterEnd($body, $pos + strlen($needle));
            if ($line !== null) {
                $start = $pos > 0 && $body[$pos - 1] === "\r" ? $pos - 1 : $pos;
                return [$start, $line[0], $line[1]];
            }
            $offset = $pos + 1;
        }
        return null;
    }

    /** @return array{int, bool}|null */
    private function delimiterEnd(string $body, int $offset): ?array
    {
        $length = strlen($body);
        $closed = substr($body, $offset, 2) === '--';
        if ($closed) {
            $offset += 2;
        }
        while ($offset < $length && ($body[$offset] === ' ' || $body[$offset] === "\t")) {
            ++$offset;
        }
        if ($offset === $length) {
            return $closed ? [$offset, true] : null;
        }
        if (substr($body, $offset, 2) === "\r\n") {
            return [$offset + 2, $closed];
        }
        if ($body[$offset] === "\n") {
            return [$offset + 1, $closed];
        }
        return null;
    }

    private function parsePart(string $content): MultipartPart
    {
        // Search only the bounded header prefix, not the file contents.
        $prefix = substr($content, 0, $this->limits->maxHeaderBytes + 4);
        $crlf = strpos($prefix, "\r\n\r\n");
        $lf = strpos($prefix, "\n\n");
        if ($crlf !== false && ($lf === false || $crlf <= $lf)) {
            $separator = $crlf;
            $length = 4;
        } elseif ($lf !== false) {
            $separator = $lf;
            $length = 2;
        } else {
            if (strlen($content) > $this->limits->maxHeaderBytes) {
                throw BodyParsingException::tooLarge('Multipart headers exceed configured limit.');
            }
            throw BodyParsingException::malformed('Missing multipart header terminator.');
        }
        if ($separator > $this->limits->maxHeaderBytes) {
            throw BodyParsingException::tooLarge('Multipart headers exceed configured limit.');
        }
        if (strlen($content) - $separator - $length > $this->limits->maxPartBytes) {
            throw BodyParsingException::tooLarge('Multipart part exceeds configured limit.');
        }
        $block = substr($content, 0, $separator);
        $unfolded = preg_replace("/\r?\n[ \t]+/", ' ', $block);
        if ($unfolded === null) {
            throw BodyParsingException::malformed('Invalid multipart headers.');
        }
        $headers = [];
        foreach (explode("\n", $unfolded) as $line) {
            $line = rtrim($line, "\r");
            $pair = explode(':', $line, 2);
            if (count($pair) !== 2 || preg_match("@^[!#$%&'*+.^_\x60|~0-9A-Za-z-]+$@D", $pair[0]) !== 1) {
                throw BodyParsingException::malformed('Invalid multipart header name.');
            }
            $name = strtolower($pair[0]);
            $value = trim($pair[1], " \t");
            if (isset($headers[$name]) || preg_match('/[\x00-\x08\x0a-\x1f\x7f]/', $value) === 1) {
                throw BodyParsingException::malformed('Invalid or duplicate multipart header.');
            }
            $headers[$name] = $value;
        }
        $part = new MultipartPart($headers, substr($content, $separator + $length));
        if ($part->getName() === null) {
            throw BodyParsingException::malformed('Multipart form-data part requires a name.');
        }
        return $part;
    }
}

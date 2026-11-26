<?php

declare(strict_types=1);

namespace Componenta\Http\Middleware\Body;

use Componenta\Http\Middleware\BodyParsingException;
use Componenta\Http\Middleware\BodyParsingLimits;

/** @internal Single-use bounded reader. A read callback returns an empty string only at EOF. */
final class MultipartStreamReader
{
    private const int CHUNK_BYTES = 8192;
    private string $buffer = "\n";
    private int $readBytes = 0;
    private bool $eof = false;

    /** @param \Closure(int): string $read */
    public function __construct(private readonly \Closure $read, private readonly BodyParsingLimits $limits) {}

    /** @return \Generator<int, array{array<string, string>, resource}> The caller owns each yielded resource. */
    public function parts(string $boundary): \Generator
    {
        $needle = "\n--" . $boundary;
        $closed = $this->scan($needle, static function (string $data): void {});
        $count = 0;
        while (!$closed) {
            if (++$count > $this->limits->maxParts) {
                throw BodyParsingException::tooLarge('Too many multipart parts.');
            }
            $headers = $this->readHeaders();
            $resource = fopen('php://temp/maxmemory:65536', 'w+b');
            if ($resource === false) {
                throw new \RuntimeException('Could not create multipart part stream.');
            }
            $size = 0;
            try {
                $closed = $this->scan($needle, function (string $data) use ($resource, &$size): void {
                    $length = strlen($data);
                    if ($length > $this->limits->maxPartBytes - $size) {
                        throw BodyParsingException::tooLarge('Multipart part exceeds configured limit.');
                    }
                    if ($length !== 0 && fwrite($resource, $data) !== $length) {
                        throw new \RuntimeException('Could not write multipart part stream.');
                    }
                    $size += $length;
                });
                if (!rewind($resource)) {
                    throw new \RuntimeException('Could not rewind multipart part stream.');
                }
            } catch (\Throwable $exception) {
                fclose($resource);
                throw $exception;
            }
            yield [$headers, $resource];
        }
        // The epilogue is ignored, but counts toward the transport body limit.
        $this->buffer = '';
        while ($this->fill()) {
            $this->buffer = '';
        }
    }

    private function fill(): bool
    {
        if ($this->eof) {
            return false;
        }
        $remaining = $this->limits->maxBodyBytes - $this->readBytes;
        $chunk = ($this->read)(min(self::CHUNK_BYTES, $remaining + 1));
        if ($chunk === '') {
            $this->eof = true;
            return false;
        }
        if (strlen($chunk) > $remaining) {
            throw BodyParsingException::tooLarge('Multipart body exceeds configured limit.');
        }
        $this->readBytes += strlen($chunk);
        $this->buffer .= $chunk;
        return true;
    }

    private function ensure(int $length): bool
    {
        while (strlen($this->buffer) < $length && $this->fill()) {}
        return strlen($this->buffer) >= $length;
    }

    /** @param \Closure(string): void $emit */
    private function scan(string $needle, \Closure $emit): bool
    {
        // Retain the possible CR and incomplete delimiter at every chunk edge.
        $keep = strlen($needle) + 1;
        while (true) {
            $position = strpos($this->buffer, $needle);
            if ($position === false) {
                $length = strlen($this->buffer);
                if ($length > $keep) {
                    $emit(substr($this->buffer, 0, $length - $keep));
                    $this->buffer = substr($this->buffer, -$keep);
                }
                if (!$this->fill()) {
                    throw BodyParsingException::malformed('Missing multipart delimiter.');
                }
                continue;
            }
            $suffix = $this->delimiterEnd($position + strlen($needle));
            if ($suffix === null) {
                // This is body data, not a delimiter. Advance past its LF only.
                $emit(substr($this->buffer, 0, $position + 1));
                $this->buffer = substr($this->buffer, $position + 1);
                continue;
            }
            $end = $position > 0 && $this->buffer[$position - 1] === "\r" ? $position - 1 : $position;
            $emit(substr($this->buffer, 0, $end));
            [$after, $closed] = $suffix;
            $this->buffer = substr($this->buffer, $after);
            return $closed;
        }
    }

    /** @return array{int, bool}|null */
    private function delimiterEnd(int $offset): ?array
    {
        $this->ensure($offset + 2);
        $closed = substr($this->buffer, $offset, 2) === '--';
        if ($closed) {
            $offset += 2;
        }
        $padding = 0;
        while ($this->ensure($offset + 1)) {
            $char = $this->buffer[$offset];
            if ($char !== ' ' && $char !== "\t") {
                break;
            }
            if (++$padding > $this->limits->maxHeaderBytes) {
                throw BodyParsingException::tooLarge('Multipart delimiter padding exceeds configured limit.');
            }
            ++$offset;
        }
        if (!$this->ensure($offset + 1)) {
            return $closed ? [$offset, true] : null;
        }
        if ($this->buffer[$offset] === "\n") {
            return [$offset + 1, $closed];
        }
        if ($this->buffer[$offset] === "\r" && $this->ensure($offset + 2) && $this->buffer[$offset + 1] === "\n") {
            return [$offset + 2, $closed];
        }
        return null;
    }

    /** @return array<string, string> */
    private function readHeaders(): array
    {
        $block = '';
        while (true) {
            while (($end = strpos($this->buffer, "\n")) === false) {
                if (strlen($this->buffer) > $this->limits->maxHeaderBytes - strlen($block)) {
                    throw BodyParsingException::tooLarge('Multipart headers exceed configured limit.');
                }
                if (!$this->fill()) {
                    throw BodyParsingException::malformed('Missing multipart header terminator.');
                }
            }
            $line = substr($this->buffer, 0, $end + 1);
            $this->buffer = substr($this->buffer, $end + 1);
            if ($line === "\r\n" || $line === "\n") {
                break;
            }
            if (strlen($line) > $this->limits->maxHeaderBytes - strlen($block)) {
                throw BodyParsingException::tooLarge('Multipart headers exceed configured limit.');
            }
            $block .= $line;
        }
        $unfolded = preg_replace("/\r?\n[ \t]+/", ' ', rtrim($block, "\r\n"));
        if ($unfolded === null || $unfolded === '') {
            throw BodyParsingException::malformed('Missing multipart headers.');
        }
        $headers = [];
        foreach (explode("\n", $unfolded) as $line) {
            $pair = explode(':', str_ends_with($line, "\r") ? substr($line, 0, -1) : $line, 2);
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
        return $headers;
    }
}

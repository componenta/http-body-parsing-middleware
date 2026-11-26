<?php

declare(strict_types=1);

namespace Componenta\Http\Middleware;

final class BodyParsingException extends \RuntimeException
{
    private function __construct(public readonly int $status, string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, $status, $previous);
    }

    public static function malformed(string $message, ?\Throwable $previous = null): self
    {
        return new self(400, $message, $previous);
    }

    public static function tooLarge(string $message): self
    {
        return new self(413, $message);
    }
}

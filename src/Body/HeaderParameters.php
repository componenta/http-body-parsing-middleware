<?php

declare(strict_types=1);

namespace Componenta\Http\Middleware\Body;

use Componenta\Http\Middleware\BodyParsingException;

/** @internal */
final class HeaderParameters
{
    /** @return array{string, array<string, string>} */
    public static function parse(string $value): array
    {
        $length = strlen($value);
        $first = strpos($value, ';');
        if ($first === false) {
            return [strtolower(trim($value, " \t")), []];
        }
        $type = strtolower(trim(substr($value, 0, $first), " \t"));
        $parameters = [];
        $offset = $first;
        while ($offset < $length) {
            if ($value[$offset++] !== ';') {
                throw BodyParsingException::malformed('Invalid header parameter separator.');
            }
            self::skipOws($value, $offset);
            $start = $offset;
            while ($offset < $length && self::tokenChar($value[$offset])) {
                ++$offset;
            }
            $name = strtolower(substr($value, $start, $offset - $start));
            self::skipOws($value, $offset);
            if ($name === '' || isset($parameters[$name]) || $offset >= $length || $value[$offset++] !== '=') {
                throw BodyParsingException::malformed('Invalid or duplicate header parameter.');
            }
            self::skipOws($value, $offset);
            if ($offset >= $length) {
                throw BodyParsingException::malformed('Missing header parameter value.');
            }
            if ($value[$offset] === '"') {
                ++$offset;
                $parameter = '';
                $closed = false;
                while ($offset < $length) {
                    $char = $value[$offset++];
                    if ($char === '"') {
                        $closed = true;
                        break;
                    }
                    if ($char === '\\') {
                        if ($offset >= $length) {
                            break;
                        }
                        $char = $value[$offset++];
                    }
                    if ((ord($char) < 32 && $char !== "\t") || ord($char) === 127) {
                        throw BodyParsingException::malformed('Invalid quoted parameter.');
                    }
                    $parameter .= $char;
                }
                if (!$closed) {
                    throw BodyParsingException::malformed('Unterminated quoted parameter.');
                }
            } else {
                $start = $offset;
                while ($offset < $length && self::tokenChar($value[$offset])) {
                    ++$offset;
                }
                if ($offset === $start) {
                    throw BodyParsingException::malformed('Empty header parameter.');
                }
                $parameter = substr($value, $start, $offset - $start);
            }
            $parameters[$name] = $parameter;
            self::skipOws($value, $offset);
        }
        return [$type, $parameters];
    }

    private static function tokenChar(string $char): bool
    {
        return ctype_alnum($char) && ord($char) < 128
            || str_contains("!#$%&'*+-.^_\x60|~", $char);
    }

    private static function skipOws(string $value, int &$offset): void
    {
        $length = strlen($value);
        while ($offset < $length && ($value[$offset] === ' ' || $value[$offset] === "\t")) {
            ++$offset;
        }
    }
}

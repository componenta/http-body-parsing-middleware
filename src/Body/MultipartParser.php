<?php

declare(strict_types=1);

namespace Componenta\Http\Middleware\Body;

use RuntimeException;

/**
 * RFC-compliant multipart message parser.
 *
 * Parses multipart/* message bodies according to the MIME specification.
 * Primarily used for multipart/form-data in HTTP PUT/PATCH requests,
 * where PHP does not natively parse the body.
 *
 * @see RFC 2046 §5.1   - Multipart Media Type
 * @see RFC 2046 §5.1.1 - Common Syntax (boundary definition)
 * @see RFC 7578         - Returning Values from Forms: multipart/form-data
 */
final readonly class MultipartParser
{
    /**
     * Maximum boundary length per RFC 2046 §5.1.1.
     *
     * boundary := 0*69<bchars> bcharsnospace
     */
    private const int MAX_BOUNDARY_LENGTH = 70;

    /**
     * Parses a multipart message body into individual parts.
     *
     * Per RFC 2046 §5.1.1, the body is structured as:
     *
     *   preamble CRLF
     *   "--" boundary CRLF
     *   body-part
     *   *(CRLF "--" boundary CRLF body-part)
     *   CRLF "--" boundary "--" [transport-padding] CRLF
     *   epilogue
     *
     * The preamble and epilogue are ignored per RFC 2046 §5.1.1.
     *
     * Note: Line ending normalization is NOT applied to the entire body,
     * as this would corrupt binary content in file uploads. Instead,
     * boundaries are located using a pattern that tolerates both
     * CRLF and bare LF (common in real-world HTTP clients).
     *
     * @return list<MultipartPart>
     *
     * @throws RuntimeException If the boundary is invalid
     */
    public function parse(string $body, string $boundary): array
    {
        $this->validateBoundary($boundary);

        if ($body === '') {
            return [];
        }

        $delimiter = '--' . $boundary;

        // Find the first boundary (preamble before it is ignored per RFC 2046 §5.1.1)
        // Must validate that what follows the boundary is valid per RFC 2046.
        $start = $this->findFirstDelimiter($body, $delimiter);

        if ($start === false) {
            return [];
        }

        $parts = [];
        $offset = $start + strlen($delimiter);

        while ($offset < strlen($body)) {
            // Check for close delimiter ("--" immediately after boundary)
            if ($this->isCloseDelimiter($body, $offset)) {
                break;
            }

            // Skip transport padding and line ending after the delimiter
            $offset = $this->skipTransportPaddingAndLineEnding($body, $offset);

            if ($offset >= strlen($body)) {
                break;
            }

            // Find the next boundary: CRLF + delimiter or LF + delimiter
            $nextPos = $this->findNextDelimiter($body, $offset, $delimiter);

            if ($nextPos === false) {
                // Last part without proper closing - be lenient
                $partContent = substr($body, $offset);
            } else {
                $partContent = substr($body, $offset, $nextPos['start'] - $offset);
            }

            $part = $this->parsePart($partContent);

            if ($part !== null) {
                $parts[] = $part;
            }

            if ($nextPos === false) {
                break;
            }

            // Move past the line ending + delimiter
            $offset = $nextPos['end'];
        }

        return $parts;
    }

    /**
     * Extracts the boundary parameter from a Content-Type header value.
     *
     * Per RFC 2046 §5.1.1:
     *   boundary := 0*69<bchars> bcharsnospace
     *   bchars := bcharsnospace / " "
     *   bcharsnospace := DIGIT / ALPHA / "'" / "(" / ")" / "+" / "_"
     *                    / "," / "-" / "." / "/" / ":" / "=" / "?"
     *
     * The boundary may be quoted per RFC 7231 §3.1.1.1.
     */
    public function extractBoundary(string $contentType): ?string
    {
        // Match both quoted and unquoted boundary values
        // Unquoted branch excludes quotes, whitespace, and semicolons
        if (preg_match('/boundary\s*=\s*(?:"([^"]{1,70})"|([^\s;="]{1,70}))/i', $contentType, $matches)) {
            return ($matches[1] ?? '') !== '' ? $matches[1] : ($matches[2] ?? null);
        }

        return null;
    }

    /**
     * Checks if the current position marks a close delimiter.
     *
     * The close delimiter is "--" immediately after the boundary string,
     * forming: "--" boundary "--"
     */
    private function isCloseDelimiter(string $body, int $offset): bool
    {
        return $offset + 1 < strlen($body)
            && $body[$offset] === '-'
            && $body[$offset + 1] === '-';
    }

    /**
     * Finds the first boundary delimiter in the body.
     *
     * The first boundary may appear at the very start of the body (no preamble)
     * or after preamble text. Like subsequent delimiters, we must validate
     * that the boundary string is followed by valid delimiter characters.
     *
     * @return int|false Position of the first boundary, or false if not found
     */
    private function findFirstDelimiter(string $body, string $delimiter): int|false
    {
        $offset = 0;
        $bodyLen = strlen($body);
        $delimiterLen = strlen($delimiter);

        while ($offset < $bodyLen) {
            $pos = strpos($body, $delimiter, $offset);

            if ($pos === false) {
                return false;
            }

            $afterDelimiter = $pos + $delimiterLen;

            if ($this->isValidAfterDelimiter($body, $afterDelimiter)) {
                return $pos;
            }

            // False match, continue searching
            $offset = $afterDelimiter;
        }

        return false;
    }

    /**
     * Finds the next boundary delimiter after the given offset.
     *
     * Per RFC 2046 §5.1.1, a delimiter line consists of:
     *   CRLF "--" boundary [transport-padding] CRLF
     * or for the close delimiter:
     *   CRLF "--" boundary "--"
     *
     * After finding "--" boundary in the body, we must verify that
     * what follows is valid (CRLF, "--", transport padding, or EOF).
     * Otherwise it's just body content that happens to contain the
     * boundary string as a prefix.
     *
     * @return array{start: int, end: int}|false Start = position of line ending before delimiter,
     *                                            End = position after the delimiter string
     */
    private function findNextDelimiter(string $body, int $offset, string $delimiter): array|false
    {
        $bodyLen = strlen($body);
        $delimiterLen = strlen($delimiter);
        $searchOffset = $offset;

        while ($searchOffset < $bodyLen) {
            // Try CRLF + delimiter
            $crlfPos = strpos($body, "\r\n" . $delimiter, $searchOffset);
            // Try bare LF + delimiter
            $lfPos = strpos($body, "\n" . $delimiter, $searchOffset);

            if ($crlfPos === false && $lfPos === false) {
                return false;
            }

            // Pick the first occurrence, preferring CRLF if tied
            if ($crlfPos !== false && ($lfPos === false || $crlfPos <= $lfPos)) {
                $lineEndLen = 2;
                $matchStart = $crlfPos;
            } else {
                $lineEndLen = 1;
                $matchStart = $lfPos;
            }

            $afterDelimiter = $matchStart + $lineEndLen + $delimiterLen;

            // Validate: after "--boundary", RFC 2046 allows only:
            // "--" (close), CRLF, transport-padding (SP/HTAB), bare LF, or EOF
            if ($this->isValidAfterDelimiter($body, $afterDelimiter)) {
                return [
                    'start' => $matchStart,
                    'end' => $afterDelimiter,
                ];
            }

            // False match - body content happened to contain the boundary prefix.
            // Continue searching after this false match.
            $searchOffset = $afterDelimiter;
        }

        return false;
    }

    /**
     * Validates that the character(s) after a potential boundary delimiter
     * are consistent with a real delimiter line per RFC 2046 §5.1.1.
     *
     * Valid continuations:
     * - EOF (end of body)
     * - "--" (close delimiter)
     * - "\r\n" or "\n" (line ending -> part follows)
     * - SP or HTAB (transport padding, eventually followed by line ending)
     */
    private function isValidAfterDelimiter(string $body, int $offset): bool
    {
        // EOF - valid (lenient, end of body)
        if ($offset >= strlen($body)) {
            return true;
        }

        $char = $body[$offset];

        // Close delimiter: must be "--" (two hyphens), not just one
        if ($char === '-') {
            return ($offset + 1 < strlen($body)) && $body[$offset + 1] === '-';
        }

        return $char === "\r"  // CRLF line ending
            || $char === "\n"  // bare LF line ending
            || $char === ' '   // transport padding
            || $char === "\t"; // transport padding
    }

    /**
     * Parses a single body-part into headers and body.
     *
     * Per RFC 2046 §5.1.1, each body-part consists of:
     *   header-fields CRLF CRLF body
     *
     * Headers are separated from the body by a blank line.
     * We accept both CRLF CRLF and LF LF for robustness.
     *
     * The separator that appears FIRST is used, to correctly handle
     * the case where headers use LF but the body contains CRLF CRLF
     * (or vice versa).
     */
    private function parsePart(string $content): ?MultipartPart
    {
        // RFC 2046 §5.1.1: headers and body separated by blank line.
        // If content starts with a blank line, there are no headers.
        if (str_starts_with($content, "\r\n")) {
            return new MultipartPart([], substr($content, 2));
        }

        if (str_starts_with($content, "\n")) {
            return new MultipartPart([], substr($content, 1));
        }

        // Find both possible separators and use whichever comes first,
        // since the header/body boundary always precedes body content.
        $crlfPos = strpos($content, "\r\n\r\n");
        $lfPos = strpos($content, "\n\n");

        $separatorPos = false;
        $separatorLen = 0;

        if ($crlfPos !== false && ($lfPos === false || $crlfPos <= $lfPos)) {
            $separatorPos = $crlfPos;
            $separatorLen = 4;
        } elseif ($lfPos !== false) {
            $separatorPos = $lfPos;
            $separatorLen = 2;
        }

        if ($separatorPos === false) {
            // No headers - treat entire content as body
            return new MultipartPart([], $content);
        }

        $headerBlock = substr($content, 0, $separatorPos);
        $body = substr($content, $separatorPos + $separatorLen);

        $headers = $this->parseHeaders($headerBlock);

        // Per RFC 7578 §4.2, parts MUST have Content-Disposition: form-data
        $disposition = $headers['content-disposition'] ?? '';

        if ($disposition !== '' && !str_contains(strtolower($disposition), 'form-data')) {
            return null;
        }

        return new MultipartPart($headers, $body);
    }

    /**
     * Parses header fields with support for line folding.
     *
     * Per RFC 7230 §3.2.4, obsolete line folding (obs-fold) consists of
     * CRLF followed by at least one SP or HTAB. While deprecated in HTTP/1.1,
     * we handle it for robustness.
     *
     * @return array<string, string> Lowercase header name => value
     *
     * @see RFC 7230 §3.2 - Header Fields
     */
    private function parseHeaders(string $headerBlock): array
    {
        $headers = [];

        // Unfold headers per RFC 7230 §3.2.4 (obs-fold)
        // Handle both CRLF and LF folding
        $headerBlock = preg_replace("/\r?\n[\t ]+/", ' ', $headerBlock);

        // Split on CRLF or LF
        foreach (preg_split("/\r?\n/", $headerBlock) as $line) {
            $colonPos = strpos($line, ':');

            if ($colonPos === false) {
                continue;
            }

            // RFC 7230 §3.2: field-name = token (no whitespace before colon)
            $name = strtolower(trim(substr($line, 0, $colonPos)));
            $value = trim(substr($line, $colonPos + 1));

            $headers[$name] = $value;
        }

        return $headers;
    }

    /**
     * Validates the boundary string per RFC 2046 §5.1.1.
     *
     * @throws RuntimeException If the boundary is invalid
     */
    private function validateBoundary(string $boundary): void
    {
        if ($boundary === '') {
            throw new RuntimeException('Multipart boundary must not be empty');
        }

        if (strlen($boundary) > self::MAX_BOUNDARY_LENGTH) {
            throw new RuntimeException(sprintf(
                'Multipart boundary exceeds maximum length of %d characters per RFC 2046 §5.1.1',
                self::MAX_BOUNDARY_LENGTH,
            ));
        }

        // RFC 2046 §5.1.1: boundary must not end with a space
        if (str_ends_with($boundary, ' ')) {
            throw new RuntimeException(
                'Multipart boundary must not end with a space per RFC 2046 §5.1.1',
            );
        }

        // RFC 2046 §5.1.1: allowed characters
        if (!preg_match('/^[0-9a-zA-Z\'()+_,\-.\/:=? ]+$/', $boundary)) {
            throw new RuntimeException(
                'Multipart boundary contains invalid characters per RFC 2046 §5.1.1',
            );
        }
    }

    /**
     * Skips transport padding and line ending after a boundary delimiter.
     *
     * Per RFC 2046 §5.1.1:
     *   transport-padding := *LWSP-char
     *   ; Composers MUST NOT generate non-zero length transport padding,
     *   ; but receivers MUST be able to handle it.
     *
     * LWSP-char is SP or HTAB.
     * The line ending after transport padding can be CRLF or bare LF.
     */
    private function skipTransportPaddingAndLineEnding(string $body, int $offset): int
    {
        $length = strlen($body);

        // Skip SP and HTAB (transport-padding)
        while ($offset < $length && ($body[$offset] === ' ' || $body[$offset] === "\t")) {
            $offset++;
        }

        // Skip line ending: CRLF or bare LF
        if ($offset < $length && $body[$offset] === "\r") {
            $offset++;
        }

        if ($offset < $length && $body[$offset] === "\n") {
            $offset++;
        }

        return $offset;
    }
}

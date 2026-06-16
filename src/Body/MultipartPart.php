<?php

declare(strict_types=1);

namespace Componenta\Http\Middleware\Body;

/**
 * Represents a single part of a multipart message.
 *
 * @see RFC 2046 §5.1 - Multipart Media Type
 * @see RFC 7578 §4   - Definition of multipart/form-data
 */
final readonly class MultipartPart
{
    /**
     * @param array<string, string> $headers Lowercase header name => value
     */
    public function __construct(
        public array $headers,
        public string $body,
    ) {}

    /**
     * Extracts the "name" parameter from Content-Disposition.
     *
     * Per RFC 7578 §4.2, each part MUST contain a Content-Disposition
     * header field of type "form-data" with a "name" parameter.
     */
    public function getName(): ?string
    {
        return $this->getDispositionParam('name');
    }

    /**
     * Extracts the "filename" parameter from Content-Disposition.
     *
     * Per RFC 7578 §4.2, file inputs include a "filename" parameter.
     * If "filename*" (RFC 8187) is present, it takes precedence.
     */
    public function getFilename(): ?string
    {
        return $this->getDispositionParam('filename*')
            ?? $this->getDispositionParam('filename');
    }

    public function isFile(): bool
    {
        return $this->getFilename() !== null;
    }

    /**
     * Returns the Content-Type of this part.
     *
     * Per RFC 7578 §4.4, the default Content-Type for parts
     * is "text/plain" when no Content-Type header is present.
     */
    public function getContentType(): string
    {
        return $this->headers['content-type'] ?? 'text/plain';
    }

    /**
     * Returns the decoded body content.
     *
     * Per RFC 7578 §4.7, Content-Transfer-Encoding is deprecated for
     * HTTP but may still appear. We support base64 and quoted-printable
     * for interoperability.
     *
     * @see RFC 2046 §6 - Content-Transfer-Encoding
     */
    public function getDecodedBody(): string
    {
        $encoding = strtolower($this->headers['content-transfer-encoding'] ?? '7bit');

        return match ($encoding) {
            'base64' => $this->decodeBase64($this->body),
            'quoted-printable' => quoted_printable_decode($this->body),
            default => $this->body,
        };
    }

    /**
     * Decodes base64 content with strict validation.
     *
     * Uses explicit false check instead of `?:` to correctly handle
     * the case where base64 decodes to an empty string (valid result).
     */
    private function decodeBase64(string $data): string
    {
        $decoded = base64_decode($data, true);

        if ($decoded === false) {
            return $data;
        }

        return $decoded;
    }

    /**
     * Extracts a parameter value from the Content-Disposition header.
     *
     * Supports both quoted and unquoted parameter values per RFC 6266 §4.1.
     * Supports extended parameter values (param*) per RFC 8187.
     *
     * @see RFC 6266 - Use of the Content-Disposition Header Field
     * @see RFC 8187 - Indicating Character Encoding and Language for HTTP Header Field Parameters
     */
    private function getDispositionParam(string $param): ?string
    {
        $disposition = $this->headers['content-disposition'] ?? '';

        if ($disposition === '') {
            return null;
        }

        // RFC 8187: extended parameter values (e.g., filename*=UTF-8''encoded%20name)
        if (str_ends_with($param, '*')) {
            $pattern = '/(?:^|;\s*)' . preg_quote($param, '/') . '\s*=\s*([^\s;]+)/i';

            if (preg_match($pattern, $disposition, $matches)) {
                return $this->decodeExtendedValue($matches[1]);
            }

            return null;
        }

        // RFC 6266 §4.1: quoted-string or token
        // The (?:^|;\s*) anchor ensures we match the exact parameter name
        // and don't accidentally match substrings (e.g., "name" inside "filename")
        $pattern = '/(?:^|;\s*)' . preg_quote($param, '/') . '\s*=\s*(?:"([^"\\\\]*(?:\\\\.[^"\\\\]*)*)"|([^\s;]+))/i';

        if (preg_match($pattern, $disposition, $matches, PREG_UNMATCHED_AS_NULL)) {
            // Quoted value: unescape quoted-pairs per RFC 7230 §3.2.6
            // quoted-pair = "\" ( HTAB / SP / VCHAR / obs-text )
            // We strip the backslash only, preserving the escaped character
            if ($matches[1] !== null) {
                return preg_replace('/\\\\(.)/', '$1', $matches[1]);
            }

            return $matches[2];
        }

        return null;
    }

    /**
     * Decodes an RFC 8187 extended parameter value.
     *
     * Format: charset'language'value
     * Only UTF-8 charset is required to be supported.
     *
     * @see RFC 8187 §3.2
     */
    private function decodeExtendedValue(string $value): string
    {
        $parts = explode("'", $value, 3);

        if (count($parts) !== 3) {
            return $value;
        }

        $decoded = rawurldecode($parts[2]);

        // Convert to UTF-8 if a different charset is specified
        $charset = strtoupper($parts[0]);

        if ($charset !== 'UTF-8' && $charset !== '') {
            try {
                $converted = mb_convert_encoding($decoded, 'UTF-8', $charset);

                if ($converted !== false) {
                    return $converted;
                }
            } catch (\ValueError) {
                // Unknown charset - fall through to return raw decoded value.
                // PHP 8.x throws ValueError for invalid encoding names.
            }
        }

        return $decoded;
    }
}

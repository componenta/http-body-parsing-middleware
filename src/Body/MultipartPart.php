<?php

declare(strict_types=1);

namespace Componenta\Http\Middleware\Body;

use Componenta\Http\Middleware\BodyParsingException;

final readonly class MultipartPart
{
    /** @var array<string, string> */
    private array $parameters;

    /** @param array<string, string> $headers Lowercase header names. */
    public function __construct(public array $headers, public string $body)
    {
        [$disposition, $parameters] = HeaderParameters::parse($headers['content-disposition'] ?? '');
        $this->parameters = $disposition === 'form-data' ? $parameters : [];
    }

    public function getName(): ?string
    {
        return $this->parameters['name'] ?? null;
    }

    public function getFilename(): ?string
    {
        // RFC 7578 prohibits filename*. Retain explicit legacy compatibility
        // for existing clients, but parse parameters structurally.
        if (isset($this->parameters['filename*'])) {
            return $this->decodeExtendedValue($this->parameters['filename*']);
        }
        return $this->parameters['filename'] ?? null;
    }

    public function isFile(): bool
    {
        return $this->getFilename() !== null;
    }

    public function getContentType(): string
    {
        return $this->headers['content-type'] ?? 'text/plain';
    }

    public function getDecodedBody(): string
    {
        $encoding = strtolower($this->headers['content-transfer-encoding'] ?? 'binary');
        return match ($encoding) {
            'base64' => $this->decodeBase64($this->body),
            'quoted-printable' => quoted_printable_decode($this->body),
            '7bit', '8bit', 'binary' => $this->body,
            default => throw BodyParsingException::malformed('Unsupported multipart transfer encoding.'),
        };
    }

    private function decodeBase64(string $data): string
    {
        $decoded = base64_decode($data, true);
        if ($decoded === false) {
            throw BodyParsingException::malformed('Invalid base64 multipart content.');
        }
        return $decoded;
    }

    private function decodeExtendedValue(string $value): string
    {
        $parts = explode("'", $value, 3);
        if (count($parts) !== 3 || $parts[0] === '' || preg_match('/%(?![0-9A-Fa-f]{2})/', $parts[2]) === 1) {
            throw BodyParsingException::malformed('Invalid extended multipart filename.');
        }
        $decoded = rawurldecode($parts[2]);
        try {
            if (!mb_check_encoding($decoded, $parts[0])) {
                throw BodyParsingException::malformed('Invalid extended multipart filename encoding.');
            }
            return mb_convert_encoding($decoded, 'UTF-8', $parts[0]);
        } catch (\ValueError $exception) {
            throw BodyParsingException::malformed('Invalid extended multipart filename charset.', $exception);
        }
    }
}

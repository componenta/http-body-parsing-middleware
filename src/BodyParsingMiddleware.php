<?php

declare(strict_types=1);

namespace Componenta\Http\Middleware;

use JsonException;
use Componenta\Http\Middleware\Body\MultipartParser;
use Componenta\Http\Middleware\Body\MultipartPart;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Parses request bodies for methods where PHP does not natively populate
 * $_POST and $_FILES (PUT, PATCH, DELETE), as well as POST requests
 * with content types that PHP does not handle (e.g., application/json).
 *
 * Supported content types:
 *
 * - application/json                (RFC 8259)
 * - application/x-www-form-urlencoded (WHATWG URL Standard §5)
 * - multipart/form-data             (RFC 7578, RFC 2046)
 *
 * @see RFC 9110 §6.4.1 - Associating a Body with a Method
 * @see RFC 9110 §8.3   - Content-Type
 */
final class BodyParsingMiddleware implements MiddlewareInterface
{
    /** @var array<string, callable(ServerRequestInterface): ServerRequestInterface> */
    private array $parsers;

    /**
     * Content types that PHP natively parses for POST requests.
     *
     * PHP populates $_POST and $_FILES for these types on POST only.
     * We skip these combinations to avoid double-parsing.
     *
     * @see https://www.php.net/manual/en/ini.core.php#ini.enable-post-data-reading
     */
    private const array PHP_NATIVE_POST_TYPES = [
        'application/x-www-form-urlencoded',
        'multipart/form-data',
    ];

    public function __construct(
        private readonly StreamFactoryInterface $streamFactory,
        private readonly UploadedFileFactoryInterface $uploadedFileFactory,
        private readonly MultipartParser $multipartParser = new MultipartParser(),
    ) {
        $this->parsers = [
            'application/json' => $this->parseJson(...),
            'application/x-www-form-urlencoded' => $this->parseFormUrlEncoded(...),
            'multipart/form-data' => $this->parseMultipart(...),
        ];
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->shouldSkip($request)) {
            return $handler->handle($request);
        }

        $mediaType = $this->extractMediaType($request->getHeaderLine('Content-Type'));

        foreach ($this->parsers as $type => $parser) {
            if ($mediaType === $type) {
                $request = $parser($request);
                break;
            }
        }

        return $handler->handle($request);
    }

    /**
     * Determines whether body parsing should be skipped.
     *
     * Skips if:
     * - Body already parsed (getParsedBody() !== null)
     * - Method has no defined body semantics per RFC 9110 §9
     * - PHP already natively parsed this POST request (form-urlencoded / multipart)
     */
    private function shouldSkip(ServerRequestInterface $request): bool
    {
        if ($request->getParsedBody() !== null) {
            return true;
        }

        // RFC 9110 §9.3.1/§9.3.5: These methods have no defined body semantics
        if (in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS', 'TRACE'], true)) {
            return true;
        }

        // PHP natively parses form-urlencoded and multipart for POST.
        // The body stream (php://input) is empty for multipart POST,
        // so re-parsing would yield nothing. We skip only these specific
        // combinations; other POST content types (e.g., JSON) are parsed.
        if ($request->getMethod() === 'POST') {
            $mediaType = $this->extractMediaType($request->getHeaderLine('Content-Type'));

            if (array_any(self::PHP_NATIVE_POST_TYPES, static fn($type) => $mediaType === $type)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Parses an application/json request body.
     *
     * Per RFC 8259 §8.1, JSON text MUST be encoded using UTF-8.
     * Per RFC 8259 §2, a JSON text is a serialized value which can be
     * an object, array, number, string, or one of the literals.
     *
     * PSR-7's withParsedBody() accepts null|array|object. For scalar
     * JSON values (string, number, boolean) and null, we store the
     * decoded value under the "__scalar" key to preserve the data
     * while remaining PSR-7 compliant. Consumers should check for
     * this key when expecting non-object/array JSON payloads.
     *
     * @see RFC 8259 - The JavaScript Object Notation (JSON) Data Interchange Format
     *
     * @throws JsonException If the body contains invalid JSON
     */
    private function parseJson(ServerRequestInterface $request): ServerRequestInterface
    {
        $body = $this->readBody($request);

        if ($body === '') {
            return $request;
        }

        $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        // PSR-7 withParsedBody() accepts null|array|object.
        // Wrap scalar values to stay compliant while preserving data.
        if (!is_array($data)) {
            $data = ['__scalar' => $data];
        }

        return $request->withParsedBody($data);
    }

    /**
     * Parses an application/x-www-form-urlencoded request body.
     *
     * Uses PHP's parse_str() which follows the WHATWG URL Standard §5
     * for parsing application/x-www-form-urlencoded data, including
     * support for nested keys (e.g., foo[bar]=baz).
     *
     * @see https://url.spec.whatwg.org/#urlencoded-parsing
     */
    private function parseFormUrlEncoded(ServerRequestInterface $request): ServerRequestInterface
    {
        $body = $this->readBody($request);

        if ($body === '') {
            return $request;
        }

        parse_str($body, $data);

        return $request->withParsedBody($data);
    }

    /**
     * Parses a multipart/form-data request body.
     *
     * Delegates to MultipartParser for RFC 2046/7578 compliant parsing,
     * then maps the parsed parts to PSR-7 parsed body and uploaded files.
     *
     * Per RFC 7578 §4.3, multiple files with the same name are supported.
     * Per RFC 7578 §4.4, the Content-Type of each file part defaults
     * to "text/plain" if not specified.
     *
     * @see RFC 7578 - Returning Values from Forms: multipart/form-data
     * @see RFC 2046 §5.1 - Multipart Media Type
     */
    private function parseMultipart(ServerRequestInterface $request): ServerRequestInterface
    {
        $contentType = $request->getHeaderLine('Content-Type');
        $boundary = $this->multipartParser->extractBoundary($contentType);

        if ($boundary === null) {
            return $request;
        }

        $body = $this->readBody($request);

        if ($body === '') {
            return $request;
        }

        $parts = $this->multipartParser->parse($body, $boundary);

        $params = [];
        $files = [];

        foreach ($parts as $part) {
            $name = $part->getName();

            if ($name === null) {
                // RFC 7578 §4.2: name parameter is required, skip invalid parts
                continue;
            }

            if ($part->isFile()) {
                $this->assignNestedValue(
                    $files,
                    $name,
                    $this->createUploadedFile($part),
                );
            } else {
                // RFC 7578 §5.1: text field values use the decoded body
                $this->assignNestedValue($params, $name, $part->getDecodedBody());
            }
        }

        $request = $request->withParsedBody($params !== [] ? $params : null);

        if ($files !== []) {
            $request = $request->withUploadedFiles($files);
        }

        return $request;
    }

    /**
     * Creates a PSR-7 UploadedFile from a parsed multipart part.
     *
     * Per RFC 7578 §4.4, the default Content-Type for file parts
     * is "text/plain" if no Content-Type header is present.
     * MultipartPart::getContentType() returns this default.
     */
    private function createUploadedFile(MultipartPart $part): UploadedFileInterface
    {
        $body = $part->getDecodedBody();
        $stream = $this->streamFactory->createStream($body);

        return $this->uploadedFileFactory->createUploadedFile(
            $stream,
            $stream->getSize(),
            UPLOAD_ERR_OK,
            $part->getFilename(),
            $part->getContentType(),
        );
    }

    /**
     * Safely reads the request body stream.
     *
     * PSR-7 streams may not be rewindable, and a prior middleware
     * may have already consumed the stream. We attempt to rewind
     * before reading to handle this case gracefully.
     *
     * @see PSR-7 §1.3 - Streams
     */
    private function readBody(ServerRequestInterface $request): string
    {
        $body = $request->getBody();

        if ($body->isSeekable()) {
            $body->rewind();
        }

        return $body->getContents();
    }

    /**
     * Extracts the media type (type/subtype) from a Content-Type header.
     *
     * Per RFC 9110 §8.3.1:
     *   Content-Type = media-type
     *   media-type   = type "/" subtype *( OWS ";" OWS parameter )
     *
     * This strips parameters (like charset) and normalizes to lowercase
     * for reliable comparison. Prevents false matches like
     * "application/json-patch+json" matching "application/json".
     */
    private function extractMediaType(string $contentType): string
    {
        $semicolonPos = strpos($contentType, ';');

        if ($semicolonPos !== false) {
            $contentType = substr($contentType, 0, $semicolonPos);
        }

        return strtolower(trim($contentType));
    }

    /**
     * Assigns a value into a nested array using PHP's bracket notation.
     *
     * Supports field names like:
     * - "simple"         -> $target['simple'] = $value
     * - "arr[]"          -> $target['arr'][] = $value
     * - "nested[a][b]"  -> $target['nested']['a']['b'] = $value
     *
     * This mirrors PHP's native behavior for $_POST and $_FILES.
     */
    private function assignNestedValue(array &$target, string $name, mixed $value): void
    {
        if (!str_contains($name, '[')) {
            $target[$name] = $value;

            return;
        }

        // Extract base name before first [
        if (!preg_match('/^([^[]+)/', $name, $base)) {
            // Name starts with [ (e.g., "[weird]") - invalid per HTML spec,
            // skip silently to match PHP's $_POST behavior which also ignores these
            return;
        }

        preg_match_all('/\[([^]]*)]/', $name, $keys);

        $current = &$target;

        if (!isset($current[$base[1]]) || !is_array($current[$base[1]])) {
            $current[$base[1]] = [];
        }

        $current = &$current[$base[1]];

        foreach ($keys[1] as $key) {
            if (!is_array($current)) {
                $current = [];
            }

            if ($key === '') {
                $current[] = null;
                end($current);
                $key = key($current);
            }

            if (!isset($current[$key]) || !is_array($current[$key])) {
                $current[$key] = [];
            }

            $current = &$current[$key];
        }

        $current = $value;
    }
}

<?php

declare(strict_types=1);

namespace Componenta\Http\Middleware;

use Componenta\Http\Middleware\Body\HeaderParameters;
use Componenta\Http\Middleware\Body\MultipartParser;
use Componenta\Http\Middleware\Body\MultipartPart;
use JsonException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class BodyParsingMiddleware implements MiddlewareInterface
{
    /** Root JSON kind; distinguishes scalar wrappers from user objects. */
    public const string ATTR_JSON_KIND = 'componenta.body-parsing.json-kind';

    private MultipartParser $multipartParser;

    public function __construct(
        private StreamFactoryInterface $streamFactory,
        private UploadedFileFactoryInterface $uploadedFileFactory,
        ?MultipartParser $multipartParser = null,
        private BodyParsingLimits $limits = new BodyParsingLimits(),
        private ?ResponseFactoryInterface $responseFactory = null,
    ) {
        $this->multipartParser = $multipartParser ?? new MultipartParser($limits);
    }

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getParsedBody() !== null || in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS', 'TRACE'], true)) {
            return $handler->handle($request);
        }
        if (!$request->hasHeader('Content-Type')) {
            return $handler->handle($request);
        }
        try {
            $contentTypes = $request->getHeader('Content-Type');
            if (count($contentTypes) !== 1) {
                throw BodyParsingException::malformed('Ambiguous Content-Type.');
            }
            $contentType = $contentTypes[0];
            if (strlen($contentType) > $this->limits->maxHeaderBytes) {
                throw BodyParsingException::tooLarge('Content-Type exceeds configured limit.');
            }
            [$mediaType] = HeaderParameters::parse($contentType);
            if (preg_match("@^[!#$%&'*+.^_\x60|~0-9a-z-]+/[!#$%&'*+.^_\x60|~0-9a-z-]+$@D", $mediaType) !== 1) {
                throw BodyParsingException::malformed('Invalid Content-Type.');
            }
            if (!in_array($mediaType, ['application/json', 'application/x-www-form-urlencoded', 'multipart/form-data'], true)
                || ($mediaType === 'multipart/form-data' && $request->getUploadedFiles() !== [])) {
                return $handler->handle($request);
            }
            [$request, $body] = $this->readBody($request);
            if ($body !== '') {
                $request = match ($mediaType) {
                    'application/json' => $this->parseJson($request, $body),
                    'application/x-www-form-urlencoded' => $this->parseForm($request, $body),
                    'multipart/form-data' => $this->parseMultipart($request, $body),
                };
            }
        } catch (BodyParsingException $exception) {
            if ($this->responseFactory === null) {
                throw $exception;
            }
            return $this->responseFactory->createResponse($exception->status)
                ->withHeader('Cache-Control', 'no-store');
        }
        return $handler->handle($request);
    }

    private function parseJson(ServerRequestInterface $request, string $body): ServerRequestInterface
    {
        try {
            $data = json_decode($body, true, $this->limits->maxNestingDepth, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException $exception) {
            throw BodyParsingException::malformed('Invalid JSON request body.', $exception);
        }
        $kind = match (ltrim($body, " \t\r\n")[0] ?? '') {
            '{' => 'object',
            '[' => 'array',
            default => 'scalar',
        };
        return $request
            ->withParsedBody(is_array($data) ? $data : ['__scalar' => $data])
            ->withAttribute(self::ATTR_JSON_KIND, $kind);
    }

    private function parseForm(ServerRequestInterface $request, string $body): ServerRequestInterface
    {
        $separators = ini_get('arg_separator.input') ?: '&';
        $nativeMax = (int) ini_get('max_input_vars');
        $nativeDepth = (int) ini_get('max_input_nesting_level');
        $maxFields = $nativeMax > 0 ? min($this->limits->maxParts, $nativeMax) : $this->limits->maxParts;
        $fields = preg_split('/[' . preg_quote($separators, '/') . ']/', $body, $maxFields + 1);
        if ($fields === false) {
            throw BodyParsingException::malformed('Invalid form body.');
        }
        if (count($fields) > $maxFields) {
            throw BodyParsingException::tooLarge('Too many form fields.');
        }
        foreach ($fields as $field) {
            $name = urldecode(explode('=', $field, 2)[0]);
            if (str_contains($name, "\0")) {
                throw BodyParsingException::malformed('Invalid form field name.');
            }
            $depth = substr_count($name, '[');
            if ($depth > $this->limits->maxNestingDepth || ($nativeDepth > 0 && $depth > $nativeDepth)) {
                throw BodyParsingException::tooLarge('Form nesting exceeds configured limit.');
            }
        }
        parse_str($body, $data);
        return $request->withParsedBody($data);
    }

    private function parseMultipart(ServerRequestInterface $request, string $body): ServerRequestInterface
    {
        $boundary = $this->multipartParser->extractBoundary($request->getHeaderLine('Content-Type'));
        if ($boundary === null) {
            throw BodyParsingException::malformed('Multipart boundary is required.');
        }
        $parts = $this->multipartParser->parse($body, $boundary);
        if (count($parts) > $this->limits->maxParts) {
            throw BodyParsingException::tooLarge('Too many multipart parts.');
        }
        $fields = [];
        $files = [];
        foreach ($parts as $part) {
            $name = $part->getName();
            if ($name === null) {
                throw BodyParsingException::malformed('Multipart field name is required.');
            }
            $decoded = $part->getDecodedBody();
            if (strlen($decoded) > $this->limits->maxPartBytes) {
                throw BodyParsingException::tooLarge('Multipart part exceeds configured limit.');
            }
            if ($part->isFile()) {
                $this->assign($files, $name, $this->createUpload($part, $decoded), true);
            } else {
                $this->assign($fields, $name, $decoded, false);
            }
        }
        $request = $request->withParsedBody($fields);
        return $files === [] ? $request : $request->withUploadedFiles($files);
    }

    private function createUpload(MultipartPart $part, string $data): UploadedFileInterface
    {
        $filename = $part->getFilename();
        $stream = $this->streamFactory->createStream($data);
        return $this->uploadedFileFactory->createUploadedFile(
            $stream,
            $stream->getSize(),
            $filename === '' ? UPLOAD_ERR_NO_FILE : UPLOAD_ERR_OK,
            $filename,
            $part->getContentType(),
        );
    }

    /** @return array{ServerRequestInterface, string} */
    private function readBody(ServerRequestInterface $request): array
    {
        $stream = $request->getBody();
        $size = $stream->getSize();
        if ($size !== null && $size > $this->limits->maxBodyBytes) {
            throw BodyParsingException::tooLarge('Request body exceeds configured limit.');
        }
        $position = null;
        if ($stream->isSeekable()) {
            $position = $stream->tell();
            $stream->rewind();
        }
        $body = '';
        try {
            while (!$stream->eof()) {
                $remaining = $this->limits->maxBodyBytes - strlen($body);
                $chunk = $stream->read(min(8192, $remaining + 1));
                if ($chunk === '' && !$stream->eof()) {
                    throw BodyParsingException::malformed('Request body stream made no progress.');
                }
                $body .= $chunk;
                if (strlen($body) > $this->limits->maxBodyBytes) {
                    throw BodyParsingException::tooLarge('Request body exceeds configured limit.');
                }
            }
        } finally {
            if ($position !== null) {
                $stream->seek($position);
            }
        }
        if ($position === null) {
            $request = $request->withBody($this->streamFactory->createStream($body));
        }
        return [$request, $body];
    }

    /** @param array<array-key, mixed> $target */
    private function assign(array &$target, string $name, mixed $value, bool $upload): void
    {
        $name = ltrim($name, ' ');
        if ($name === '') {
            return;
        }
        if (preg_match('/[\x00-\x1f\x7f]/', $name) === 1
            || preg_match('/^([^\[\]]+)((?:\[[^\[\]]*\])*)$/D', $name, $parts) !== 1) {
            throw BodyParsingException::malformed('Invalid multipart field name.');
        }
        preg_match_all('/\[([^\[\]]*)\]/', $parts[2], $matches);
        $keys = [strtr($parts[1], '. ', '__'), ...$matches[1]];
        if (count($keys) - 1 > $this->limits->maxNestingDepth) {
            throw BodyParsingException::tooLarge('Multipart nesting exceeds configured limit.');
        }
        $leaf = array_pop($keys);
        $current = &$target;
        foreach ($keys as $key) {
            if ($key === '') {
                $current[] = [];
                $key = array_key_last($current);
            }
            if (isset($current[$key]) && !is_array($current[$key]) && $upload) {
                throw BodyParsingException::malformed('Conflicting multipart upload field shapes.');
            }
            if (!isset($current[$key]) || !is_array($current[$key])) {
                $current[$key] = [];
            }
            $current = &$current[$key];
        }
        if ($leaf === '') {
            $current[] = $value;
        } elseif ($upload && isset($current[$leaf])) {
            $existing = $current[$leaf];
            if ($existing instanceof UploadedFileInterface) {
                $current[$leaf] = [$existing, $value];
            } elseif (is_array($existing) && array_is_list($existing) && array_all($existing, static fn(mixed $item): bool => $item instanceof UploadedFileInterface)) {
                $current[$leaf][] = $value;
            } else {
                throw BodyParsingException::malformed('Conflicting multipart upload field shapes.');
            }
        } else {
            $current[$leaf] = $value;
        }
    }
}

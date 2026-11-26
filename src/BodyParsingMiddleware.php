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
use Psr\Http\Message\StreamInterface;
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
            if ($mediaType === 'multipart/form-data' && $request->getUploadedFiles() === []) {
                $request = $this->parseMultipart($request);
            } elseif (in_array($mediaType, ['application/json', 'application/x-www-form-urlencoded'], true)) {
                [$request, $body] = $this->readBody($request);
                if ($body !== '') {
                    $request = $mediaType === 'application/json'
                        ? $this->parseJson($request, $body)
                        : $this->parseForm($request, $body);
                }
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
        $maxFields = $nativeMax >= 0 ? min($this->limits->maxParts, $nativeMax) : $this->limits->maxParts;
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
            if ($depth > $this->limits->maxNestingDepth || ($nativeDepth >= 0 && $depth > $nativeDepth)) {
                throw BodyParsingException::tooLarge('Form nesting exceeds configured limit.');
            }
        }
        parse_str($body, $data);
        return $request->withParsedBody($data);
    }

    private function parseMultipart(ServerRequestInterface $request): ServerRequestInterface
    {
        $boundary = $this->multipartParser->extractBoundary($request->getHeaderLine('Content-Type'));
        if ($boundary === null) {
            throw BodyParsingException::malformed('Multipart boundary is required.');
        }
        $source = $request->getBody();
        $size = $source->getSize();
        if ($size !== null && $size > $this->limits->maxBodyBytes) {
            throw BodyParsingException::tooLarge('Request body exceeds configured limit.');
        }
        $position = null;
        $replay = null;
        if ($source->isSeekable()) {
            $position = $source->tell();
            $source->rewind();
        } else {
            $replay = $this->copyBodyToTemporaryStream($source);
            $source = $replay;
        }
        $fields = [];
        $files = [];
        $ownedUploads = [];
        try {
            try {
                foreach ($this->multipartParser->parseStream($source, $boundary, $this->streamFactory, $this->limits) as [$part, $stream]) {
                    $transferred = false;
                    try {
                        $name = $part->getName();
                        if ($name === null) {
                            throw BodyParsingException::malformed('Multipart field name is required.');
                        }
                        if (ltrim($name, ' ') === '') {
                            continue;
                        }
                        $filename = $part->getFilename();
                        $encoding = strtolower($part->headers['content-transfer-encoding'] ?? 'binary');
                        if (!in_array($encoding, ['7bit', '8bit', 'binary'], true)) {
                            // Legacy transfer encodings remain supported; only
                            // these individual bounded parts materialize.
                            $decoded = (new MultipartPart($part->headers, $stream->getContents()))->getDecodedBody();
                            if (strlen($decoded) > $this->limits->maxPartBytes) {
                                throw BodyParsingException::tooLarge('Multipart part exceeds configured limit.');
                            }
                            $decodedStream = $this->streamFactory->createStream($decoded);
                            $stream->close();
                            $stream = $decodedStream;
                        }
                        if ($filename !== null) {
                            if ($filename === '' && $stream->read(1) !== '') {
                                throw BodyParsingException::malformed('An unselected file input must have an empty body.');
                            }
                            $upload = $this->createUpload($filename, $part->getContentType(), $stream);
                            $this->assign($files, $name, $upload, true);
                            $ownedUploads[] = $stream;
                            $transferred = true;
                        } else {
                            $this->assign($fields, $name, $stream->getContents(), false);
                        }
                    } finally {
                        if (!$transferred) {
                            $stream->close();
                        }
                    }
                }
            } finally {
                if ($position !== null) {
                    $source->seek($position);
                }
            }
            if ($replay !== null) {
                $replay->rewind();
                $request = $request->withBody($replay);
            }
            $request = $request->withParsedBody($fields);
            return $files === [] ? $request : $request->withUploadedFiles($files);
        } catch (\Throwable $exception) {
            // No request reached the application. Do not rely on a particular
            // PSR-7 implementation's destructors to release temporary files.
            foreach ($ownedUploads as $uploadStream) {
                try {
                    $uploadStream->close();
                } catch (\Throwable) {
                    // Preserve the original failure while releasing other streams.
                }
            }
            if ($replay !== null) {
                try {
                    $replay->close();
                } catch (\Throwable) {
                    // Preserve the original failure.
                }
            }
            throw $exception;
        }
    }

    private function copyBodyToTemporaryStream(StreamInterface $source): StreamInterface
    {
        $resource = fopen('php://temp/maxmemory:65536', 'w+b');
        if ($resource === false) {
            throw new \RuntimeException('Could not create request replay stream.');
        }
        try {
            $size = 0;
            while (true) {
                $chunk = $source->read(min(8192, $this->limits->maxBodyBytes - $size + 1));
                if ($chunk === '') {
                    if (!$source->eof()) {
                        throw BodyParsingException::malformed('Request body stream made no progress.');
                    }
                    break;
                }
                $size += strlen($chunk);
                if ($size > $this->limits->maxBodyBytes) {
                    throw BodyParsingException::tooLarge('Request body exceeds configured limit.');
                }
                if (fwrite($resource, $chunk) !== strlen($chunk)) {
                    throw new \RuntimeException('Could not write request replay stream.');
                }
            }
            if (!rewind($resource)) {
                throw new \RuntimeException('Could not rewind request replay stream.');
            }
            return $this->streamFactory->createStreamFromResource($resource);
        } catch (\Throwable $exception) {
            if (is_resource($resource)) {
                fclose($resource);
            }
            throw $exception;
        }
    }

    private function createUpload(string $filename, string $contentType, StreamInterface $stream): UploadedFileInterface
    {
        return $this->uploadedFileFactory->createUploadedFile(
            $stream,
            $stream->getSize(),
            $filename === '' ? UPLOAD_ERR_NO_FILE : UPLOAD_ERR_OK,
            $filename,
            $contentType,
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
            while (true) {
                $remaining = $this->limits->maxBodyBytes - strlen($body);
                $chunk = $stream->read(min(8192, $remaining + 1));
                if ($chunk === '') {
                    if (!$stream->eof()) {
                        throw BodyParsingException::malformed('Request body stream made no progress.');
                    }
                    break;
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
                $this->ensureAppendable($current);
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
            $this->ensureAppendable($current);
            $current[] = $value;
        } elseif ($upload && isset($current[$leaf])) {
            $existing = $current[$leaf];
            if ($existing instanceof UploadedFileInterface) {
                $current[$leaf] = [$existing, $value];
            } elseif (is_array($existing) && array_is_list($existing) && array_all($existing, static fn(mixed $item): bool => $item instanceof UploadedFileInterface)) {
                $this->ensureAppendable($current[$leaf]);
                $current[$leaf][] = $value;
            } else {
                throw BodyParsingException::malformed('Conflicting multipart upload field shapes.');
            }
        } else {
            $current[$leaf] = $value;
        }
    }
    /** @param array<array-key, mixed> $values */
    private function ensureAppendable(array $values): void
    {
        if (array_key_exists(PHP_INT_MAX, $values)) {
            throw BodyParsingException::malformed('Multipart numeric field index exceeds supported range.');
        }
    }
}

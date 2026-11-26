<?php

declare(strict_types=1);

namespace Componenta\Http\Middleware\Tests;

use Componenta\Http\Middleware\Body\MultipartParser;
use Componenta\Http\Middleware\BodyParsingException;
use Componenta\Http\Middleware\BodyParsingLimits;
use Componenta\Http\Middleware\BodyParsingMiddleware;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class MultipartStreamingTest extends TestCase
{
    public static function chunkWidths(): iterable
    {
        foreach ([1, 2, 3, 4, 7, 15, 31, 63, 127, 8192] as $width) {
            yield [$width];
        }
    }

    #[DataProvider('chunkWidths')]
    public function testBoundariesAndBinaryBytesAcrossChunkEdges(int $width): void
    {
        $factory = new Psr17Factory();
        $binary = "a\0\r\n--boundary--X\r\n--boundary \tX\r\n--boundary-\n--boundaryq\r\n" . str_repeat('abc', 6000) . "\r\n";
        $header = "Content-Disposition: form-data; name=x\r\n\r\n";
        $body = "preamble text--boundary\r\n--boundary\r\n" . $header . $binary
            . "\r\n--boundary\t \r\n" . $header . "\r\n--boundary--\r\nepilogue";
        $offset = 0;
        $source = $this->createStub(StreamInterface::class);
        $source->method('read')->willReturnCallback(static function (int $length) use (&$offset, $body, $width): string {
            $chunk = substr($body, $offset, min($length, $width));
            $offset += strlen($chunk);
            return $chunk;
        });
        $source->method('eof')->willReturnCallback(static function () use (&$offset, $body): bool {
            return $offset >= strlen($body);
        });
        $parts = [];
        foreach ((new MultipartParser())->parseStream($source, 'boundary', $factory, new BodyParsingLimits()) as [$metadata, $stream]) {
            $parts[] = [$metadata->getName(), $stream->getContents()];
            $stream->close();
        }
        self::assertSame([['x', $binary], ['x', '']], $parts);
        self::assertSame(strlen($body), $offset);
    }

    #[DataProvider('chunkWidths')]
    public function testHeaderByteLimitIsIndependentOfTerminatorChunkBoundaries(int $width): void
    {
        $factory = new Psr17Factory();
        $header = "Content-Disposition: form-data; name=x\r\n";
        $body = "--b\r\n" . $header . "\r\nabc\r\n--b--\r\n";
        $offset = 0;
        $source = $this->createStub(StreamInterface::class);
        $source->method('read')->willReturnCallback(static function (int $length) use (&$offset, $body, $width): string {
            $chunk = substr($body, $offset, min($length, $width));
            $offset += strlen($chunk);
            return $chunk;
        });
        $source->method('eof')->willReturnCallback(static function () use (&$offset, $body): bool {
            return $offset >= strlen($body);
        });
        $limits = new BodyParsingLimits(maxHeaderBytes: strlen($header));
        $parts = [];
        foreach ((new MultipartParser($limits))->parseStream($source, 'b', $factory, $limits) as [$part, $stream]) {
            $parts[] = [$part->getName(), $stream->getContents()];
            $stream->close();
        }
        self::assertSame([['x', 'abc']], $parts);
        self::assertSame(strlen($body), $offset);
    }

    public function testEpilogueCountsTowardBodyLimit(): void
    {
        $factory = new Psr17Factory();
        $parser = new MultipartParser();
        $this->expectException(BodyParsingException::class);
        iterator_to_array($parser->parseStream(
            $factory->createStream("--b--\r\n" . str_repeat('a', 20)),
            'b', $factory, new BodyParsingLimits(maxBodyBytes: 10),
        ));
    }

    public function testNonSeekableBodyIsReplayedAndFileStreamIsPreserved(): void
    {
        $factory = new Psr17Factory();
        $data = str_repeat("a\0b", 30000);
        $body = "--b\r\nContent-Disposition: form-data; name=file; filename=data.bin\r\n\r\n" . $data . "\r\n--b--\r\n";
        $offset = 0;
        $source = $this->createStub(StreamInterface::class);
        $source->method('isSeekable')->willReturn(false);
        $source->method('getSize')->willReturn(null);
        $source->method('eof')->willReturnCallback(static function () use (&$offset, $body): bool {
            return $offset >= strlen($body);
        });
        $source->method('read')->willReturnCallback(static function (int $length) use (&$offset, $body): string {
            $chunk = substr($body, $offset, min($length, 17));
            $offset += strlen($chunk);
            return $chunk;
        });
        $request = $factory->createServerRequest('POST', '/')->withHeader('Content-Type', 'multipart/form-data; boundary=b')->withBody($source);
        $handler = new class implements RequestHandlerInterface {
            public ?ServerRequestInterface $request = null;
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->request = $request;
                return (new Psr17Factory())->createResponse(204);
            }
        };
        $middleware = new BodyParsingMiddleware($factory, $factory, responseFactory: $factory);
        self::assertSame(204, $middleware->process($request, $handler)->getStatusCode());
        self::assertNotNull($handler->request);
        self::assertSame($body, $handler->request->getBody()->getContents());
        $upload = $handler->request->getUploadedFiles()['file'];
        self::assertSame(strlen($data), $upload->getSize());
        self::assertSame($data, $upload->getStream()->getContents());
    }

    public function testFailureClosesAlreadyCreatedUploadStreams(): void
    {
        $factory = new Psr17Factory();
        $streams = new class($factory) implements StreamFactoryInterface {
            /** @var list<StreamInterface> */
            public array $created = [];
            public function __construct(private readonly Psr17Factory $factory) {}
            public function createStream(string $content = ''): StreamInterface
            {
                return $this->factory->createStream($content);
            }
            public function createStreamFromFile(string $filename, string $mode = 'r'): StreamInterface
            {
                return $this->factory->createStreamFromFile($filename, $mode);
            }
            public function createStreamFromResource($resource): StreamInterface
            {
                $stream = $this->factory->createStreamFromResource($resource);
                $this->created[] = $stream;
                return $stream;
            }
        };
        $body = "--b\r\nContent-Disposition: form-data; name=file; filename=data.bin\r\n\r\nDATA\r\n"
            . "--b\r\nContent-Disposition: form-data; name=broken\r\n\r\nno closing boundary";
        $request = $factory->createServerRequest('POST', '/')->withHeader('Content-Type', 'multipart/form-data; boundary=b')
            ->withBody($factory->createStream($body));
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new \LogicException('Malformed multipart must not reach the handler.');
            }
        };
        $middleware = new BodyParsingMiddleware($streams, $factory, responseFactory: $factory);
        self::assertSame(400, $middleware->process($request, $handler)->getStatusCode());
        self::assertCount(1, $streams->created);
        self::assertFalse($streams->created[0]->isReadable());
        self::assertSame(0, $request->getBody()->tell());
    }

    public function testExtraCarriageReturnInMultipartHeaderIsRejected(): void
    {
        $this->expectException(BodyParsingException::class);
        (new MultipartParser())->parse("--b\r\nContent-Disposition: form-data; name=x\r\r\n\r\nabc\r\n--b--\r\n", 'b');
    }

    public function testUnselectedFileWithContentIsRejected(): void
    {
        $factory = new Psr17Factory();
        $body = "--b\r\nContent-Disposition: form-data; name=file; filename=\"\"\r\n\r\nDATA\r\n--b--\r\n";
        $request = $factory->createServerRequest('POST', '/')->withHeader('Content-Type', 'multipart/form-data; boundary=b')
            ->withBody($factory->createStream($body));
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new \LogicException('Malformed file input must not reach the handler.');
            }
        };
        $middleware = new BodyParsingMiddleware($factory, $factory, responseFactory: $factory);
        self::assertSame(400, $middleware->process($request, $handler)->getStatusCode());
    }
}

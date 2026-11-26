<?php

declare(strict_types=1);

namespace Componenta\Http\Middleware\Tests;

use Componenta\Http\Middleware\BodyParsingException;
use Componenta\Http\Middleware\BodyParsingLimits;
use Componenta\Http\Middleware\BodyParsingMiddleware;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class BodyParsingContractTest extends TestCase
{
    public static function jsonKinds(): iterable
    {
        yield ['{}', [], 'object'];
        yield ['[]', [], 'array'];
        yield ['{"__scalar":42}', ['__scalar' => 42], 'object'];
        yield ['42', ['__scalar' => 42], 'scalar'];
        yield ['null', ['__scalar' => null], 'scalar'];
        yield ['"hello"', ['__scalar' => 'hello'], 'scalar'];
        yield ['true', ['__scalar' => true], 'scalar'];
        yield ['9223372036854775808', ['__scalar' => '9223372036854775808'], 'scalar'];
    }

    #[DataProvider('jsonKinds')]
    public function testJsonRootSemanticsAndIntegerPrecision(string $body, array $expected, string $kind): void
    {
        $factory = new Psr17Factory();
        $handler = $this->handler();
        $middleware = new BodyParsingMiddleware($factory, $factory, responseFactory: $factory);
        $request = $factory->createServerRequest('POST', '/')->withHeader('Content-Type', 'APPLICATION/JSON ; Charset = UTF-8')
            ->withBody($factory->createStream($body));
        self::assertSame(204, $middleware->process($request, $handler)->getStatusCode());
        self::assertSame($expected, $handler->request?->getParsedBody());
        self::assertSame($kind, $handler->request?->getAttribute(BodyParsingMiddleware::ATTR_JSON_KIND));
    }

    public function testAlreadyParsedAndUnsupportedBodiesAreUntouched(): void
    {
        $factory = new Psr17Factory();
        $middleware = new BodyParsingMiddleware($factory, $factory, responseFactory: $factory);
        $base = $factory->createServerRequest('POST', '/')->withBody($factory->createStream('{'));
        foreach ([
            $base,
            $base->withHeader('Content-Type', 'text/plain'),
            $base->withHeader('Content-Type', 'application/json')->withParsedBody(['already' => true]),
            $base->withHeader('Content-Type', 'application/json')->withMethod('GET'),
            $base->withHeader('Content-Type', 'application/json')->withMethod('HEAD'),
            $base->withHeader('Content-Type', 'application/json')->withMethod('OPTIONS'),
            $base->withHeader('Content-Type', 'application/json')->withMethod('TRACE'),
        ] as $request) {
            $handler = $this->handler();
            self::assertSame(204, $middleware->process($request, $handler)->getStatusCode());
            self::assertSame($request, $handler->request);
        }
    }

    public function testMalformedJsonIsTypedExceptionWithoutResponseFactory(): void
    {
        $factory = new Psr17Factory();
        $middleware = new BodyParsingMiddleware($factory, $factory);
        $request = $factory->createServerRequest('POST', '/')->withHeader('Content-Type', 'application/json')
            ->withBody($factory->createStream('{'));
        try {
            $middleware->process($request, $this->handler());
            self::fail('Malformed JSON was accepted.');
        } catch (BodyParsingException $exception) {
            self::assertSame(400, $exception->status);
            self::assertInstanceOf(\JsonException::class, $exception->getPrevious());
        }
    }

    public function testSeekablePositionIsRestoredAndExactBodyLimitIsAccepted(): void
    {
        $factory = new Psr17Factory();
        $handler = $this->handler();
        $middleware = new BodyParsingMiddleware($factory, $factory, limits: new BodyParsingLimits(maxBodyBytes: 7), responseFactory: $factory);
        $stream = $factory->createStream('{"a":1}');
        $stream->seek(3);
        $request = $factory->createServerRequest('POST', '/')->withHeader('Content-Type', 'application/json')->withBody($stream);
        self::assertSame(204, $middleware->process($request, $handler)->getStatusCode());
        self::assertSame(['a' => 1], $handler->request?->getParsedBody());
        self::assertSame(3, $stream->tell());
    }

    public function testNoProgressNonSeekableStreamIsRejected(): void
    {
        $factory = new Psr17Factory();
        $handler = $this->handler();
        $source = $this->createStub(StreamInterface::class);
        $source->method('isSeekable')->willReturn(false);
        $source->method('getSize')->willReturn(null);
        $source->method('eof')->willReturn(false);
        $source->method('read')->willReturn('');
        $request = $factory->createServerRequest('POST', '/')->withHeader('Content-Type', 'application/json')->withBody($source);
        self::assertSame(400, (new BodyParsingMiddleware($factory, $factory, responseFactory: $factory))->process($request, $handler)->getStatusCode());
        self::assertNull($handler->request);
    }

    public function testUnknownSizeBodyLimitIsEnforcedDuringReads(): void
    {
        $factory = new Psr17Factory();
        $handler = $this->handler();
        $source = $this->createStub(StreamInterface::class);
        $source->method('isSeekable')->willReturn(false);
        $source->method('getSize')->willReturn(null);
        $source->method('eof')->willReturn(false);
        $source->method('read')->willReturn('1234');
        $request = $factory->createServerRequest('POST', '/')->withHeader('Content-Type', 'application/json')->withBody($source);
        $middleware = new BodyParsingMiddleware($factory, $factory, limits: new BodyParsingLimits(maxBodyBytes: 3), responseFactory: $factory);
        self::assertSame(413, $middleware->process($request, $handler)->getStatusCode());
        self::assertNull($handler->request);
    }

    public static function badForms(): iterable
    {
        yield ['a%00b=x', 400];
        yield ['a[b][c]=x', 413];
        yield ['a=1&b=2&c=3', 413];
    }

    #[DataProvider('badForms')]
    public function testFormPolicyIsEnforcedBeforePhpParsing(string $body, int $status): void
    {
        $factory = new Psr17Factory();
        $handler = $this->handler();
        $middleware = new BodyParsingMiddleware($factory, $factory, limits: new BodyParsingLimits(maxParts: 2, maxNestingDepth: 1), responseFactory: $factory);
        $request = $factory->createServerRequest('POST', '/')->withHeader('Content-Type', 'application/x-www-form-urlencoded')->withBody($factory->createStream($body));
        self::assertSame($status, $middleware->process($request, $handler)->getStatusCode());
        self::assertNull($handler->request);
    }

    public static function invalidLimits(): iterable
    {
        foreach ([
            ['maxBodyBytes' => 0], ['maxBodyBytes' => PHP_INT_MAX], ['maxPartBytes' => 0], ['maxParts' => 0],
            ['maxHeaderBytes' => 0], ['maxNestingDepth' => 0], ['maxNestingDepth' => 513],
        ] as $args) {
            yield [$args];
        }
    }

    #[DataProvider('invalidLimits')]
    public function testInvalidLimitsAreConfigurationErrors(array $args): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new BodyParsingLimits(...$args);
    }

    private function handler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public ?ServerRequestInterface $request = null;
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->request = $request;
                return (new Psr17Factory())->createResponse(204);
            }
        };
    }
}

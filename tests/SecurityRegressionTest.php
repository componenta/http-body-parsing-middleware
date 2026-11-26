<?php

declare(strict_types=1);

namespace Componenta\Http\Middleware\Tests;

use Componenta\Http\Middleware\Body\MultipartParser;
use Componenta\Http\Middleware\BodyParsingException;
use Componenta\Http\Middleware\BodyParsingLimits;
use Componenta\Http\Middleware\BodyParsingMiddleware;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class SecurityRegressionTest extends TestCase
{
    public function testBoundaryParameterMustMatchExactly(): void
    {
        $parser = new MultipartParser();
        self::assertSame('right', $parser->extractBoundary('multipart/form-data; notboundary=wrong; boundary=right'));
        $this->expectException(BodyParsingException::class);
        $parser->extractBoundary('multipart/form-data; boundary=' . str_repeat('a', 71));
    }

    public function testIncompleteMultipartIsRejected(): void
    {
        $this->expectException(BodyParsingException::class);
        (new MultipartParser())->parse("--b\r\nContent-Disposition: form-data; name=x\r\n\r\nabc", 'b');
    }

    public function testBoundaryLikeBytesInsideBinaryPartArePreserved(): void
    {
        $data = "one\r\n--b--not-a-delimiter\r\n--b invalid\r\ntwo";
        $parts = (new MultipartParser())->parse("--b\r\nContent-Disposition: form-data; name=x\r\n\r\n" . $data . "\r\n--b--\r\n", 'b');
        self::assertCount(1, $parts);
        self::assertSame($data, $parts[0]->body);
    }

    public function testPostFormParsingAndStreamPositionAreIndependentOfSapi(): void
    {
        $factory = new Psr17Factory();
        $handler = $this->handler();
        $middleware = new BodyParsingMiddleware($factory, $factory, responseFactory: $factory);
        $request = $factory->createServerRequest('POST', '/')
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withBody($factory->createStream('name=Alice'));
        self::assertSame(204, $middleware->process($request, $handler)->getStatusCode());
        self::assertSame(['name' => 'Alice'], $handler->request?->getParsedBody());
        self::assertSame(0, $request->getBody()->tell());
    }

    public function testOversizeBodyDoesNotReachHandler(): void
    {
        $factory = new Psr17Factory();
        $handler = $this->handler();
        $middleware = new BodyParsingMiddleware($factory, $factory, limits: new BodyParsingLimits(maxBodyBytes: 3), responseFactory: $factory);
        $request = $factory->createServerRequest('POST', '/')->withHeader('Content-Type', 'application/json')
            ->withBody($factory->createStream('{"x":1}'));
        self::assertSame(413, $middleware->process($request, $handler)->getStatusCode());
        self::assertNull($handler->request);
    }

    public function testDuplicateFileFieldKeepsBothUploads(): void
    {
        $factory = new Psr17Factory();
        $handler = $this->handler();
        $middleware = new BodyParsingMiddleware($factory, $factory, responseFactory: $factory);
        $body = "--b\r\nContent-Disposition: form-data; name=file; filename=a\r\n\r\nA\r\n"
            . "--b\r\nContent-Disposition: form-data; name=file; filename=b\r\n\r\nB\r\n--b--\r\n";
        $request = $factory->createServerRequest('POST', '/')->withHeader('Content-Type', 'multipart/form-data; boundary=b')
            ->withBody($factory->createStream($body));
        self::assertSame(204, $middleware->process($request, $handler)->getStatusCode());
        $uploads = $handler->request?->getUploadedFiles()['file'];
        self::assertIsArray($uploads);
        self::assertCount(2, $uploads);
        self::assertSame('a', $uploads[0]->getClientFilename());
        self::assertSame('b', $uploads[1]->getClientFilename());
    }

    public function testAmbiguousContentTypeIsRejected(): void
    {
        $factory = new Psr17Factory();
        $handler = $this->handler();
        $middleware = new BodyParsingMiddleware($factory, $factory, responseFactory: $factory);
        foreach (['application/json, text/plain', ['application/json', 'text/plain']] as $value) {
            $request = $factory->createServerRequest('POST', '/')->withHeader('Content-Type', $value);
            self::assertSame(400, $middleware->process($request, $handler)->getStatusCode());
        }
        self::assertNull($handler->request);
    }

    public function testFormFieldLimitIsEnforcedBeforeParsing(): void
    {
        $factory = new Psr17Factory();
        $handler = $this->handler();
        $middleware = new BodyParsingMiddleware($factory, $factory, limits: new BodyParsingLimits(maxParts: 2), responseFactory: $factory);
        $request = $factory->createServerRequest('POST', '/')->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withBody($factory->createStream('a=1&b=2&c=3'));
        self::assertSame(413, $middleware->process($request, $handler)->getStatusCode());
        self::assertNull($handler->request);
    }

    public function testOverflowingImplicitArrayIndexIsRejected(): void
    {
        $factory = new Psr17Factory();
        $handler = $this->handler();
        $middleware = new BodyParsingMiddleware($factory, $factory, responseFactory: $factory);
        $body = "--b\r\nContent-Disposition: form-data; name=\"x[" . PHP_INT_MAX . "]\"\r\n\r\nA\r\n"
            . "--b\r\nContent-Disposition: form-data; name=\"x[]\"\r\n\r\nB\r\n--b--\r\n";
        $request = $factory->createServerRequest('POST', '/')->withHeader('Content-Type', 'multipart/form-data; boundary=b')
            ->withBody($factory->createStream($body));
        self::assertSame(400, $middleware->process($request, $handler)->getStatusCode());
        self::assertNull($handler->request);
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

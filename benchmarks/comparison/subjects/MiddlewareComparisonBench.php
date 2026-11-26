<?php

declare(strict_types=1);

namespace Componenta\Http\Middleware\Comparison;

use Componenta\Http\Middleware\BodyParsingMiddleware;
use Componenta\Http\Middleware\Cors\CorsConfiguration;
use Componenta\Http\Middleware\Cors\CorsMiddleware;
use Componenta\Http\Middleware\Csrf\CsrfMiddleware;
use Componenta\Http\Middleware\Csrf\HmacCsrfTokenManager;
use Componenta\Http\Middleware\Throttle\FileRateLimiter;
use Componenta\Http\Middleware\TrustedProxy\TrustedHeaders;
use Componenta\Http\Middleware\TrustedProxy\TrustedProxyConfiguration;
use Componenta\Http\Middleware\TrustedProxy\TrustedProxyMiddleware;
use Nyholm\Psr7\Factory\Psr17Factory;
use PhpBench\Attributes as Bench;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[Bench\BeforeMethods('setUp')]
#[Bench\AfterMethods('tearDown')]
#[Bench\Iterations(5)]
final class MiddlewareComparisonBench
{
    private CorsMiddleware $cors;
    private CsrfMiddleware $csrf;
    private TrustedProxyMiddleware $xff;
    private TrustedProxyMiddleware $forwarded;
    private FileRateLimiter $limiter;
    private BodyParsingMiddleware $body;
    private ServerRequestInterface $corsActual;
    private ServerRequestInterface $corsPreflight;
    private ServerRequestInterface $csrfRequest;
    private ServerRequestInterface $xffRequest;
    private ServerRequestInterface $forwardedRequest;
    private ServerRequestInterface $jsonRequest;
    private ServerRequestInterface $formRequest;
    private ServerRequestInterface $uploadRequest;
    private RequestHandlerInterface $handler;
    private RequestHandlerInterface $proxyHandler;
    private RequestHandlerInterface $jsonHandler;
    private RequestHandlerInterface $formHandler;
    private RequestHandlerInterface $uploadHandler;
    private string $directory;

    public function setUp(): void
    {
        $factory = new Psr17Factory();
        $response = $factory->createResponse(204);
        $this->handler = new class($response) implements RequestHandlerInterface {
            public function __construct(private readonly ResponseInterface $response) {}
            public function handle(ServerRequestInterface $request): ResponseInterface { return $this->response; }
        };
        $origins = array_map(static fn(int $i): string => 'https://app' . $i . '.example.test', range(1, 100));
        $this->cors = new CorsMiddleware(new CorsConfiguration(
            allowedOrigins: $origins, allowedMethods: ['GET', 'POST'],
            allowedHeaders: ['Authorization', 'Content-Type'], allowCredentials: true,
        ), $factory);
        $this->corsActual = $factory->createServerRequest('GET', 'https://api.example.test/')
            ->withHeader('Origin', 'https://app100.example.test');
        $this->corsPreflight = $this->corsActual->withMethod('OPTIONS')
            ->withHeader('Access-Control-Request-Method', 'POST')
            ->withHeader('Access-Control-Request-Headers', 'Authorization, Content-Type');
        $tokenManager = new HmacCsrfTokenManager(str_repeat('k', 32), 600, 'comparison-session', static fn(): int => 1000);
        $this->csrf = new CsrfMiddleware($tokenManager, $factory, targetOrigin: 'https://shop.example.test');
        $this->csrfRequest = $factory->createServerRequest('POST', 'https://shop.example.test/')
            ->withHeader('Origin', 'https://shop.example.test')
            ->withHeader('X-CSRF-Token', $tokenManager->generate());
        $this->xff = new TrustedProxyMiddleware(new TrustedProxyConfiguration(['10.0.0.0/8']));
        $this->forwarded = new TrustedProxyMiddleware(new TrustedProxyConfiguration(['10.0.0.0/8'], TrustedHeaders::Forwarded));
        $base = $factory->createServerRequest('GET', 'http://internal.test:8080/', ['REMOTE_ADDR' => '10.0.0.1']);
        $this->xffRequest = $base
            ->withHeader('X-Forwarded-For', '192.0.2.10, 10.0.0.2, 10.0.0.3')
            ->withHeader('X-Forwarded-Host', 'shop.example.test, internal.test, internal.test')
            ->withHeader('X-Forwarded-Proto', 'https, http, http');
        $this->forwardedRequest = $base->withHeader('Forwarded', 'for=192.0.2.10;host=shop.example.test;proto=https, for=10.0.0.2, for=10.0.0.3');
        $this->proxyHandler = new class($response) implements RequestHandlerInterface {
            public function __construct(private readonly ResponseInterface $response) {}
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                if ($request->getAttribute('client_ip') !== '192.0.2.10'
                    || (string) $request->getUri() !== 'https://shop.example.test/') {
                    throw new \RuntimeException('Proxy behavior differs on the common valid fixture.');
                }
                return $this->response;
            }
        };
        $this->directory = sys_get_temp_dir() . '/componenta-comparison-' . bin2hex(random_bytes(8));
        if (!mkdir($this->directory, 0700)) { throw new \RuntimeException('Cannot create rate-limit fixture.'); }
        $this->limiter = new FileRateLimiter($this->directory, static fn(): int => 100);
        $this->limiter->hit('denied', 1, 60);
        $this->body = new BodyParsingMiddleware($factory, $factory);
        $patch = $factory->createServerRequest('PATCH', '/');
        $this->jsonRequest = $patch->withHeader('Content-Type', 'application/json')
            ->withBody($factory->createStream(json_encode(array_fill(0, 100, ['id' => 1, 'name' => 'example']), JSON_THROW_ON_ERROR)));
        $this->formRequest = $patch->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withBody($factory->createStream(http_build_query(['tags' => array_fill(0, 100, 'example')])));
        $this->jsonHandler = new class($response) implements RequestHandlerInterface {
            public function __construct(private readonly ResponseInterface $response) {}
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $body = $request->getParsedBody();
                if (!is_array($body) || count($body) !== 100 || $body[99]['name'] !== 'example') {
                    throw new \RuntimeException('JSON behavior differs.');
                }
                return $this->response;
            }
        };
        $this->formHandler = new class($response) implements RequestHandlerInterface {
            public function __construct(private readonly ResponseInterface $response) {}
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $body = $request->getParsedBody();
                if (!is_array($body) || count($body['tags']) !== 100 || $body['tags'][99] !== 'example') {
                    throw new \RuntimeException('Form behavior differs.');
                }
                return $this->response;
            }
        };
        $resource = tmpfile();
        if ($resource === false) { throw new \RuntimeException('Cannot create multipart fixture.'); }
        fwrite($resource, "--b\r\nContent-Disposition: form-data; name=file; filename=data.bin\r\n\r\n");
        for ($i = 0; $i < 256; ++$i) { fwrite($resource, str_repeat('x', 8192)); }
        fwrite($resource, "\r\n--b--\r\n");
        rewind($resource);
        $this->uploadRequest = $patch->withHeader('Content-Type', 'multipart/form-data; boundary=b')
            ->withBody($factory->createStreamFromResource($resource));
        $this->uploadHandler = new class($response) implements RequestHandlerInterface {
            public function __construct(private readonly ResponseInterface $response) {}
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $file = $request->getUploadedFiles()['file'] ?? null;
                if (!$file instanceof UploadedFileInterface || $file->getSize() !== 2_097_152 || $file->getStream()->read(1) !== 'x') {
                    throw new \RuntimeException('Multipart behavior differs.');
                }
                return $this->response;
            }
        };
    }

    #[Bench\Revs(1000)]
    public function benchCorsActual100Origins(): void
    {
        $response = $this->cors->process($this->corsActual, $this->handler);
        if ($response->getHeaderLine('Access-Control-Allow-Origin') !== 'https://app100.example.test') {
            throw new \RuntimeException('CORS actual behavior differs.');
        }
    }

    #[Bench\Revs(1000)]
    public function benchCorsPreflight100Origins(): void
    {
        $response = $this->cors->process($this->corsPreflight, $this->handler);
        if ($response->getStatusCode() !== 204 || $response->getHeaderLine('Access-Control-Allow-Origin') !== 'https://app100.example.test') {
            throw new \RuntimeException('CORS preflight behavior differs.');
        }
    }

    #[Bench\Revs(1000)]
    public function benchCsrfValidUnsafeRequest(): void
    {
        if ($this->csrf->process($this->csrfRequest, $this->handler)->getStatusCode() !== 204) {
            throw new \RuntimeException('CSRF valid behavior differs.');
        }
    }

    #[Bench\Revs(1000)]
    public function benchTrustedProxyXForwarded(): void { $this->xff->process($this->xffRequest, $this->proxyHandler); }

    #[Bench\Revs(1000)]
    public function benchTrustedProxyForwarded(): void { $this->forwarded->process($this->forwardedRequest, $this->proxyHandler); }

    #[Bench\Revs(100)]
    public function benchFileRateAllowed(): void
    {
        if (!$this->limiter->hit('allowed', PHP_INT_MAX, 60)->allowed) {
            throw new \RuntimeException('Rate quota unexpectedly denied.');
        }
    }

    #[Bench\Revs(100)]
    public function benchFileRateDenied(): void
    {
        if ($this->limiter->hit('denied', 1, 60)->allowed) {
            throw new \RuntimeException('Rate quota unexpectedly allowed.');
        }
    }

    #[Bench\Revs(500)]
    public function benchJson(): void { $this->body->process($this->jsonRequest, $this->jsonHandler); }

    #[Bench\Revs(500)]
    public function benchForm(): void { $this->body->process($this->formRequest, $this->formHandler); }

    #[Bench\Revs(10)]
    public function benchMultipartTwoMiB(): void { $this->body->process($this->uploadRequest, $this->uploadHandler); }

    public function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->directory);
    }
}

<?php

declare(strict_types=1);

namespace Componenta\Http\Middleware\Benchmarks;

use Componenta\Http\Middleware\BodyParsingMiddleware;
use Nyholm\Psr7\Factory\Psr17Factory;
use PhpBench\Attributes as Bench;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[Bench\BeforeMethods('setUp')]
#[Bench\Iterations(5)]
final class BodyParsingBench
{
    private BodyParsingMiddleware $middleware;
    private RequestHandlerInterface $handler;
    private ServerRequestInterface $json;
    private ServerRequestInterface $form;
    private ServerRequestInterface $multipart;

    public function setUp(): void
    {
        $factory = new Psr17Factory();
        $this->middleware = new BodyParsingMiddleware($factory, $factory, responseFactory: $factory);
        $this->handler = new class($factory->createResponse(204)) implements RequestHandlerInterface {
            public function __construct(private readonly ResponseInterface $response) {}
            public function handle(ServerRequestInterface $request): ResponseInterface { return $this->response; }
        };
        $this->json = $factory->createServerRequest('POST', '/')->withHeader('Content-Type', 'application/json')
            ->withBody($factory->createStream(json_encode(array_fill(0, 100, ['id' => 1, 'name' => 'example']), JSON_THROW_ON_ERROR)));
        $this->form = $factory->createServerRequest('PATCH', '/')->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withBody($factory->createStream(http_build_query(['tags' => array_fill(0, 100, 'example')])));
        $resource = tmpfile();
        if ($resource === false) { throw new \RuntimeException('Cannot create benchmark source.'); }
        fwrite($resource, "--b\r\nContent-Disposition: form-data; name=file; filename=data.bin\r\n\r\n");
        for ($i = 0; $i < 256; ++$i) { fwrite($resource, str_repeat('x', 8192)); }
        fwrite($resource, "\r\n--b--\r\n");
        rewind($resource);
        $this->multipart = $factory->createServerRequest('POST', '/')->withHeader('Content-Type', 'multipart/form-data; boundary=b')
            ->withBody($factory->createStreamFromResource($resource));
    }

    #[Bench\Revs(500)]
    public function benchJson(): void { $this->assertAccepted($this->json); }
    #[Bench\Revs(500)]
    public function benchForm(): void { $this->assertAccepted($this->form); }
    #[Bench\Revs(10)]
    public function benchMultipartTwoMiB(): void { $this->assertAccepted($this->multipart); }

    private function assertAccepted(ServerRequestInterface $request): void
    {
        if ($this->middleware->process($request, $this->handler)->getStatusCode() !== 204) {
            throw new \RuntimeException('Benchmark request was rejected.');
        }
    }
}

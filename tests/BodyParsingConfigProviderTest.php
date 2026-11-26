<?php

declare(strict_types=1);

namespace Componenta\Http\Middleware\Tests;

use Componenta\Config\ConfigKey;
use Componenta\Http\Middleware\BodyParsingConfigProvider;
use Componenta\Http\Middleware\BodyParsingMiddleware;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class BodyParsingConfigProviderTest extends TestCase
{
    public function testProviderRegistersFunctionalMiddlewareWithPsrFactories(): void
    {
        $config = (new BodyParsingConfigProvider())();
        $factory = new Psr17Factory();
        $container = $this->createStub(ContainerInterface::class);
        $container->method('get')->willReturn($factory);
        $middleware = $config[ConfigKey::DEPENDENCIES][ConfigKey::FACTORIES][BodyParsingMiddleware::class]($container);
        self::assertInstanceOf(BodyParsingMiddleware::class, $middleware);
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new \LogicException('Malformed input must not reach the handler.');
            }
        };
        $request = $factory->createServerRequest('POST', '/')
            ->withHeader('Content-Type', 'application/json')
            ->withBody($factory->createStream('{'));
        self::assertSame(400, $middleware->process($request, $handler)->getStatusCode());
    }
}

<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Componenta\Config\Config;
use Componenta\Config\ConfigKey;
use Componenta\Config\DependencyDefinitions;
use Componenta\Config\Environment;
use Componenta\DI\ContainerFactory;
use Componenta\Http\Middleware\BodyParsingConfigProvider;
use Componenta\Http\Middleware\BodyParsingMiddleware;
use Componenta\Http\Middleware\Cors\CorsConfiguration;
use Componenta\Http\Middleware\Cors\CorsMiddleware;
use Componenta\Http\Middleware\Csrf\ComponentaSessionCsrfTokenManager;
use Componenta\Http\Middleware\Csrf\CsrfMiddleware;
use Componenta\Http\Middleware\Throttle\FileRateLimiter;
use Componenta\Http\Middleware\Throttle\ThrottleMiddleware;
use Componenta\Http\Middleware\TrustedProxy\TrustedProxyConfiguration;
use Componenta\Http\Middleware\TrustedProxy\TrustedProxyMiddleware;
use Componenta\Session\Session;
use Componenta\Session\SessionId;
use Componenta\Session\SessionInterface;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @param list<MiddlewareInterface> $middleware */
function chain(array $middleware, RequestHandlerInterface $terminal): RequestHandlerInterface
{
    $next = $terminal;
    foreach (array_reverse($middleware) as $layer) {
        $next = new class($layer, $next) implements RequestHandlerInterface {
            public function __construct(private MiddlewareInterface $layer, private RequestHandlerInterface $next) {}
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->layer->process($request, $this->next);
            }
        };
    }
    return $next;
}

function runIntegration(bool $targeted): void
{
    $directory = sys_get_temp_dir() . '/componenta-integration-' . bin2hex(random_bytes(8));
    check(mkdir($directory, 0700), 'Cannot create rate limit storage.');
    try {
        $factory = new Psr17Factory();
        $dependencies = (new BodyParsingConfigProvider())()[ConfigKey::DEPENDENCIES];
        $dependencies[ConfigKey::SERVICES] = [Psr17Factory::class => $factory];
        $dependencies[ConfigKey::ALIASES] = [
            ResponseFactoryInterface::class => Psr17Factory::class,
            StreamFactoryInterface::class => Psr17Factory::class,
            UploadedFileFactoryInterface::class => Psr17Factory::class,
        ];
        $dependencies[ConfigKey::FACTORIES] += [
            TrustedProxyMiddleware::class => static fn(ContainerInterface $c): TrustedProxyMiddleware =>
                new TrustedProxyMiddleware(new TrustedProxyConfiguration(['10.0.0.1'])),
            CorsMiddleware::class => static fn(ContainerInterface $c): CorsMiddleware =>
                new CorsMiddleware(new CorsConfiguration(
                    allowedOrigins: ['https://shop.example.test'],
                    allowedMethods: ['GET', 'POST'],
                    allowedHeaders: ['Content-Type', 'X-CSRF-Token'],
                    allowCredentials: true,
                ), $c->get(ResponseFactoryInterface::class)),
            ThrottleMiddleware::class => static fn(ContainerInterface $c): ThrottleMiddleware =>
                new ThrottleMiddleware(new FileRateLimiter($directory, static fn(): int => 100),
                    $c->get(ResponseFactoryInterface::class), limit: 4, policy: 'integration'),
            CsrfMiddleware::class => static fn(ContainerInterface $c): CsrfMiddleware =>
                new CsrfMiddleware(ComponentaSessionCsrfTokenManager::fromRequest(...),
                    $c->get(ResponseFactoryInterface::class), exposeTokenManager: false),
        ];
        $assembly = new ContainerFactory();
        $config = new Config([], new Environment([]));
        $definitions = new DependencyDefinitions($dependencies);
        $value = $targeted
            ? $assembly->createWithTargetedAttributeDiscovery($config, $definitions)
            : $assembly->create($config, $definitions);
        $container = $value->container;
        $terminal = new class($factory) implements RequestHandlerInterface {
            public int $calls = 0;
            public function __construct(private Psr17Factory $factory) {}
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                ++$this->calls;
                check($request->getUri()->getScheme() === 'https', 'Wrong external scheme.');
                check($request->getUri()->getHost() === 'shop.example.test', 'Wrong external host.');
                check(!$request->hasHeader('X-Forwarded-For'), 'Raw forwarding metadata escaped.');
                check($request->getAttribute(CsrfMiddleware::ATTR_TOKEN_MANAGER) === null, 'Manager escaped.');
                $response = $this->factory->createResponse(200)->withHeader('Cache-Control', 'private, no-store');
                if ($request->getMethod() === 'GET') {
                    $response->getBody()->write((string) $request->getAttribute(CsrfMiddleware::ATTR_TOKEN));
                } else {
                    check($request->getParsedBody()['name'] === 'Alice', 'Parsed form was not propagated.');
                    check($request->getAttribute('client_ip') === '192.0.2.10', 'Wrong client identity.');
                    $response->getBody()->write('accepted');
                }
                return $response;
            }
        };
        $middleware = [];
        foreach ([TrustedProxyMiddleware::class, CorsMiddleware::class, ThrottleMiddleware::class,
            BodyParsingMiddleware::class, CsrfMiddleware::class] as $class) {
            $layer = $container->get($class);
            check($layer instanceof MiddlewareInterface, 'DI returned a non-middleware.');
            $middleware[] = $layer;
        }
        $pipeline = chain($middleware, $terminal);
        $sessionA = new Session(new SessionId('integration-session-a'));
        $sessionB = new Session(new SessionId('integration-session-b'));
        $base = $factory->createServerRequest('GET', 'http://internal.test:8080/', ['REMOTE_ADDR' => '10.0.0.1'])
            ->withHeader('X-Forwarded-For', '192.0.2.10')
            ->withHeader('X-Forwarded-Host', 'shop.example.test')
            ->withHeader('X-Forwarded-Proto', 'https')
            ->withHeader('Origin', 'https://shop.example.test')
            ->withAttribute(SessionInterface::class, $sessionA);
        $preflight = $pipeline->handle($base->withMethod('OPTIONS')
            ->withHeader('Access-Control-Request-Method', 'POST')
            ->withHeader('Access-Control-Request-Headers', 'X-CSRF-Token'));
        check($preflight->getStatusCode() === 204, 'Preflight failed.');
        check($terminal->calls === 0, 'Preflight reached the terminal.');
        $first = $pipeline->handle($base);
        $token = (string) $first->getBody();
        check($first->getStatusCode() === 200 && strlen($token) === 64, 'Token issue failed.');
        check($first->getHeaderLine('X-RateLimit-Remaining') === '3', 'Preflight consumed quota.');
        $post = $base->withMethod('POST')->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withBody($factory->createStream(http_build_query(['_csrf_token' => $token, 'name' => 'Alice'])));
        check($pipeline->handle($post)->getStatusCode() === 200, 'Valid form did not pass.');
        $rejected = $pipeline->handle($post->withAttribute(SessionInterface::class, $sessionB));
        check($rejected->getStatusCode() === 403, 'Cross-session token was accepted.');
        check($rejected->getHeaderLine('Access-Control-Allow-Origin') === 'https://shop.example.test', 'CORS missing on 403.');
        $malformed = $post->withHeader('Content-Type', 'application/json')->withBody($factory->createStream('{'));
        $bad = $pipeline->handle($malformed);
        check($bad->getStatusCode() === 400, 'Malformed JSON did not return 400.');
        check($bad->getHeaderLine('Access-Control-Allow-Origin') === 'https://shop.example.test', 'CORS missing on 400.');
        $limited = $pipeline->handle($malformed->withHeader('X-Forwarded-For', '::ffff:c000:20a'));
        check($limited->getStatusCode() === 429, 'Mapped IP bypassed quota or body parsed before throttle.');
        check($limited->getHeaderLine('Access-Control-Allow-Origin') === 'https://shop.example.test', 'CORS missing on 429.');
        check($limited->getHeaderLine('Cache-Control') === 'no-store', '429 is cacheable.');
        $second = $pipeline->handle($base->withHeader('X-Forwarded-For', '192.0.2.11')->withAttribute(SessionInterface::class, $sessionB));
        check($second->getStatusCode() === 200, 'Independent client quota failed.');
        check((string) $second->getBody() !== $token, 'Session token leaked across requests.');
        check($terminal->calls === 3, 'Rejected requests reached the terminal.');
        echo ($targeted ? 'Targeted' : 'Full') . " DI discovery: all integration assertions passed.\n";
    } finally {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($directory);
    }
}

runIntegration(false);
runIntegration(true);

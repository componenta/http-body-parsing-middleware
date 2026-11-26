<?php

declare(strict_types=1);

namespace Componenta\Http\Middleware\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NativeFormLimitsTest extends TestCase
{
    public static function zeroLimits(): iterable
    {
        yield ['max_input_vars=0', 'a=1'];
        yield ['max_input_nesting_level=0', 'a[b]=1'];
    }

    #[DataProvider('zeroLimits')]
    public function testZeroNativeLimitIsNotTreatedAsUnlimited(string $ini, string $body): void
    {
        if (!function_exists('proc_open')) {
            self::markTestSkipped('Requires process creation to set PHP_INI_PERDIR settings.');
        }
        $script = <<<'PHP'
require $argv[1];
$factory = new \Nyholm\Psr7\Factory\Psr17Factory();
$middleware = new \Componenta\Http\Middleware\BodyParsingMiddleware($factory, $factory, responseFactory: $factory);
$request = $factory->createServerRequest('POST', '/')
    ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
    ->withBody($factory->createStream($argv[2]));
$handler = new class implements \Psr\Http\Server\RequestHandlerInterface {
    public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
    {
        throw new \LogicException('Truncated form must not reach the handler.');
    }
};
echo $middleware->process($request, $handler)->getStatusCode();
PHP;
        $process = proc_open([PHP_BINARY, '-d', $ini, '-r', $script, dirname(__DIR__) . '/vendor/autoload.php', $body], [
            0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
        ], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), (string) $errors);
        self::assertSame('413', $output);
        self::assertSame('', $errors);
    }
}

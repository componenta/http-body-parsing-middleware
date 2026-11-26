<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

function registerMiddlewareSources(string $variant): void
{
    $root = dirname(__DIR__, 2) . '/comparison-sources/' . $variant . '/';
    $prefixes = [
        'Componenta\\Http\\Middleware\\Csrf\\' => 'http-csrf-middleware/src/',
        'Componenta\\Http\\Middleware\\Cors\\' => 'http-cors-middleware/src/',
        'Componenta\\Http\\Middleware\\TrustedProxy\\' => 'http-trusted-proxy-middleware/src/',
        'Componenta\\Http\\Middleware\\Throttle\\' => 'http-throttle-middleware/src/',
        'Componenta\\Http\\Middleware\\' => 'http-body-parsing-middleware/src/',
    ];
    spl_autoload_register(static function (string $class) use ($prefixes, $root): void {
        foreach ($prefixes as $prefix => $path) {
            if (str_starts_with($class, $prefix)) {
                $file = $root . $path . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
                if (is_file($file)) {
                    require $file;
                }
                return;
            }
        }
    }, prepend: true);

    foreach ([
        \Componenta\Http\Middleware\Csrf\CsrfMiddleware::class,
        \Componenta\Http\Middleware\Cors\CorsMiddleware::class,
        \Componenta\Http\Middleware\TrustedProxy\TrustedProxyMiddleware::class,
        \Componenta\Http\Middleware\Throttle\FileRateLimiter::class,
        \Componenta\Http\Middleware\BodyParsingMiddleware::class,
    ] as $class) {
        $file = (new ReflectionClass($class))->getFileName();
        if (!is_string($file) || !str_starts_with($file, $root)) {
            throw new RuntimeException('Benchmark loaded an unexpected revision: ' . $class);
        }
    }
}

<?php

declare(strict_types=1);

namespace Componenta\Http\Middleware;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;

class BodyParsingConfigProvider extends \Componenta\Config\ConfigProvider
{
    protected function getFactories(): array
    {
        return [
            BodyParsingMiddleware::class => static function (ContainerInterface $container): BodyParsingMiddleware {
                return new BodyParsingMiddleware(
                    $container->get(StreamFactoryInterface::class),
                    $container->get(UploadedFileFactoryInterface::class),
                );
            },
        ];
    }
}

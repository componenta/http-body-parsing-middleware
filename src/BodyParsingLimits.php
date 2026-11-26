<?php

declare(strict_types=1);

namespace Componenta\Http\Middleware;

final readonly class BodyParsingLimits
{
    public function __construct(
        public int $maxBodyBytes = 8_388_608,
        public int $maxPartBytes = 8_388_608,
        public int $maxParts = 1000,
        public int $maxHeaderBytes = 16_384,
        public int $maxNestingDepth = 64,
    ) {
        foreach ([$maxBodyBytes, $maxPartBytes, $maxParts, $maxHeaderBytes, $maxNestingDepth] as $limit) {
            if ($limit < 1) {
                throw new \InvalidArgumentException('Body parsing limits must be positive.');
            }
        }
        if ($maxNestingDepth > 512 || $maxBodyBytes >= PHP_INT_MAX
            || $maxPartBytes > PHP_INT_MAX - 4 || $maxHeaderBytes > PHP_INT_MAX - 4 || $maxParts >= PHP_INT_MAX) {
            throw new \InvalidArgumentException('Body parsing limits exceed supported bounds.');
        }
    }
}

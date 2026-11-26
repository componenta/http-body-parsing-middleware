<?php

declare(strict_types=1);

namespace Componenta\Http\Middleware;

final readonly class BodyParsingLimits
{
    /** @var int<1, 512> */
    public int $maxNestingDepth;
    public function __construct(
        public int $maxBodyBytes = 8_388_608,
        public int $maxPartBytes = 8_388_608,
        public int $maxParts = 1000,
        public int $maxHeaderBytes = 16_384,
        int $maxNestingDepth = 64,
    ) {
        foreach ([$maxBodyBytes, $maxPartBytes, $maxParts, $maxHeaderBytes] as $limit) {
            if ($limit < 1) {
                throw new \InvalidArgumentException('Body parsing limits must be positive.');
            }
        }
        if ($maxNestingDepth < 1 || $maxNestingDepth > 512 || $maxBodyBytes >= PHP_INT_MAX
            || $maxPartBytes > PHP_INT_MAX - 4 || $maxHeaderBytes > PHP_INT_MAX - 4 || $maxParts >= PHP_INT_MAX) {
            throw new \InvalidArgumentException('Body parsing limits exceed supported bounds.');
        }
        $this->maxNestingDepth = $maxNestingDepth;
    }
}

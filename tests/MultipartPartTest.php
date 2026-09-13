<?php

declare(strict_types=1);

namespace Componenta\Http\Middleware\Tests;

use Componenta\Http\Middleware\Body\MultipartPart;
use PHPUnit\Framework\TestCase;

final class MultipartPartTest extends TestCase
{
    public function testExtendedFilenameIsConvertedFromItsDeclaredEncoding(): void
    {
        $part = new MultipartPart(
            ['content-disposition' => "form-data; name=\"file\"; filename=\"fallback.txt\"; filename*=ISO-8859-1''caf%E9.txt"],
            'document',
        );

        self::assertSame('café.txt', $part->getFilename());
    }
}

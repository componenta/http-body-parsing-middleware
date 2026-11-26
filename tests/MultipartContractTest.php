<?php

declare(strict_types=1);

namespace Componenta\Http\Middleware\Tests;

use Componenta\Http\Middleware\Body\MultipartParser;
use Componenta\Http\Middleware\Body\MultipartPart;
use Componenta\Http\Middleware\BodyParsingException;
use Componenta\Http\Middleware\BodyParsingLimits;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MultipartContractTest extends TestCase
{
    public static function boundaries(): iterable
    {
        yield [' MULTIPART/FORM-DATA ; BOUNDARY = "a+b" ', 'a+b'];
        yield ['multipart/form-data; boundary="a\+b"; ext="a;b=c"', 'a+b'];
        yield ['multipart/form-data; boundary="a b"', 'a b'];
        yield ['multipart/form-data; boundary=' . str_repeat('a', 70), str_repeat('a', 70)];
        yield ['multipart/form-data', null];
        yield ['multipart/form-data; notboundary=abc', null];
        yield ['text/plain; boundary=abc', null];
    }

    #[DataProvider('boundaries')]
    public function testBoundaryExtractionMatchesParameterGrammar(string $header, ?string $expected): void
    {
        self::assertSame($expected, (new MultipartParser())->extractBoundary($header));
    }

    public static function invalidBoundaries(): iterable
    {
        foreach ([
            'multipart/form-data; boundary=',
            'multipart/form-data; boundary=""',
            'multipart/form-data; boundary="abc "',
            'multipart/form-data; boundary="abc@def"',
            'multipart/form-data; boundary=' . str_repeat('a', 71),
            'multipart/form-data; boundary=a; BOUNDARY=b',
            'multipart/form-data; boundary',
            'multipart/form-data; =a',
            'multipart/form-data; boundary="unterminated',
            'multipart/form-data; boundary="abc"junk',
            'multipart/form-data; boundary=abc=def',
            'multipart/form-data; boundary=a;',
            "multipart/form-data; boundary=\"a\0b\"",
        ] as $header) {
            yield [$header];
        }
    }

    #[DataProvider('invalidBoundaries')]
    public function testInvalidBoundaryIsNotSilentlyTruncated(string $header): void
    {
        $this->expectException(BodyParsingException::class);
        (new MultipartParser())->extractBoundary($header);
    }

    public function testStringApiPreservesAllPartsAcrossMultipleInternalReads(): void
    {
        $binary = str_repeat("\0abc\r\n--bX\r\n", 1200);
        $body = "--b\r\nContent-Disposition: form-data; name=first\r\n\r\none\r\n"
            . "--b\r\nContent-Disposition: form-data; name=file; filename=data.bin\r\n\r\n"
            . $binary . "\r\n"
            . "--b\r\nContent-Disposition: form-data; name=last\r\n\r\nthree\r\n"
            . "--b--\r\n";
        $parts = (new MultipartParser())->parse($body, 'b');

        self::assertCount(3, $parts);
        self::assertSame('first', $parts[0]->getName());
        self::assertSame('one', $parts[0]->body);
        self::assertSame('file', $parts[1]->getName());
        self::assertSame('data.bin', $parts[1]->getFilename());
        self::assertSame($binary, $parts[1]->body);
        self::assertSame('last', $parts[2]->getName());
        self::assertSame('three', $parts[2]->body);
    }

    public static function invalidDirectBoundaries(): iterable
    {
        foreach (['', str_repeat('a', 71), "b\n", "b\r", 'b ', 'b@'] as $boundary) {
            yield [$boundary];
        }
    }

    #[DataProvider('invalidDirectBoundaries')]
    public function testStringApiRejectsInvalidBoundaryEvenWhenDelimitersMatch(string $boundary): void
    {
        $body = '--' . $boundary . "\r\nContent-Disposition: form-data; name=x\r\n\r\nabc\r\n--" . $boundary . "--\r\n";
        $this->expectException(BodyParsingException::class);
        (new MultipartParser())->parse($body, $boundary);
    }

    public function testQuotedExtensionMayContainHorizontalTabAndTildeButNotDel(): void
    {
        $parser = new MultipartParser();
        self::assertSame('b', $parser->extractBoundary("multipart/form-data; boundary=b; ext=\"a\t~b\""));
        $this->expectException(BodyParsingException::class);
        $parser->extractBoundary("multipart/form-data; boundary=b; ext=\"a\x7fb\"");
    }

    public function testCaseInsensitiveHeadersFoldedParametersAndBinaryContent(): void
    {
        $body = "--b\r\nCONTENT-DISPOSITION: FORM-DATA;\r\n\tNAME = \"a;b\"; FILENAME = \"a\\\"b.txt\"\r\n"
            . "Content-Type: application/octet-stream\r\n\r\n" . "\0a\r\nb" . "\r\n--b--";
        $parts = (new MultipartParser())->parse($body, 'b');
        self::assertCount(1, $parts);
        self::assertSame('a;b', $parts[0]->getName());
        self::assertSame('a"b.txt', $parts[0]->getFilename());
        self::assertTrue($parts[0]->isFile());
        self::assertSame('application/octet-stream', $parts[0]->getContentType());
        self::assertSame("\0a\r\nb", $parts[0]->getDecodedBody());
    }

    public static function malformedParts(): iterable
    {
        $header = "Content-Disposition: form-data; name=x";
        yield 'no opening' => ["prefix--b\r\n" . $header . "\r\n\r\nabc\r\nsuffix--b--"];
        yield 'no closing' => ["--b\r\n" . $header . "\r\n\r\nabc"];
        yield 'invalid close suffix' => ["--b\r\n" . $header . "\r\n\r\nabc\r\n--b--x"];
        yield 'missing terminator' => ["--b\r\n" . $header . "\r\nabc\r\n--b--"];
        yield 'missing disposition' => ["--b\r\nContent-Type: text/plain\r\n\r\nabc\r\n--b--"];
        yield 'missing name' => ["--b\r\nContent-Disposition: form-data\r\n\r\nabc\r\n--b--"];
        yield 'other disposition' => ["--b\r\nContent-Disposition: attachment; name=x\r\n\r\nabc\r\n--b--"];
        yield 'duplicate header' => ["--b\r\n" . $header . "\r\ncontent-disposition: form-data; name=y\r\n\r\nabc\r\n--b--"];
        yield 'duplicate name' => ["--b\r\nContent-Disposition: form-data; name=x; NAME=y\r\n\r\nabc\r\n--b--"];
        yield 'invalid header name' => ["--b\r\nBad Header: x\r\n" . $header . "\r\n\r\nabc\r\n--b--"];
        yield 'invalid control character' => ["--b\r\n" . $header . "\r\nX-Extra: a\0b\r\n\r\nabc\r\n--b--"];
        yield 'no headers' => ["--b\r\n\r\nabc\r\n--b--"];
        yield 'opening delimiter only' => ["--b\r\n"];
    }

    #[DataProvider('malformedParts')]
    public function testMalformedPartsAreRejected(string $body): void
    {
        $this->expectException(BodyParsingException::class);
        (new MultipartParser())->parse($body, 'b');
    }

    public function testEmptyMultipartAndLfCompatibility(): void
    {
        $parser = new MultipartParser();
        self::assertSame([], $parser->parse("--b--", 'b'));
        self::assertSame([], $parser->parse("preamble--b\r\n--b--\r\n", 'b'));
        $parts = $parser->parse("preamble\n--b\nContent-Disposition: form-data; name=x\n\nabc\n--b--\nepilogue", 'b');
        self::assertCount(1, $parts);
        self::assertSame('abc', $parts[0]->getDecodedBody());
        self::assertFalse($parts[0]->isFile());
        self::assertNull($parts[0]->getFilename());
        self::assertSame('text/plain', $parts[0]->getContentType());
    }

    public function testExactResourceLimitsAcceptBoundaryValues(): void
    {
        $header = "Content-Disposition: form-data; name=x\r\n";
        $body = "--b\r\n" . $header . "\r\nabc\r\n--b--\r\n";
        $parser = new MultipartParser(new BodyParsingLimits(
            maxBodyBytes: strlen($body), maxPartBytes: 3, maxParts: 1, maxHeaderBytes: strlen($header),
        ));
        self::assertSame('abc', $parser->parse($body, 'b')[0]->body);
        foreach ([
            new BodyParsingLimits(maxBodyBytes: strlen($body) - 1),
            new BodyParsingLimits(maxPartBytes: 2),
            new BodyParsingLimits(maxHeaderBytes: strlen($header) - 1),
        ] as $limits) {
            try {
                (new MultipartParser($limits))->parse($body, 'b');
                self::fail('Exceeded resource limit was accepted.');
            } catch (BodyParsingException $exception) {
                self::assertSame(413, $exception->status);
            }
        }
    }

    public function testPartCountLimitIsEnforced(): void
    {
        $part = "--b\r\nContent-Disposition: form-data; name=x\r\n\r\nabc\r\n";
        try {
            (new MultipartParser(new BodyParsingLimits(maxParts: 1)))->parse($part . $part . '--b--', 'b');
            self::fail('Exceeded part count accepted.');
        } catch (BodyParsingException $exception) {
            self::assertSame(413, $exception->status);
        }
    }

    public static function transferEncodings(): iterable
    {
        yield ['BASE64', 'YQ==', 'a'];
        yield ['base64', '', ''];
        yield ['quoted-printable', 'a=00b', "a\0b"];
        yield ['binary', "\0a", "\0a"];
        yield ['8bit', "\xff", "\xff"];
        yield ['7bit', 'text', 'text'];
    }

    #[DataProvider('transferEncodings')]
    public function testSupportedTransferEncodings(string $encoding, string $input, string $expected): void
    {
        $part = new MultipartPart(['content-disposition' => 'form-data; name=x', 'content-transfer-encoding' => $encoding], $input);
        self::assertSame($expected, $part->getDecodedBody());
    }

    public static function invalidTransferEncodings(): iterable
    {
        yield ['base64', '%%%'];
        yield ['unknown', 'data'];
    }

    #[DataProvider('invalidTransferEncodings')]
    public function testInvalidTransferEncodingDoesNotSilentlyPassThrough(string $encoding, string $input): void
    {
        $part = new MultipartPart(['content-transfer-encoding' => $encoding], $input);
        $this->expectException(BodyParsingException::class);
        $part->getDecodedBody();
    }

    public static function invalidExtendedFilenames(): iterable
    {
        foreach (["UTF-8''bad%Z1", "UTF-8''bad%FF", "UNKNOWN''name", "''name", 'unstructured'] as $filename) {
            yield [$filename];
        }
    }

    #[DataProvider('invalidExtendedFilenames')]
    public function testMalformedExtendedFilenameIsRejected(string $filename): void
    {
        $part = new MultipartPart(['content-disposition' => 'form-data; name=x; filename*= ' . $filename], '');
        $this->expectException(BodyParsingException::class);
        $part->getFilename();
    }

    public function testExtendedUtf8FilenameTakesPrecedence(): void
    {
        $part = new MultipartPart(['content-disposition' => "form-data; name=x; filename=fallback; filename*=UTF-8'en'caf%C3%A9.txt"], '');
        self::assertSame('café.txt', $part->getFilename());
    }
}

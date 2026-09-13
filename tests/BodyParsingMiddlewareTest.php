<?php

declare(strict_types=1);

namespace Componenta\Http\Middleware\Tests;

use Componenta\Http\Middleware\BodyParsingMiddleware;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class BodyParsingMiddlewareTest extends TestCase
{
    /** @return iterable<string, array{string, array<array-key, mixed>}> */
    public static function fieldNames(): iterable
    {
        yield 'dot' => ['user.name', ['user_name' => 'Alice']];
        yield 'space' => ['user name', ['user_name' => 'Alice']];
        yield 'leading and trailing spaces' => [' user.name ', ['user_name_' => 'Alice']];
        yield 'nested base' => ['user.name[locale]', ['user_name' => ['locale' => 'Alice']]];
        yield 'nested key remains literal' => ['user[display.name]', ['user' => ['display.name' => 'Alice']]];
        yield 'array item' => ['user.tags[]', ['user_tags' => ['Alice']]];
    }

    /** @param array<array-key, mixed> $expected */
    #[DataProvider('fieldNames')]
    public function testFormAndMultipartUsePhpFieldNameSemantics(string $name, array $expected): void
    {
        $form = $this->parse('application/x-www-form-urlencoded', rawurlencode($name) . '=Alice');
        $multipart = $this->parse(
            'multipart/form-data; boundary=test-boundary',
            "--test-boundary\r\nContent-Disposition: form-data; name=\"$name\"\r\n\r\nAlice\r\n--test-boundary--\r\n",
        );

        self::assertSame($expected, $form->getParsedBody());
        self::assertSame($expected, $multipart->getParsedBody());
    }

    public function testUploadedFilesNormalizeOnlyTheRootAndPreserveArrayOrder(): void
    {
        $body = "--test-boundary\r\n"
            . "Content-Disposition: form-data; name=\"user.files[scan.name][]\"; filename=\"first.txt\"\r\n"
            . "Content-Type: text/plain\r\n\r\nfirst\r\n"
            . "--test-boundary\r\n"
            . "Content-Disposition: form-data; name=\"user.files[scan.name][]\"; filename=\"second.txt\"\r\n"
            . "Content-Type: text/plain\r\n\r\nsecond\r\n"
            . "--test-boundary--\r\n";

        $request = $this->parse('multipart/form-data; boundary=test-boundary', $body);
        $files = $request->getUploadedFiles();

        self::assertSame(['user_files'], array_keys($files));
        $root = $files['user_files'];
        self::assertIsArray($root);
        self::assertSame(['scan.name'], array_keys($root));
        $uploads = $root['scan.name'];
        self::assertIsArray($uploads);
        self::assertSame([0, 1], array_keys($uploads));
        $first = $uploads[0];
        $second = $uploads[1];
        self::assertInstanceOf(UploadedFileInterface::class, $first);
        self::assertInstanceOf(UploadedFileInterface::class, $second);
        self::assertSame('first.txt', $first->getClientFilename());
        self::assertSame('first', (string) $first->getStream());
        self::assertSame('second.txt', $second->getClientFilename());
        self::assertSame('second', (string) $second->getStream());
    }

    /** @return iterable<string, array{string, string, int}> */
    public static function uploads(): iterable
    {
        yield 'file input left unselected' => ['', '', UPLOAD_ERR_NO_FILE];
        yield 'selected empty file' => ['empty.txt', '', UPLOAD_ERR_OK];
        yield 'selected nonempty file' => ['document.txt', 'hello', UPLOAD_ERR_OK];
    }

    #[DataProvider('uploads')]
    public function testUploadsPreserveWhetherTheUserSelectedAFile(
        string $filename,
        string $contents,
        int $expectedError,
    ): void {
        $body = "--test-boundary\r\n"
            . "Content-Disposition: form-data; name=\"attachment\"; filename=\"$filename\"\r\n"
            . "Content-Type: application/octet-stream\r\n\r\n$contents\r\n"
            . "--test-boundary--\r\n";

        $request = $this->parse('multipart/form-data; boundary=test-boundary', $body);
        $files = $request->getUploadedFiles();
        self::assertSame(['attachment'], array_keys($files));
        $upload = $files['attachment'];
        self::assertInstanceOf(UploadedFileInterface::class, $upload);
        self::assertSame($expectedError, $upload->getError());
        self::assertSame($filename, $upload->getClientFilename());
        self::assertSame(strlen($contents), $upload->getSize());

        if ($expectedError === UPLOAD_ERR_OK) {
            self::assertSame($contents, (string) $upload->getStream());
        }
    }

    /** @return iterable<string, array{string}> */
    public static function emptyNames(): iterable
    {
        yield 'empty' => [''];
        yield 'spaces only' => ['  '];
    }

    #[DataProvider('emptyNames')]
    public function testEmptyFieldNamesDoNotAddEntries(string $name): void
    {
        $body = "--test-boundary\r\nContent-Disposition: form-data; name=\"$name\"\r\n\r\nignored\r\n"
            . "--test-boundary\r\nContent-Disposition: form-data; name=\"kept\"\r\n\r\nAlice\r\n"
            . "--test-boundary--\r\n";

        self::assertSame(['kept' => 'Alice'], $this->parse('multipart/form-data; boundary=test-boundary', $body)->getParsedBody());
    }

    /** @return iterable<string, array{string, string, array<array-key, mixed>}> */
    public static function contentTypes(): iterable
    {
        yield 'json object' => ['application/json', '{"name":"Alice"}', ['name' => 'Alice']];
        yield 'json scalar' => ['application/json', '42', ['__scalar' => 42]];
        yield 'urlencoded arrays' => [
            'application/x-www-form-urlencoded',
            'user[name]=Alice&tags[]=one&tags[]=two',
            ['user' => ['name' => 'Alice'], 'tags' => ['one', 'two']],
        ];
    }

    /** @param array<array-key, mixed> $expected */
    #[DataProvider('contentTypes')]
    public function testParsesSupportedContentTypes(string $type, string $body, array $expected): void
    {
        self::assertSame($expected, $this->parse($type, $body)->getParsedBody());
    }

    private function parse(string $contentType, string $body): ServerRequestInterface
    {
        $factory = new Psr17Factory();
        $middleware = new BodyParsingMiddleware($factory, $factory);
        $request = $factory->createServerRequest('PATCH', '/profile')
            ->withHeader('Content-Type', $contentType)
            ->withBody($factory->createStream($body));
        $handler = new class implements RequestHandlerInterface {
            public ?ServerRequestInterface $received = null;

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->received = $request;
                return (new Psr17Factory())->createResponse(204);
            }
        };

        self::assertSame(204, $middleware->process($request, $handler)->getStatusCode());
        self::assertInstanceOf(ServerRequestInterface::class, $handler->received);
        return $handler->received;
    }
}

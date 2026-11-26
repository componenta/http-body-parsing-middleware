# Componenta HTTP Body Parsing Middleware

PSR-15 middleware for parsing HTTP request bodies into PSR-7 parsed body and uploaded files. It supports JSON, `application/x-www-form-urlencoded`, and `multipart/form-data`.

Use this package in HTTP applications that need parsed bodies for methods and content types PHP does not parse natively.

## Installation

Requires PHP 8.4+ with mbstring and ctype. The Composer dependency constraint permits Config 3 and 4.

```bash
composer require componenta/http-body-parsing-middleware
```

The package exposes `Componenta\Http\Middleware\BodyParsingConfigProvider` through Composer metadata.

## Configuration

The config provider registers `BodyParsingMiddleware` from:

- `StreamFactoryInterface`
- `UploadedFileFactoryInterface`
- `ResponseFactoryInterface`

Install one PSR-7/PSR-17 integration package so these factories exist in the container.

## Runtime Behavior

The middleware skips requests when:

- `getParsedBody()` is already set;
- the method is `GET`, `HEAD`, `OPTIONS`, or `TRACE`;
- multipart uploads are already populated in the PSR-7 request.

Scalar JSON values are preserved under the `__scalar` key for compatibility. `BodyParsingMiddleware::ATTR_JSON_KIND` is `scalar`, `object`, or `array` and distinguishes root representations. Integers outside PHP integer range are decoded as strings.

Multipart field and upload names follow PHP naming rules: leading spaces are removed and dots/spaces in the root name become underscores. Nested keys keep their original spelling.

Run the package tests with `composer test` after installing development dependencies.

## Public Classes

- `BodyParsingMiddleware` is the PSR-15 middleware.
- `Body\MultipartParser` parses multipart bodies.
- `Body\MultipartPart` represents one multipart part.

## Related Packages

- [`componenta/http-psr`](../http-psr/README.md) wires server request creation.
- [`componenta/app-http`](../app-http/README.md) runs HTTP middleware.

## Limits and malformed requests

Raw supported bodies are read in bounded chunks (default 8 MiB), including POST forms not already parsed by the request factory. `BodyParsingLimits` configures body bytes, part bytes, part count, per-part header bytes, and nesting depth. Multipart bodies are still buffered, not fully streaming; configure transport limits independently and choose limits appropriate to available memory. Seekable stream positions are restored; non-seekable streams are replaced with an equivalent buffered stream on success. Non-seekable input must be provided at its beginning.

Malformed input produces `BodyParsingException` (400) or a limit failure (413). Supply `responseFactory:` to convert these into responses; the config provider supplies it by default. Already parsed data is trusted and must have been bounded by its producer. Multipart files with repeated field names are preserved as lists. Incomplete multipart messages and malformed/duplicate header parameters are rejected. Bare LF and deprecated transfer encodings/extended filenames are supported only for compatibility. Treat client filenames and media types as untrusted metadata. This package does not validate uploaded file contents.

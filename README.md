# Componenta HTTP Body Parsing Middleware

PSR-15 middleware for parsing HTTP request bodies into PSR-7 parsed body and uploaded files. It supports JSON, `application/x-www-form-urlencoded`, and `multipart/form-data`.

Use this package in HTTP applications that need parsed bodies for methods and content types PHP does not parse natively.

## Installation

```bash
composer require componenta/http-body-parsing-middleware
```

The package exposes `Componenta\Http\Middleware\BodyParsingConfigProvider` through Composer metadata.

## Configuration

The config provider registers `BodyParsingMiddleware` from:

- `StreamFactoryInterface`
- `UploadedFileFactoryInterface`

Install one PSR-7/PSR-17 integration package so these factories exist in the container.

## Runtime Behavior

The middleware skips requests when:

- `getParsedBody()` is already set;
- the method is `GET`, `HEAD`, `OPTIONS`, or `TRACE`;
- PHP has already parsed a native `POST` form or multipart request.

Scalar JSON values are preserved under the `__scalar` key because PSR-7 parsed bodies must be `null`, array, or object.

## Public Classes

- `BodyParsingMiddleware` is the PSR-15 middleware.
- `Body\MultipartParser` parses multipart bodies.
- `Body\MultipartPart` represents one multipart part.

## Related Packages

- [`componenta/http-psr`](../http-psr/README.md) wires server request creation.
- [`componenta/app-http`](../app-http/README.md) runs HTTP middleware.

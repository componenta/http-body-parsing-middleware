# Componenta HTTP Body Parsing Middleware

PSR-15 промежуточный обработчик для разбора тела HTTP-запроса в разобранное тело и загруженные файлы PSR-7 запроса. Поддерживает JSON, `application/x-www-form-urlencoded` и `multipart/form-data`.

Используйте пакет в HTTP-приложениях, которым нужен разбор тела для методов и типов содержимого, которые PHP не разбирает нативно.

## Установка

```bash
composer require componenta/http-body-parsing-middleware
```

Пакет публикует `Componenta\Http\Middleware\BodyParsingConfigProvider` через метаданные Composer.

## Конфигурация

Провайдер регистрирует `BodyParsingMiddleware` из:

- `StreamFactoryInterface`
- `UploadedFileFactoryInterface`

Установите один интеграционный пакет PSR-7/PSR-17, чтобы эти фабрики были в контейнере.

## Поведение

Промежуточный обработчик пропускает запрос, если:

- `getParsedBody()` уже установлен;
- метод равен `GET`, `HEAD`, `OPTIONS` или `TRACE`;
- PHP уже разобрал нативный `POST` form или multipart запрос.

Скалярные JSON-значения сохраняются в ключе `__scalar`, потому что parsed body в PSR-7 должен быть `null`, массивом или объектом.

## Основные классы

- `BodyParsingMiddleware` является PSR-15 промежуточным обработчиком.
- `Body\MultipartParser` разбирает multipart-тело.
- `Body\MultipartPart` представляет одну multipart-часть.

## Связанные пакеты

- [`componenta/http-psr`](../http-psr/README.ru.md) связывает создание серверного запроса.
- [`componenta/app-http`](../app-http/README.ru.md) запускает HTTP-промежуточные обработчики.

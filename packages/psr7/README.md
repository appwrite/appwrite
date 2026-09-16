# Utopia PSR-7

> [!IMPORTANT]
> This repository is a read-only mirror of the [utopia-php monorepo](https://github.com/utopia-php/monorepo). Development happens in [`packages/psr7`](https://github.com/utopia-php/monorepo/tree/main/packages/psr7) — please open issues and pull requests there.

PSR-7 HTTP message implementations and PSR-17 factories for PHP 8.4+.

## Install

```bash
composer require utopia-php/psr7
```

## Quick start

```php
<?php

use Utopia\Psr7\Method;
use Utopia\Psr7\Request;
use Utopia\Psr7\Response;
use Utopia\Psr7\Stream;

require __DIR__ . '/vendor/autoload.php';

$request = new Request\Factory()->json(Method::POST, 'https://example.com/users', [
    'name' => 'Ada',
]);

$response = new Response(200, body: new Stream('{"ok":true}'));

echo $request->getMethod();
echo $response->json()['ok'];
```

The package includes immutable request, response, URI, and stream implementations, plus helpers for JSON, XML, text, form, query-string, raw-body, and multipart payloads.

## Development

Local copies of the relevant PSR and multipart RFC documents live under `docs/`, with translated coverage requirements in [testing-requirements.md](docs/testing-requirements.md).

> [!IMPORTANT]
> This repository is a read-only mirror of the [utopia-php monorepo](https://github.com/utopia-php/monorepo). Development happens in [`packages/http`](https://github.com/utopia-php/monorepo/tree/main/packages/http) — please open issues and pull requests there.

<p>
    <img height="45" src="docs/logo.png" alt="Logo">
</p>

[![Build Status](https://github.com/utopia-php/http/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/utopia-php/http/actions/workflows/ci.yml)
![Total Downloads](https://img.shields.io/packagist/dt/utopia-php/http.svg)
[![Discord](https://img.shields.io/discord/564160730845151244?label=discord)](https://discord.gg/GSeTUeA)

Utopia HTTP is a PHP MVC based framework with minimal must-have features for professional, simple, advanced and secure web development. This library is maintained by the [Appwrite team](https://appwrite.io).

Utopia HTTP keeps routing and request lifecycle concerns separate from resource wiring by relying on the standalone Utopia DI package for dependency injection.

## Getting started

Install using Composer:

```bash
composer require utopia-php/http
```

Init your first application in `src/server.php`:

```php
require_once __DIR__.'/../vendor/autoload.php';

use Utopia\DI\Container;
use Utopia\Http\Http;
use Utopia\Http\Request;
use Utopia\Http\Response;
use Utopia\Http\Adapter\FPM\Server;

$resources = new Container();

Http::get('/hello-world') // Define Route
    ->inject('request')
    ->inject('response')
    ->action(
        function(Request $request, Response $response) {
            $response
              ->addHeader('Cache-Control', 'no-cache, no-store, must-revalidate')
              ->addHeader('Expires', '0')
              ->addHeader('Pragma', 'no-cache')
              ->json(['Hello' => 'World']);
        }
    );

Http::setMode(Http::MODE_TYPE_PRODUCTION);

$http = new Http(new Server($resources), 'America/New_York');
$http->start();
```

Run HTTP server:

```bash
php -S localhost:8000 src/server.php 
```

Send HTTP request:

```bash
curl http://localhost:8000/hello-world
```

### Server adapters

The library supports server adapters to be able to run on any PHP setup. You could use the FPM or Swoole server.

#### Use PHP FPM server

```php
use Utopia\DI\Container;
use Utopia\Http\Http;
use Utopia\Http\Response;
use Utopia\Http\Adapter\FPM\Server;

$resources = new Container();

Http::get('/')
    ->inject('response')
    ->action(
        function(Response $response) {
            $response->send('Hello from PHP FPM');
        }
    );

$http = new Http(new Server($resources), 'America/New_York');
$http->start();
```

> When using PHP FPM, you can use the command `php -S localhost:80 src/server.php` to run the HTTP server locally

#### Using Swoole server

```php
use Utopia\DI\Container;
use Utopia\Http\Http;
use Utopia\Http\Request;
use Utopia\Http\Response;
use Utopia\Http\Adapter\Swoole\Server;

$resources = new Container();

Http::get('/')
    ->inject('request')
    ->inject('response')
    ->action(
        function(Request $request, Response $response) {
            $response->send('Hello from Swoole');
        }
    );

$http = new Http(new Server('0.0.0.0', '80', resources: $resources), 'America/New_York');
$http->start();
```

> When using Swoole, you can use the command `php src/server.php` to run the HTTP server locally, but you need Swoole installed. For setup with Docker, check out our [example application](/example)

### Parameters

Parameters are used to receive input into endpoint action from the HTTP request. Parameters could be defined as URL parameters or in a body with a structure such as JSON.

Every parameter must have a validator defined. Validators are simple classes that verify the input and ensure the security of inputs. You can define your own validators or use some of [built-in validators](/src/Http/Validator).

Define an endpoint with params:

```php
Http::get('/')
    ->param('name', 'World', new Text(256), 'Name to greet. Optional, max length 256.', true)
    ->inject('response')
    ->action(function(string $name, Response $response) {
        $response->send('Hello ' . $name);
    });
```

Send HTTP requests to ensure the parameter works:

```bash
curl http://localhost:8000/hello-world
curl http://localhost:8000/hello-world?name=Utopia
curl http://localhost:8000/hello-world?name=Appwrite
```

It's always recommended to use params instead of getting params or body directly from the request resource. If you do that intentionally, always make sure to run validation right after fetching such a raw input.

### Multiple methods

A route can be registered under additional paths and multiple HTTP methods. All matching paths and methods dispatch to the same route, so the action, params, and hooks are defined only once.

Use `alias()` to serve the same route under another path, for example to keep a legacy URL working:

```php
Http::get('/users/:id')
    ->alias('/members/:id')
    ->param('id', '', new Text(256), 'User ID')
    ->inject('response')
    ->action(function(string $id, Response $response) {
        $response->json(['id' => $id]);
    });
```

Use `routes()` to serve the same route under multiple HTTP methods. For example, the OpenID Connect UserInfo endpoint must support both GET and POST:

```php
Http::routes([Http::REQUEST_METHOD_GET, Http::REQUEST_METHOD_POST], '/oauth/userinfo')
    ->inject('request')
    ->inject('response')
    ->action(function(Request $request, Response $response) {
        // $request->getMethod() tells how the request arrived (GET or POST)
        $response->json(['sub' => 'user-id']);
    });
```

Path aliases and multiple methods combine: a route with both responds on every method under every path. Use `getMethods()` to inspect the methods a route was registered with, and use the request resource to tell how a request arrived.

### Hooks

There are three types of hooks:

- **Init hooks** are executed before the route action is executed
- **Shutdown hooks** are executed after route action is finished, but before application shuts down
- **Error hooks** are executed whenever there's an error in the application lifecycle.

You can provide multiple hooks for each stage. If you do not assign groups to the hook, by default, the hook will be executed for every route. If a group is defined on a hook, it will only run during the lifecycle of a request with the same group name on the action.

```php
Http::init()
    ->inject('request')
    ->action(function(Request $request) {
        \var_dump("Recieved: " . $request->getMethod() . ' ' . $request->getURI());
    });

Http::shutdown()
    ->inject('response')
    ->action(function(Response $response) {
        \var_dump('Responding with status code: ' . $response->getStatusCode());
    });

Http::error()
    ->inject('error')
    ->inject('response')
    ->action(function(\Throwable $error, Response $response) {
        $response
            ->setStatusCode(500)
            ->send('Error occurred ' . $error);
    });
```

Hooks are designed to be actions that run during the lifecycle of requests. Hooks should include functional logic. Hooks are not designed to prepare dependencies or context for the request. For such a use case, you should use resources.

### Groups

Groups allow you to define common behavior for multiple endpoints.

You can start by defining a group on an endpoint. Keep in mind you can also define multiple groups on a single endpoint.

```php
Http::get('/v1/health')
    ->groups(['api', 'public'])
    ->inject('response')
    ->action(
        function(Response $response) {
            $response->send('OK');
        }
    );
```

Now you can define hooks that would apply only to specific groups. Remember, hooks can also be assigned to multiple groups.

```php
Http::init()
    ->groups(['api'])
    ->inject('request')
    ->inject('response')
    ->action(function(Request $request, Response $response) {
        $apiKey = $request->getHeader('x-api-key', '');

        if(empty($apiKey)) {
            $response
                ->setStatusCode(Response::STATUS_CODE_UNAUTHORIZED)
                ->send('API key missing.');
        }
    });
```

Groups are designed to be actions that run during the lifecycle of requests to endpoints that have some logic in common. Groups allow you to prevent code duplication and are designed to be defined anywhere in your source code to allow flexibility.

### Resources

Resources allow you to prepare dependencies for requests such as database connections or shared services. Register application dependencies on the resources container with `set()`. Runtime values such as `request`, `response`, `route`, and `error` are registered on the per-request `context` container by `Http` for each request.

Define a dependency on the resources container:

```php
$resources->set('bootTime', function () {
    return \microtime(true);
});
```

Inject resource into endpoint action:

```php
$http = new Http(new Server($resources), 'America/New_York');

Http::get('/')
    ->inject('bootTime')
    ->inject('response')
    ->action(function(float $bootTime, Response $response) {
        $response->send('Process started at: ' . \strval($bootTime));
    });
```

Inject resource into a hook:

```php
Http::shutdown()
    ->inject('bootTime')
    ->action(function(float $bootTime) {
        $uptime = \microtime(true) - $bootTime;
        \var_dump("Process uptime: " . $uptime . " seconds");
    });
```

In advanced scenarios, resources can also be injected into other resources or endpoint parameters.

Resources are designed to prepare dependencies or context for the request. Resources are not meant to do functional logic or return callbacks. For such a use case, you should use hooks.

To learn more about architecture and features for this library, check out more in-depth [Getting started guide](/docs/Getting-Starting-Guide.md).

## System requirements

Utopia HTTP requires PHP 8.3 or later. We recommend using the latest PHP version whenever possible.

## More from Utopia

Our ecosystem supports other thin PHP projects aiming to extend the core PHP Utopia HTTP.

Each project is focused on solving a single, very simple problem and you can use Composer to include any of them in your next project.

You can find all libraries in [GitHub Utopia organization](https://github.com/utopia-php).

## Contributing

All code contributions - including those of people having commit access - must go through a pull request and approved by a core developer before being merged. This is to ensure proper review of all the code.

Fork the [utopia-php monorepo](https://github.com/utopia-php/monorepo), create a feature branch, and send us a pull request.

You can refer to the [Contributing Guide](https://github.com/utopia-php/monorepo/blob/main/CONTRIBUTING.md) for more info.

For security issues, please email security@appwrite.io instead of posting a public issue in GitHub.

## Copyright and license

The MIT License (MIT) [http://www.opensource.org/licenses/mit-license.php](http://www.opensource.org/licenses/mit-license.php)

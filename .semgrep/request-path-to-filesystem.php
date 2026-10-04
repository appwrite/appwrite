<?php

use Utopia\Http\Http;

class RequestPathFixture
{
    public function action(string $path, Response $response): void
    {
        // ruleid: php.appwrite.request-path-to-filesystem
        $contents = file_get_contents(APP_STORAGE_UPLOADS . '/' . $path);

        // ruleid: php.appwrite.request-path-to-filesystem
        \unlink(APP_STORAGE_UPLOADS . '/' . $path);

        // ok: php.appwrite.request-path-to-filesystem
        $safe = file_get_contents(APP_STORAGE_UPLOADS . '/' . \basename($path));
    }
}

class RealpathFixture
{
    public function action(string $name, Request $request, Response $response): void
    {
        $base = \realpath(APP_STORAGE_UPLOADS);

        // ruleid: php.appwrite.request-path-to-filesystem
        $contents = file_get_contents(realpath($base . '/' . $request->getParam('path')));

        $unchecked = \realpath($base . '/' . $name);
        // ruleid: php.appwrite.request-path-to-filesystem
        $response->send(\file_get_contents($unchecked));

        $warned = \realpath($base . '/' . $name);
        if (!\str_starts_with($warned, $base . '/')) {
            Console::warning('Path outside base');
        }
        // ruleid: php.appwrite.request-path-to-filesystem
        \unlink($warned);

        $real = \realpath($base . '/' . $name);
        if (!\str_starts_with($real, $base . '/')) {
            throw new Exception(Exception::GENERAL_UNAUTHORIZED_SCOPE);
        }
        // ok: php.appwrite.request-path-to-filesystem
        $response->send(\file_get_contents($real));

        $absolute = \realpath($base . '/' . $request->getParam('file', ''));
        if (\substr($absolute, 0, \strlen($base)) !== $base) {
            throw new Exception(Exception::GENERAL_UNAUTHORIZED_SCOPE);
        }
        // ok: php.appwrite.request-path-to-filesystem
        \readfile($absolute);

        $inside = \realpath($base . '/' . $name);
        if (\str_starts_with($inside, $base . '/')) {
            // ok: php.appwrite.request-path-to-filesystem
            \unlink($inside);
        }
    }
}

Http::get('/v1/things/:file')
    ->action(function (string $file, Request $request) {
        // ruleid: php.appwrite.request-path-to-filesystem
        include __DIR__ . '/views/' . $file;

        $name = $request->getParam('name', '');
        // ruleid: php.appwrite.request-path-to-filesystem
        unlink('/tmp/' . $name);

        // ok: php.appwrite.request-path-to-filesystem
        readfile(__DIR__ . '/views/index.html');
    });

foreach (Config::getParam('services', []) as $service) {
    // ok: php.appwrite.request-path-to-filesystem
    include_once $service['controller'];
}

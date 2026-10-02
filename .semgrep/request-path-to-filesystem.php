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

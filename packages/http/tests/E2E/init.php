<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Utopia\Http\Http;
use Utopia\Http\Request;
use Utopia\Http\Response;
use Utopia\Validator\JSON\ObjectValidator;
use Utopia\Validator\Text;

ini_set('memory_limit', '1024M');
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
ini_set('display_socket_timeout', '-1');
error_reporting(E_ALL);

Http::get('/')
    ->inject('response')
    ->action(function (Response $response) {
        $response->send('Hello World!');
    });

Http::get('/value/:value')
    ->param('value', '', new Text(64))
    ->inject('response')
    ->action(function (string $value, Response $response) {
        $response->send($value);
    });


Http::get('/cookies')
    ->inject('request')
    ->inject('response')
    ->action(function (Request $request, Response $response) {
        $response->send($request->getHeaderLine('cookie'));
    });

Http::get('/cookie/:key')
    ->param('key', '', new Text(64))
    ->inject('request')
    ->inject('response')
    ->action(function (string $key, Request $request, Response $response) {
        $response->send($request->getCookie($key, ''));
    });

Http::get('/set-cookie')
    ->inject('request')
    ->inject('response')
    ->action(function (Request $request, Response $response) {
        $response->addHeader('Set-Cookie', 'key1=value1');
        $response->addHeader('Set-Cookie', 'key2=value2');
        $response->send('OK');
    });

Http::get('/chunked')
    ->inject('response')
    ->action(function (Response $response) {
        foreach (['Hello ', 'World!'] as $key => $word) {
            $response->chunk($word, $key === 1);
        }
    });

Http::get('/redirect')
    ->inject('response')
    ->action(function (Response $response) {
        $response->redirect('/');
    });

Http::get('/humans.txt')
    ->inject('response')
    ->action(function (Response $response) {
        $response->noContent();
    });

Http::get('/aliased')
    ->alias('/aliased-1')
    ->alias('/aliased-2')
    ->alias('/aliased-3')
    ->inject('response')
    ->action(function (Response $response) {
        $response->send('Aliased!');
    });

Http::post('/object')
    ->param('data', [], new ObjectValidator())
    ->inject('response')
    ->action(function (mixed $data, Response $response) {
        $response->json(['data' => $data]);
    });

<?php

use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\App;

App::init()
    ->groups(['web'])
    ->inject('request')
    ->inject('response')
    ->action(function (Request $request, Response $response) {
        $response
            ->addHeader('X-Frame-Options', 'SAMEORIGIN') // Avoid console and homepage from showing in iframes
            ->addHeader('X-XSS-Protection', '1; mode=block; report=/v1/xss?url=' . \urlencode($request->getURI()))
            ->addHeader('X-UA-Compatible', 'IE=Edge') // Deny IE browsers from going into quirks mode
        ;
    });

App::get('/console/*')
    ->alias('/')
    ->alias('auth/*')
    ->alias('/invite')
    ->alias('/login')
    ->alias('/recover')
    ->alias('/register/*')
    ->groups(['web'])
    ->label('permission', 'public')
    ->label('scope', 'home')
    ->inject('response')
    ->action(function (Response $response) {
        $fallback = file_get_contents(__DIR__ . '/../../../console/index.html');
        $response->html($fallback);
    });

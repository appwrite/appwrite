<?php

use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\App;

App::init()
    ->groups(['web'])
    ->inject('request')
    ->inject('response')
    ->action(function (Request $request, Response $response) {
        $time = (60 * 60 * 24 * 45); // 45 days cache

        $response
            ->addHeader('Cache-Control', 'public, max-age=' . $time)
            ->addHeader('Expires', \date('D, d M Y H:i:s', \time() + $time) . ' GMT') // 45 days cache
            ->addHeader('X-Frame-Options', 'SAMEORIGIN') // Avoid console and homepage from showing in iframes
            ->addHeader('X-XSS-Protection', '1; mode=block; report=/v1/xss?url=' . \urlencode($request->getURI()))
            ->addHeader('X-UA-Compatible', 'IE=Edge') // Deny IE browsers from going into quirks mode
        ;

    });

App::get('/console')
    ->alias('/')
    ->alias('/invite')
    ->alias('/login')
    ->alias('/recover')
    ->alias('/register')
    ->groups(['web'])
    ->label('permission', 'public')
    ->label('scope', 'home')
    ->inject('response')
    ->action(function (Response $response) {
        $fallback = file_get_contents(__DIR__ . '/../../../console/index.html');
        $response->html($fallback);
    });

<?php

use Appwrite\Utopia\Response;
use Utopia\App;

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
        $fallback = file_get_contents(__DIR__.'/../../../console/index.html');
        $response->html($fallback);
    });

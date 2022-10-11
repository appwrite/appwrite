<?php

use Appwrite\Utopia\Response;
use Utopia\App;

$fallbackRoute = function (Response $response) {
    $fallback = file_get_contents(__DIR__ . '/../../../console/index.html');
    $response->html($fallback);
};

App::get('/')
    ->groups(['web'])
    ->label('permission', 'public')
    ->label('scope', 'home')
    ->inject('response')
    ->action($fallbackRoute);

App::get('/console/*')
    ->groups(['web'])
    ->label('permission', 'public')
    ->label('scope', 'home')
    ->inject('response')
    ->action($fallbackRoute);

App::get('/invite')
    ->groups(['web'])
    ->label('permission', 'public')
    ->label('scope', 'home')
    ->inject('response')
    ->action($fallbackRoute);

App::get('/login')
    ->groups(['web'])
    ->label('permission', 'public')
    ->label('scope', 'home')
    ->inject('response')
    ->action($fallbackRoute);

App::get('/recover')
    ->groups(['web'])
    ->label('permission', 'public')
    ->label('scope', 'home')
    ->inject('response')
    ->action($fallbackRoute);

App::get('/register')
    ->groups(['web'])
    ->label('permission', 'public')
    ->label('scope', 'home')
    ->inject('response')
    ->action($fallbackRoute);


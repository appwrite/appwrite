<?php

include_once 'shared/web.php';

global $utopia, $response, $request, $layout, $version, $providers, $sdks;

use Utopia\View;
use Utopia\Locale\Locale;

$layout
    ->setParam('title', APP_NAME)
    ->setParam('description', Locale::getText('general.description'))
    ->setParam('class', 'home')
    ->setParam('header', [new View(__DIR__ . '/../views/home/comps/header.phtml')])
;

$utopia->shutdown(function() use ($utopia, $response, $request, $layout, $version, $env) {
    $response->send($layout->render());
});

$utopia->get('/')
    ->label('permission', 'public')
    ->label('scope', 'home')
    ->action(
        function() use ($response)
        {
            $response->redirect('/auth/signin');
        }
    );

$utopia->get('/auth/signin')
    ->desc('Login page')
    ->label('permission', 'public')
    ->label('scope', 'home')
    ->action(function() use ($layout)
    {
        $page = new View(__DIR__ . '/../views/home/auth/signin.phtml');

        $layout
            ->setParam('title', Locale::getText('home.auth.signin.title') . ' - ' . APP_NAME)
            ->setParam('body', $page);
    });

$utopia->get('/auth/signup')
    ->desc('Registration page')
    ->label('permission', 'public')
    ->label('scope', 'home')
    ->action(function() use ($layout)
    {
        $page = new View(__DIR__ . '/../views/home/auth/signup.phtml');

        $layout
            ->setParam('title', Locale::getText('home.auth.signup.title') . ' - ' . APP_NAME)
            ->setParam('body', $page);
    });

$utopia->get('/auth/recovery')
    ->desc('Password recovery page')
    ->label('permission', 'public')
    ->label('scope', 'home')
    ->action(function() use ($request, $layout)
    {
        $page = new View(__DIR__ . '/../views/home/auth/recovery.phtml');

        $layout
            ->setParam('title', Locale::getText('home.auth.recovery.title') . ' - ' . APP_NAME)
            ->setParam('body', $page);
    });

$utopia->get('/auth/confirm')
    ->desc('Account confirmation page')
    ->label('permission', 'public')
    ->label('scope', 'home')
    ->action(function() use ($layout)
    {
        $page = new View(__DIR__ . '/../views/home/auth/confirm.phtml');

        $layout
            ->setParam('title', Locale::getText('home.auth.confirm.title') . ' - ' . APP_NAME)
            ->setParam('body', $page);
    });

$utopia->get('/auth/join')
    ->desc('Account team join page')
    ->label('permission', 'public')
    ->label('scope', 'home')
    ->action(function() use ($layout)
    {
        $page = new View(__DIR__ . '/../views/home/auth/join.phtml');

        $layout
            ->setParam('title', Locale::getText('home.auth.join.title') . ' - ' . APP_NAME)
            ->setParam('body', $page);
    });

$utopia->get('/auth/recovery/reset')
    ->desc('Password recovery page')
    ->label('permission', 'public')
    ->label('scope', 'home')
    ->action(function() use ($layout)
    {
        $page = new View(__DIR__ . '/../views/home/auth/recovery/reset.phtml');

        $layout
            ->setParam('title', Locale::getText('home.auth.reset.title') . ' - ' . APP_NAME)
            ->setParam('body', $page);
    });

$utopia->get('/error/:code')
    ->desc('Error page')
    ->label('permission', 'public')
    ->label('scope', 'home')
    ->param('code', null, new \Utopia\Validator\Numeric(), 'Valid status code number', false)
    ->action(function($code) use ($layout)
    {
        $page = new View(__DIR__ . '/../views/error.phtml');

        $page
            ->setParam('code', $code)
        ;

        $layout
            ->setParam('title', 'Error' . ' - ' . APP_NAME)
            ->setParam('body', $page);
    });
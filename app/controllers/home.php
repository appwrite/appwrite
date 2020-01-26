<?php

include_once 'shared/web.php';

global $utopia, $response, $request, $layout, $version, $providers, $sdks, $platforms;

use Utopia\View;

$header = new View(__DIR__.'/../views/home/comps/header.phtml');
$footer = new View(__DIR__.'/../views/home/comps/footer.phtml');

$footer
    ->setParam('version', $version)
;

$layout
    ->setParam('title', APP_NAME)
    ->setParam('description', '')
    ->setParam('class', 'home')
    ->setParam('platforms', $platforms)
    ->setParam('header', [$header])
    ->setParam('footer', [$footer])
;

$utopia->shutdown(function () use ($response, $layout) {
    $response->send($layout->render());
});

$utopia->get('/')
    ->label('permission', 'public')
    ->label('scope', 'home')
    ->action(
        function () use ($response) {
            $response->redirect('/auth/signin');
        }
    );

$utopia->get('/auth/signin')
    ->desc('Login page')
    ->label('permission', 'public')
    ->label('scope', 'home')
    ->action(function () use ($layout) {
        $page = new View(__DIR__.'/../views/home/auth/signin.phtml');

        $layout
            ->setParam('title', 'Sign In - '.APP_NAME)
            ->setParam('body', $page);
    });

$utopia->get('/auth/signup')
    ->desc('Registration page')
    ->label('permission', 'public')
    ->label('scope', 'home')
    ->action(function () use ($layout) {
        $page = new View(__DIR__.'/../views/home/auth/signup.phtml');

        $layout
            ->setParam('title', 'Sign Up - '.APP_NAME)
            ->setParam('body', $page);
    });

$utopia->get('/auth/recovery')
    ->desc('Password recovery page')
    ->label('permission', 'public')
    ->label('scope', 'home')
    ->action(function () use ($request, $layout) {
        $page = new View(__DIR__.'/../views/home/auth/recovery.phtml');

        $layout
            ->setParam('title', 'Password Recovery - '.APP_NAME)
            ->setParam('body', $page);
    });

$utopia->get('/auth/confirm')
    ->desc('Account confirmation page')
    ->label('permission', 'public')
    ->label('scope', 'home')
    ->action(function () use ($layout) {
        $page = new View(__DIR__.'/../views/home/auth/confirm.phtml');

        $layout
            ->setParam('title', 'Account Confirmation - '.APP_NAME)
            ->setParam('body', $page);
    });

$utopia->get('/auth/join')
    ->desc('Account team join page')
    ->label('permission', 'public')
    ->label('scope', 'home')
    ->action(function () use ($layout) {
        $page = new View(__DIR__.'/../views/home/auth/join.phtml');

        $layout
            ->setParam('title', 'Invitation - '.APP_NAME)
            ->setParam('body', $page);
    });

$utopia->get('/auth/recovery/reset')
    ->desc('Password recovery page')
    ->label('permission', 'public')
    ->label('scope', 'home')
    ->action(function () use ($layout) {
        $page = new View(__DIR__.'/../views/home/auth/recovery/reset.phtml');

        $layout
            ->setParam('title', 'Password Reset - '.APP_NAME)
            ->setParam('body', $page);
    });

$utopia->get('/error/:code')
    ->desc('Error page')
    ->label('permission', 'public')
    ->label('scope', 'home')
    ->param('code', null, new \Utopia\Validator\Numeric(), 'Valid status code number', false)
    ->action(function ($code) use ($layout) {
        $page = new View(__DIR__.'/../views/error.phtml');

        $page
            ->setParam('code', $code)
        ;

        $layout
            ->setParam('title', 'Error'.' - '.APP_NAME)
            ->setParam('body', $page);
    });

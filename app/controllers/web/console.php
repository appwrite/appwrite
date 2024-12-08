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

App::get('/')
    ->alias('auth/*')
    ->alias('/invite')
    ->alias('/login')
    ->alias('/mfa')
    ->alias('/card/*')
    ->alias('/recover')
    ->alias('/register/*')
    ->groups(['web'])
    ->label('permission', 'public')
    ->label('scope', 'home')
    ->inject('request')
    ->inject('response')
    ->action(function (Request $request, Response $response) {
        $url = parse_url($request->getURI());
        $target = "/console{$url['path']}";
        $params = $request->getParams();
        if (!empty($params)) {
            $target .= "?" . \http_build_query($params);
        }
        if ($url['fragment'] ?? false) {
            $target .= "#{$url['fragment']}";
        }
        $response->redirect($target);
    });

<?php

use Appwrite\Utopia\Request;
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
    ->inject('request')
    ->inject('response')
    ->action(function (Request $request, Response $response) {
        $fallback = file_get_contents(__DIR__ . '/../../../console/index.html');

        // Card SSR
        if (\str_starts_with($request->getURI(), '/card')) {
            $urlCunks = \explode('/', $request->getURI());
            $userId = $urlCunks[\count($urlCunks) - 1] ?? '';

            $domain = $request->getProtocol() . '://' . $request->getHostname();

            if (!empty($userId)) {
                $ogImageUrl = $domain . '/v1/cards/cloud-og?userId=' . $userId;
            } else {
                $ogImageUrl = $domain . '/v1/cards/cloud-og?mock=normal';
            }

            $ogTags = [
                '<title>Appwrite Cloud Membership Card</title>',
                '<meta name="description" content="Appwrite Cloud is now LIVE! Share your Cloud card for a chance to win an exclusive Cloud hoodie!">',
                '<meta property="og:url" content="' . $domain . '/">',
                '<meta property="og:type" content="website">',
                '<meta property="og:title" content="Appwrite Cloud Membership Card">',
                '<meta property="og:description" content="Appwrite Cloud is now LIVE! Share your Cloud card for a chance to win an exclusive Cloud hoodie!">',
                '<meta property="og:image" content="' . $ogImageUrl . '">',
                '<meta name="twitter:card" content="summary_large_image">',
                '<meta property="twitter:domain" content="' . $request->getHostname() . '">',
                '<meta property="twitter:url" content="' . $domain . '/">',
                '<meta name="twitter:title" content="Appwrite Cloud Membership Card">',
                '<meta name="twitter:description" content="Appwrite Cloud is now LIVE! Share your Cloud card for a chance to win an exclusive Cloud hoodie!">',
                '<meta name="twitter:image" content="' . $ogImageUrl . '">',
            ];

            $fallback = \str_replace('<!-- {{CLOUD_OG}} -->', \implode('', $ogTags), $fallback);
        }

        $response->html($fallback);
    });

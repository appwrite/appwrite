<?php

use Appwrite\Client;
use Appwrite\Services\Avatars;
use Appwrite\Enums\Theme;
use Appwrite\Enums\Timezone;
use Appwrite\Enums\Output;

$client = (new Client())
    ->setEndpoint('https://<REGION>.cloud.appwrite.io/v1') // Your API Endpoint
    ->setProject('<YOUR_PROJECT_ID>') // Your project ID
    ->setSession(''); // The user session to authenticate with

$avatars = new Avatars($client);

$result = $avatars->getScreenshot(
    url: 'https://example.com',
    headers: [
        'Authorization' => 'Bearer token123',
        'X-Custom-Header' => 'value'
    ], // optional
    viewportWidth: 1920, // optional
    viewportHeight: 1080, // optional
    scale: 2, // optional
    theme: Theme::LIGHT(), // optional
    userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 14_0 like Mac OS X) AppleWebKit/605.1.15', // optional
    fullpage: true, // optional
    locale: 'en-US', // optional
    timezone: Timezone::AFRICAABIDJAN(), // optional
    latitude: 37.7749, // optional
    longitude: -122.4194, // optional
    accuracy: 100, // optional
    touch: true, // optional
    permissions: ["geolocation","notifications"], // optional
    sleep: 3, // optional
    width: 800, // optional
    height: 600, // optional
    quality: 85, // optional
    output: Output::JPG() // optional
);
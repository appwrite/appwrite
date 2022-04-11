<?php

global $cli;

use Appwrite\Event\Event;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Validator\Hostname;

$cli
    ->task('ssl')
    ->desc('Validate server certificates')
    ->param('domain', App::getEnv('_APP_DOMAIN', ''), new Hostname(), 'Domain to generate certificate for. If empty, main domain will be used.', true)
    ->action(function ($domain) {
        // HTTTP ping to check if domain is Appwrite server
        $ch = \curl_init();
        \curl_setopt($ch, CURLOPT_URL, 'http://appwrite/manifest.json');
        \curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        \curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        \curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        \curl_exec($ch);
        $statusCode = \curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = \curl_error($ch);
        \curl_close($ch);

        if($statusCode < 100) {
            return Console::error('Appwrite connection refused with message: ' . $error);
        }

        Console::success('Schedule a job to issue a TLS certificate for domain:' . $domain);

        // Scheduje a job
        Resque::enqueue(Event::CERTIFICATES_QUEUE_NAME, Event::CERTIFICATES_CLASS_NAME, [
            'domain' => $domain,
        ]);
    });
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
        Console::success('Scheduling a job to issue a TLS certificate for domain:' . $domain);

        // Scheduje a job
        Resque::enqueue(Event::CERTIFICATES_QUEUE_NAME, Event::CERTIFICATES_CLASS_NAME, [
            'domain' => $domain,
            'skipRenewCheck' => true
        ]);
    });
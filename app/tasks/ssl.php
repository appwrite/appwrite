<?php

global $cli;

use Appwrite\Event\Certificate;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Database\Document;
use Utopia\Validator\Hostname;

$cli
    ->task('ssl')
    ->desc('Validate server certificates')
    ->param('domain', App::getEnv('_APP_DOMAIN', ''), new Hostname(), 'Domain to generate certificate for. If empty, main domain will be used.', true)
    ->action(function ($domain) {
        Console::success('Scheduling a job to issue a TLS certificate for domain:' . $domain);

        Console::log('Issue a TLS certificate for master domain (' . $domain . ') in 30 seconds.
            Make sure your domain points to your server or restart to try again.');

        $event = new Certificate();
        $event
            ->setDomain(new Document([
                'domain' => $domain
            ]))
            ->setSkipRenewCheck(true)
            ->trigger();
    });

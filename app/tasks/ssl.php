<?php

global $cli;

use Appwrite\Event\Certificate;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Database\Document;

$cli
    ->task('ssl')
    ->desc('Validate server certificates')
    ->action(function () {
        $domain = App::getEnv('_APP_DOMAIN', '');

        Console::log('Issue a TLS certificate for master domain (' . $domain . ') in 30 seconds.
            Make sure your domain points to your server or restart to try again.');

        $event = new Certificate();
        $event
            ->setDomain(new Document([
                'domain' => $domain
            ]))
            ->trigger();
    });

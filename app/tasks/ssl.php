<?php

global $cli;

use Utopia\App;
use Utopia\CLI\Console;

$cli
    ->task('ssl')
    ->desc('Validate server certificates')
    ->action(function () {
        $domain = App::getEnv('_APP_DOMAIN', '');

        Console::log('Issue a TLS certificate for master domain ('.$domain.') in 30 seconds.
            Make sure your domain points to your server or restart to try again.');

        ResqueScheduler::enqueueAt(\time() + 30, 'v1-certificates', 'CertificatesV1', [
            'document' => [],
            'domain' => $domain,
            'validateTarget' => false,
            'validateCNAME' => false,
        ]);
    });
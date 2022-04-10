<?php

global $cli;

use Utopia\App;
use Utopia\CLI\Console;

$cli
    ->task('ssl')
    ->desc('Validate server certificates')
    ->action(function () {
        $domain = App::getEnv('_APP_DOMAIN', '');

        // TODO: Instead of waiting, let's ping Traefik. If responds, we can schedule instantly
        // TODO: Add support for argument (domain)

        Console::log('Issue a TLS certificate for master domain ('.$domain.') in 2 seconds.
            Make sure your domain points to your server or restart to try again.');

        // Const for types not available here
        ResqueScheduler::enqueueAt(\time() + 2, 'v1-certificates', 'CertificatesV1', [
            'domain' => $domain,
            'skipRenewCheck' => true // TODO: Discuss this behabiour. true? false? parameter? How do we document it?
        ]);
    });
#!/bin/env php
<?php

require_once __DIR__.'/../init.php';

global $request;

use Utopia\CLI\CLI;
use Utopia\CLI\Console;

$cli = new CLI();

$cli
    ->task('ssl')
    ->desc('Validate server certificates')
    ->action(function () use ($request) {
        $domain = $request->getServer('_APP_DOMAIN', '');

        Console::log('Issue a TLS certificate for master domain ('.$domain.')');

        Resque::enqueue('v1-certificates', 'CertificatesV1', [
            'document' => [],
            'domain' => $domain,
            'validateTarget' => false,
            'validateCNAME' => false,
        ]);
    });

$cli->run();

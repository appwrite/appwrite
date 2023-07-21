<?php

global $cli;

use Utopia\Validator\Text;
use Appwrite\Task\Setup;

$cli
    ->task('upgrade')
    ->desc('Upgrade Appwrite')
    ->param('httpPort', '', new Text(4), 'Server HTTP port', true)
    ->param('httpsPort', '', new Text(4), 'Server HTTPS port', true)
    ->param('organization', 'appwrite', new Text(0), 'Docker Registry organization', true)
    ->param('image', 'appwrite', new Text(0), 'Main appwrite docker image', true)
    ->param('interactive', 'Y', new Text(1), 'Run an interactive session', true)
    ->action(function ($httpPort, $httpsPort, $organization, $image, $interactive) {
        $setup = new Setup($httpPort, $httpsPort, $organization, $image, $interactive);
        $setup->upgrade();
    });

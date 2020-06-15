#!/bin/env php
<?php

require_once __DIR__.'/../init.php';

global $request;

use Appwrite\Storage\Device\Local;
use Utopia\CLI\CLI;
use Utopia\CLI\Console;
use Utopia\Domains\Domain;

$cli = new CLI();

$cli
    ->task('ssl')
    ->desc('Validate server certificates')
    ->action(function () use ($request) {
        $domain = $request->getServer('_APP_DOMAIN', '');

        Console::log('Issue a TLS certificate for master domain ('.$domain.')');

        ResqueScheduler::enqueueAt(time() + 30, 'v1-certificates', 'CertificatesV1', [
            'document' => [],
            'domain' => $domain,
            'validateTarget' => false,
            'validateCNAME' => false,
        ]);
    });

$cli
    ->task('doctor')
    ->desc('Validate server health')
    ->action(function () use ($request, $register) {
        Console::log("  __   ____  ____  _  _  ____  __  ____  ____     __  __  
 / _\ (  _ \(  _ \/ )( \(  _ \(  )(_  _)(  __)   (  )/  \ 
/    \ ) __/ ) __/\ /\ / )   / )(   )(   ) _)  _  )((  O )
\_/\_/(__)  (__)  (_/\_)(__\_)(__) (__) (____)(_)(__)\__/ ");

        Console::log("\n".'Running '.APP_NAME.' Doctor 👩‍⚕️ for version '.$request->getServer('_APP_VERSION', 'UNKNOWN').' ...'."\n");

        Console::log('Checking for production best practices...');
        
        try {
            $domain = new Domain($request->getServer('_APP_DOMAIN'));

            if(!$domain->isKnown() || $domain->isTest()) {
                Console::log('🔴 Hostname has a public suffix');
            }
            else {
                Console::log('🟢 Hostname has a public suffix');
            }
        } catch (\Throwable $th) {
            //throw $th;
        }
        
        try {
            $domain = new Domain($request->getServer('_APP_DOMAIN_TARGET'));

            if(!$domain->isKnown() || $domain->isTest()) {
                Console::log('🔴 CNAME target has a public suffix');
            }
            else {
                Console::log('🟢 CNAME target has a public suffix');
            }
        } catch (\Throwable $th) {
            //throw $th;
        }
        
        try {
            if($request->getServer('_APP_OPENSSL_KEY_V1', 'your-secret-key') === 'your-secret-key') {
                Console::log('🔴 Using a unique secret key for encryption');
            }
            else {
                Console::log('🟢 Using a unique secret key for encryption');
            }
        } catch (\Throwable $th) {
            //throw $th;
        }

        try {
            if($request->getServer('_APP_ENV', 'development') === 'development') {
                Console::log('🔴 App enviornment is set for production');
            }
            else {
                Console::log('🟢 App enviornment is set for production');
            }
        } catch (\Throwable $th) {
            //throw $th;
        }

        try {
            if($request->getServer('_APP_OPTIONS_ABUSE', 'disabled') === 'disabled') {
                Console::log('🔴 Abuse protection is enabled');
            }
            else {
                Console::log('🟢 Abuse protection is enabled');
            }
        } catch (\Throwable $th) {
            //throw $th;
        }

        sleep(1);

        try {
            Console::log("\n".'Checking connectivity...');
        } catch (\Throwable $th) {
            //throw $th;
        }

        try {
            $register->get('db'); /* @var $db PDO */
            Console::success('Database............connected 👍');
        } catch (\Throwable $th) {
            Console::error('Database.........disconnected 👎');
        }

        try {
            $register->get('cache');
            Console::success('Queue...............connected 👍');
        } catch (\Throwable $th) {
            Console::error('Queue............disconnected 👎');
        }

        try {
            $register->get('cache');
            Console::success('Cache...............connected 👍');
        } catch (\Throwable $th) {
            Console::error('Cache............disconnected 👎');
        }

        try {
            $mail = $register->get('smtp'); /* @var $mail \PHPMailer\PHPMailer\PHPMailer */

            $mail->addAddress('demo@example.com', 'Example.com');
            $mail->Subject = 'Test SMTP Connection';
            $mail->Body = 'Hello World';
            $mail->AltBody = 'Hello World';
    
            $mail->send();
            Console::success('SMTP................connected 👍');
        } catch (\Throwable $th) {
            Console::error('SMTP.............disconnected 👎');
        }

        $host = $request->getServer('_APP_STATSD_HOST', 'telegraf');
        $port = $request->getServer('_APP_STATSD_PORT', 8125);

        if($fp = @fsockopen('udp://'.$host, $port, $errCode, $errStr, 2)){   
            Console::success('StatsD..............connected 👍');
            fclose($fp);
        } else {
            Console::error('StatsD...........disconnected 👎');
        }

        $host = $request->getServer('_APP_INFLUXDB_HOST', '');
        $port = $request->getServer('_APP_INFLUXDB_PORT', '');

        if($fp = @fsockopen($host, $port, $errCode, $errStr, 2)){   
            Console::success('InfluxDB............connected 👍');
            fclose($fp);
        } else {
            Console::error('InfluxDB.........disconnected 👎');
        }

        sleep(1);

        Console::log('');
        Console::log('Checking volumes...');

        $device = new Local(APP_STORAGE_UPLOADS.'/');

        // Upload

        if (is_readable($device->getRoot())) {
            Console::success('Upload Volume........readable 👍');
        }
        else {
            Console::error('Upload Volume......unreadable 👎');
        }

        if (is_writable($device->getRoot())) {
            Console::success('Upload Volume.......writeable 👍');
        }
        else {
            Console::error('Upload Volume.....unwriteable 👎');
        }

        // Cache

        if (is_readable($device->getRoot().'/../cache')) {
            Console::success('Cache Volume.........readable 👍');
        }
        else {
            Console::error('Cache Volume.......unreadable 👎');
        }

        if (is_writable($device->getRoot().'/../cache')) {
            Console::success('Cache Volume........writeable 👍');
        }
        else {
            Console::error('Cache Volume......unwriteable 👎');
        }
        
        // Config

        if (is_readable($device->getRoot().'/../config')) {
            Console::success('Config Volume........readable 👍');
        }
        else {
            Console::error('Config Volume......unreadable 👎');
        }

        if (is_writable($device->getRoot().'/../config')) {
            Console::success('Config Volume.......writeable 👍');
        }
        else {
            Console::error('Config Volume.....unwriteable 👎');
        }

        // Certs

        if (is_readable($device->getRoot().'/../certificates')) {
            Console::success('Certs Volume.........readable 👍');
        }
        else {
            Console::error('Certs Volume.......unreadable 👎');
        }

        if (is_writable($device->getRoot().'/../certificates')) {
            Console::success('Certs Volume........writeable 👍');
        }
        else {
            Console::error('Certs Volume......unwriteable 👎');
        }

        
        try {
            Console::log('');
            $version = json_decode(@file_get_contents($request->getServer('_APP_HOME', 'http://localhost').'/v1/health/version'), true);
            
            if($version && isset($version['version'])) {
                if(version_compare($version['version'], $request->getServer('_APP_VERSION', 'UNKNOWN')) === 0) {
                    Console::info('You are running the latest version of '.APP_NAME.'! 🥳');
                }
                else {
                    Console::info('A new version ('.$version['version'].') is available! 🥳'."\n");
                }
            }
            else {
                Console::error('Failed to check for a newer version'."\n");
            }
        } catch (\Throwable $th) {
            Console::error('Failed to check for a newer version'."\n");
        }
    });

$cli->run();

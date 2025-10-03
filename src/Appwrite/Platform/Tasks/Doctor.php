<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\ClamAV\Network;
use Appwrite\PubSub\Adapter;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Config\Config;
use Utopia\Domains\Domain;
use Utopia\DSN\DSN;
use Utopia\Logger\Logger;
use Utopia\Platform\Action;
use Utopia\Registry\Registry;
use Utopia\Storage\Device\Local;
use Utopia\Storage\Storage;
use Utopia\System\System;

class Doctor extends Action
{
    public static function getName(): string
    {
        return 'doctor';
    }

    public function __construct()
    {
        $this
            ->desc('Validate server health')
            ->inject('register')
            ->callback(fn (Registry $register) => $this->action($register));
    }

    public function action(Registry $register): void
    {
        Console::log("  __   ____  ____  _  _  ____  __  ____  ____     __  __  
 / _\ (  _ \(  _ \/ )( \(  _ \(  )(_  _)(  __)   (  )/  \ 
/    \ ) __/ ) __/\ /\ / )   / )(   )(   ) _)  _  )((  O )
\_/\_/(__)  (__)  (_/\_)(__\_)(__) (__) (____)(_)(__)\__/ ");

        Console::log("\n" . '👩‍⚕️ Running ' . APP_NAME . ' Doctor for version ' . System::getEnv('_APP_VERSION', 'UNKNOWN') . ' ...' . "\n");

        Console::log('[Settings]');

        $domain = new Domain(System::getEnv('_APP_DOMAIN'));

        if (!$domain->isKnown() || $domain->isTest()) {
            Console::log('🔴 Hostname has no public suffix (' . $domain->get() . ')');
        } else {
            Console::log('🟢 Hostname has a public suffix (' . $domain->get() . ')');
        }

        $domain = new Domain(System::getEnv('_APP_DOMAIN_TARGET'));

        if (!$domain->isKnown() || $domain->isTest()) {
            Console::log('🔴 CNAME target has no public suffix (' . $domain->get() . ')');
        } else {
            Console::log('🟢 CNAME target has a public suffix (' . $domain->get() . ')');
        }

        if (System::getEnv('_APP_OPENSSL_KEY_V1') === 'your-secret-key' || empty(System::getEnv('_APP_OPENSSL_KEY_V1'))) {
            Console::log('🔴 Not using a unique secret key for encryption');
        } else {
            Console::log('🟢 Using a unique secret key for encryption');
        }

        if (System::getEnv('_APP_ENV', 'development') !== 'production') {
            Console::log('🔴 App environment is set for development');
        } else {
            Console::log('🟢 App environment is set for production');
        }

        if ('enabled' !== System::getEnv('_APP_OPTIONS_ABUSE', 'disabled')) {
            Console::log('🔴 Abuse protection is disabled');
        } else {
            Console::log('🟢 Abuse protection is enabled');
        }

        $authWhitelistRoot = System::getEnv('_APP_CONSOLE_WHITELIST_ROOT', null);
        $authWhitelistEmails = System::getEnv('_APP_CONSOLE_WHITELIST_EMAILS', null);
        $authWhitelistIPs = System::getEnv('_APP_CONSOLE_WHITELIST_IPS', null);

        if (
            empty($authWhitelistRoot)
            && empty($authWhitelistEmails)
            && empty($authWhitelistIPs)
        ) {
            Console::log('🔴 Console access limits are disabled');
        } else {
            Console::log('🟢 Console access limits are enabled');
        }

        if ('enabled' !== System::getEnv('_APP_OPTIONS_FORCE_HTTPS', 'disabled')) {
            Console::log('🔴 HTTPS force option is disabled');
        } else {
            Console::log('🟢 HTTPS force option is enabled');
        }

        if ('enabled' !== System::getEnv('_APP_OPTIONS_FUNCTIONS_FORCE_HTTPS', 'disabled')) {
            Console::log('🔴 HTTPS force option is disabled for function domains');
        } else {
            Console::log('🟢 HTTPS force option is enabled for function domains');
        }

        $providerConfig = System::getEnv('_APP_LOGGING_CONFIG', '');

        try {
            $loggingProvider = new DSN($providerConfig ?? '');

            $providerName = $loggingProvider->getScheme();

            if (empty($providerName) || !Logger::hasProvider($providerName)) {
                Console::log('🔴 Logging adapter is disabled');
            } else {
                Console::log('🟢 Logging adapter is enabled (' . $providerName . ')');
            }
        } catch (\Throwable $th) {
            Console::log('🔴 Logging adapter is misconfigured');
        }

        \usleep(200 * 1000); // Sleep for 0.2 seconds

        try {
            Console::log("\n" . '[Connectivity]');
        } catch (\Throwable $th) {
            //throw $th;
        }

        $pools = $register->get('pools'); /** @var \Utopia\Pools\Group $pools */

        $configs = [
            'Console.DB' => Config::getParam('pools-console'),
            'Projects.DB' => Config::getParam('pools-database'),
        ];

        foreach ($configs as $key => $config) {
            foreach ($config as $database) {
                try {
                    $adapter = $pools->get($database)->pop()->getResource();

                    if ($adapter->ping()) {
                        Console::success('🟢 ' . str_pad("{$key}({$database})", 50, '.') . 'connected');
                    } else {
                        Console::error('🔴 ' . str_pad("{$key}({$database})", 47, '.') . 'disconnected');
                    }
                } catch (\Throwable $th) {
                    Console::error('🔴 ' . str_pad("{$key}.({$database})", 47, '.') . 'disconnected');
                }
            }
        }

        $pools = $register->get('pools'); /** @var \Utopia\Pools\Group $pools */
        $configs = [
            'Cache' => Config::getParam('pools-cache'),
            'Queue' => Config::getParam('pools-queue'),
            'PubSub' => Config::getParam('pools-pubsub'),
        ];

        foreach ($configs as $key => $config) {
            foreach ($config as $pool) {
                try {
                    /** @var Adapter $adapter */
                    $adapter = $pools->get($pool)->pop()->getResource();

                    if ($adapter->ping()) {
                        Console::success('🟢 ' . str_pad("{$key}({$pool})", 50, '.') . 'connected');
                    } else {
                        Console::error('🔴 ' . str_pad("{$key}({$pool})", 47, '.') . 'disconnected');
                    }
                } catch (\Throwable $th) {
                    Console::error('🔴 ' . str_pad("{$key}({$pool})", 47, '.') . 'disconnected');
                }
            }
        }

        if (System::getEnv('_APP_STORAGE_ANTIVIRUS') === 'enabled') { // Check if scans are enabled
            try {
                $antivirus = new Network(
                    System::getEnv('_APP_STORAGE_ANTIVIRUS_HOST', 'clamav'),
                    (int) System::getEnv('_APP_STORAGE_ANTIVIRUS_PORT', 3310)
                );

                if ((@$antivirus->ping())) {
                    Console::success('🟢 ' . str_pad("Antivirus", 50, '.') . 'connected');
                } else {
                    Console::error('🔴 ' . str_pad("Antivirus", 47, '.') . 'disconnected');
                }
            } catch (\Throwable $th) {
                Console::error('🔴 ' . str_pad("Antivirus", 47, '.') . 'disconnected');
            }
        }

        try {
            $mail = $register->get('smtp'); /* @var $mail \PHPMailer\PHPMailer\PHPMailer */

            $mail->addAddress('demo@example.com', 'Example.com');
            $mail->Subject = 'Test SMTP Connection';
            $mail->Body = 'Hello World';
            $mail->AltBody = 'Hello World';

            $mail->send();
            Console::success('🟢 ' . str_pad("SMTP", 50, '.') . 'connected');
        } catch (\Throwable $th) {
            Console::error('🔴 ' . str_pad("SMTP", 47, '.') . 'disconnected');
        }

        \usleep(200 * 1000); // Sleep for 0.2 seconds

        Console::log('');
        Console::log('[Volumes]');

        foreach (
            [
                'Uploads' => APP_STORAGE_UPLOADS,
                'Cache' => APP_STORAGE_CACHE,
                'Config' => APP_STORAGE_CONFIG,
                'Certs' => APP_STORAGE_CERTIFICATES
            ] as $key => $volume
        ) {
            $device = new Local($volume);

            if (\is_readable($device->getRoot())) {
                Console::success('🟢 ' . $key . ' Volume is readable');
            } else {
                Console::error('🔴 ' . $key . ' Volume is unreadable');
            }

            if (\is_writable($device->getRoot())) {
                Console::success('🟢 ' . $key . ' Volume is writeable');
            } else {
                Console::error('🔴 ' . $key . ' Volume is unwriteable');
            }
        }

        \usleep(200 * 1000); // Sleep for 0.2 seconds

        Console::log('');
        Console::log('[Disk Space]');

        foreach (
            [
                'Uploads' => APP_STORAGE_UPLOADS,
                'Cache' => APP_STORAGE_CACHE,
                'Config' => APP_STORAGE_CONFIG,
                'Certs' => APP_STORAGE_CERTIFICATES
            ] as $key => $volume
        ) {
            $device = new Local($volume);

            $percentage = (($device->getPartitionTotalSpace() - $device->getPartitionFreeSpace())
            / $device->getPartitionTotalSpace()) * 100;

            $message = $key . ' Volume has ' . Storage::human($device->getPartitionFreeSpace()) . ' free space (' . \round($percentage, 2) . '% used)';

            if ($percentage < 80) {
                Console::success('🟢 ' . $message);
            } else {
                Console::error('🔴 ' . $message);
            }
        }

        try {
            if (App::isProduction()) {
                Console::log('');
                $version = \json_decode(@\file_get_contents(System::getEnv('_APP_HOME', 'http://localhost') . '/version'), true);

                if ($version && isset($version['version'])) {
                    if (\version_compare($version['version'], System::getEnv('_APP_VERSION', 'UNKNOWN')) === 0) {
                        Console::info('You are running the latest version of ' . APP_NAME . '! 🥳');
                    } else {
                        Console::info('A new version (' . $version['version'] . ') is available! 🥳' . "\n");
                    }
                } else {
                    Console::error('Failed to check for a newer version' . "\n");
                }
            }
        } catch (\Throwable $th) {
            Console::error('Failed to check for a newer version' . "\n");
        }
    }
}

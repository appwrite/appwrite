<?php

namespace Appwrite\SMTP;

use InvalidArgumentException;
use Utopia\Messaging\Adapter\Email as EmailAdapter;
use Utopia\Messaging\Adapter\Email\SMTP as SMTPAdapter;
use Utopia\Messaging\Messages\Email as EmailMessage;
use Utopia\System\System;

class SMTP
{
    /**
     * @return array<int, array<string, int|string>>
     */
    public static function getInternalConfigs(): array
    {
        $primary = self::getEnvConfig('');

        if (empty($primary['host'])) {
            return [];
        }

        $configs = [$primary];
        $secondary = self::getEnvConfig('_SECONDARY');

        if (!empty($secondary['host'])) {
            $configs[] = $secondary;
        }

        return $configs;
    }

    public static function isEnabled(): bool
    {
        return !empty(self::getEnvConfig('')['host']);
    }

    public static function createService(): Client
    {
        $adapters = array_map(self::createAdapter(...), self::getInternalConfigs());

        if (empty($adapters)) {
            throw new InvalidArgumentException('At least one SMTP adapter must be configured.');
        }

        if (count($adapters) === 1) {
            return new Client(fn (EmailMessage $message): array => $adapters[0]->send($message));
        }

        $messenger = 'Utopia\\Messaging\\Messenger';
        $sender = new $messenger($adapters);

        return new Client(fn (EmailMessage $message): array => $sender->send($message));
    }

    /**
     * @param array<string, int|string> $config
     */
    public static function createAdapter(array $config): EmailAdapter
    {
        return self::buildAdapter(
            host: $config['host'],
            port: (int) $config['port'],
            username: $config['username'],
            password: $config['password'],
            smtpSecure: $config['secure'],
            smtpAutoTLS: false,
            xMailer: 'Appwrite Mailer',
            timeout: 10,
            keepAlive: true,
            timelimit: 30,
        );
    }

    private static function buildAdapter(
        string $host,
        int $port = 25,
        string $username = '',
        string $password = '',
        string $smtpSecure = '',
        bool $smtpAutoTLS = false,
        string $xMailer = '',
        int $timeout = 30,
        bool $keepAlive = false,
        int $timelimit = 30,
    ): EmailAdapter {
        return new SMTPAdapter(
            $host,
            $port,
            $username,
            $password,
            $smtpSecure,
            $smtpAutoTLS,
            $xMailer,
            $timeout,
            $keepAlive,
            $timelimit,
        );
    }

    /**
     * @return array<string, int|string>
     */
    private static function getEnvConfig(string $suffix): array
    {
        return [
            'host' => System::getEnv('_APP_SMTP_HOST' . $suffix, ''),
            'port' => self::getEnvInt('_APP_SMTP_PORT' . $suffix, 25),
            'username' => System::getEnv('_APP_SMTP_USERNAME' . $suffix, ''),
            'password' => System::getEnv('_APP_SMTP_PASSWORD' . $suffix, ''),
            'secure' => System::getEnv('_APP_SMTP_SECURE' . $suffix, ''),
        ];
    }

    private static function getEnvInt(string $key, int $default): int
    {
        $value = System::getEnv($key, '');

        if ($value === '') {
            return $default;
        }

        return (int) $value;
    }
}

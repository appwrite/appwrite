<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\Passkey;

use Appwrite\Auth\Passkey\Console;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConsoleTest extends TestCase
{
    /**
     * @return \Iterator<string, array{string, string, bool, string, array<string>}>
     */
    public static function consoles(): \Iterator
    {
        yield 'local console' => ['http://localhost', '', true, 'localhost', ['http://localhost']];
        yield 'local console on a port' => ['http://localhost:3000', '', true, 'localhost', ['http://localhost:3000']];
        yield 'hosted console' => ['https://cloud.appwrite.io', '', true, 'cloud.appwrite.io', ['https://cloud.appwrite.io']];
        yield 'extra origins under the host' => [
            'https://cloud.appwrite.io',
            ' https://preview.cloud.appwrite.io , https://cloud.appwrite.io/',
            true,
            'cloud.appwrite.io',
            ['https://cloud.appwrite.io', 'https://preview.cloud.appwrite.io'],
        ];
        yield 'foreign and insecure extra origins' => [
            'https://cloud.appwrite.io',
            'https://evil.com,http://preview.cloud.appwrite.io,not a url',
            true,
            'cloud.appwrite.io',
            ['https://cloud.appwrite.io'],
        ];
        yield 'public suffix host' => ['https://co.uk', '', false, '', []];
        yield 'ip address' => ['http://127.0.0.1', '', false, '', []];
        yield 'plain http on a domain' => ['http://console.example.com', '', false, '', []];
        yield 'no url' => ['', 'https://cloud.appwrite.io', false, '', []];
    }

    /**
     * @param  array<string>  $origins
     */
    #[DataProvider('consoles')]
    public function test_get_auths(string $url, string $extra, bool $enabled, string $rpId, array $origins): void
    {
        $this->assertSame([
            'passkey' => $enabled,
            'passkeyRpId' => $rpId,
            'passkeyOrigins' => $origins,
        ], Console::getAuths($url, $extra));
    }
}

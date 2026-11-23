<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Locale;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;
use Utopia\System\System;

final class LocaleCustomClientTest extends Scope
{
    use LocaleBase;
    use ProjectCustom;
    use SideClient;

    /**
     * The client address only comes from headers listed in _APP_TRUSTED_HEADERS,
     * and only when they carry a valid IP.
     */
    public function testGetClientIp(): void
    {
        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ];
        $trusted = \array_map(
            fn (string $header) => \strtolower(\trim($header)),
            \explode(',', System::getEnv('_APP_TRUSTED_HEADERS', 'x-forwarded-for'))
        );

        $direct = $this->client->call(Client::METHOD_GET, '/locale', $headers);
        $this->assertEquals(200, $direct['headers']['status-code']);
        $this->assertNotFalse(\filter_var($direct['body']['ip'], FILTER_VALIDATE_IP));

        /**
         * Test for SUCCESS
         */
        if (\in_array('x-forwarded-for', $trusted, true)) {
            $response = $this->client->call(Client::METHOD_GET, '/locale', array_merge($headers, [
                'x-forwarded-for' => '191.0.113.195, 10.0.0.1',
            ]));
            $this->assertEquals(200, $response['headers']['status-code']);
            $this->assertEquals('191.0.113.195', $response['body']['ip']);
        }

        /**
         * Test for FAILURE
         */
        $untrusted = [
            'x-real-ip',
            'x-client-ip',
            'x-cluster-client-ip',
            'cf-connecting-ip',
            'true-client-ip',
            'fastly-client-ip',
            'forwarded',
        ];
        foreach ($untrusted as $header) {
            if (\in_array($header, $trusted, true)) {
                continue;
            }

            $value = $header === 'forwarded' ? 'for=191.0.113.196' : '191.0.113.196';
            $response = $this->client->call(Client::METHOD_GET, '/locale', array_merge($headers, [
                $header => $value,
            ]));
            $this->assertEquals(200, $response['headers']['status-code']);
            $this->assertNotEquals('191.0.113.196', $response['body']['ip'], "Header '{$header}' should not set the client IP");
        }

        $invalid = [
            'not-an-ip',
            '191.0.113.197<script>',
            '191.0.113.197 OR 1=1',
            '999.0.113.197',
            '',
        ];
        foreach ($invalid as $value) {
            $response = $this->client->call(Client::METHOD_GET, '/locale', array_merge($headers, [
                'x-forwarded-for' => $value,
            ]));
            $this->assertEquals(200, $response['headers']['status-code']);
            $this->assertNotFalse(\filter_var($response['body']['ip'], FILTER_VALIDATE_IP), "Forwarded value '{$value}' should fall back to a valid address");
            $this->assertEquals($direct['body']['ip'], $response['body']['ip']);
        }
    }
}

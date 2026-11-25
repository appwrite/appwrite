<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Locale;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;

final class LocaleCustomServerTest extends Scope
{
    use LocaleBase;
    use ProjectCustom;
    use SideServer;

    /**
     * A server that calls on behalf of an end user names the user's address in X-Forwarded-IP,
     * and only a valid IP is taken.
     */
    public function testGetForwardedIp(): void
    {
        $headers = array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders());

        $direct = $this->client->call(Client::METHOD_GET, '/locale', $headers);
        $this->assertEquals(200, $direct['headers']['status-code']);
        $this->assertNotFalse(\filter_var($direct['body']['ip'], FILTER_VALIDATE_IP));

        /**
         * Test for SUCCESS
         */
        foreach (['191.0.113.195', '2001:db8::195'] as $value) {
            $response = $this->client->call(Client::METHOD_GET, '/locale', array_merge($headers, [
                'x-forwarded-ip' => $value,
            ]));
            $this->assertEquals(200, $response['headers']['status-code']);
            $this->assertEquals($value, $response['body']['ip']);
        }

        /**
         * Test for FAILURE
         */
        $invalid = [
            'not-an-ip',
            '191.0.113.197, 10.0.0.1',
            '191.0.113.197<script>',
            '999.0.113.197',
            '',
        ];
        foreach ($invalid as $value) {
            $response = $this->client->call(Client::METHOD_GET, '/locale', array_merge($headers, [
                'x-forwarded-ip' => $value,
            ]));
            $this->assertEquals(200, $response['headers']['status-code']);
            $this->assertEquals($direct['body']['ip'], $response['body']['ip'], "Forwarded value '{$value}' should fall back to the request address");
        }
    }
}

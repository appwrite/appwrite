<?php

declare(strict_types=1);

namespace Tests\E2E\Services\Distribution;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;

final class SigningCustomServerTest extends Scope
{
    use DistributionBase;
    use MockStores;
    use SigningBase;
    use ProjectCustom;
    use SideServer;

    public function testSigningIdentityOfAnotherProjectIsNotFound(): void
    {
        $created = $this->createAndroidSigningIdentity();
        $this->assertSame(201, $created['headers']['status-code']);
        $path = '/distribution/signing-identities/' . $created['body']['$id'];

        $other = $this->getProject(true);
        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $other['$id'],
            'x-appwrite-key' => $other['apiKey'],
        ];

        /**
         * Test for FAILURE
         */
        $fetched = $this->client->call(Client::METHOD_GET, $path, $headers);
        $this->assertSame(404, $fetched['headers']['status-code']);
        $this->assertSame('distribution_signing_identity_not_found', $fetched['body']['type']);

        $updated = $this->client->call(Client::METHOD_PATCH, $path, $headers, ['name' => 'Taken']);
        $this->assertSame(404, $updated['headers']['status-code']);

        $downloaded = $this->client->call(Client::METHOD_GET, $path . '/download', $headers);
        $this->assertSame(404, $downloaded['headers']['status-code']);

        $deleted = $this->client->call(Client::METHOD_DELETE, $path, $headers);
        $this->assertSame(404, $deleted['headers']['status-code']);

        $listed = $this->client->call(Client::METHOD_GET, '/distribution/signing-identities', $headers);
        $this->assertSame(200, $listed['headers']['status-code']);
        $this->assertNotContains($created['body']['$id'], \array_column($listed['body']['signingIdentities'], '$id'));

        $original = $this->client->call(Client::METHOD_GET, $path, $this->headers());
        $this->assertSame(200, $original['headers']['status-code']);
    }
}

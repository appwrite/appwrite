<?php

namespace Tests\E2E\Services\Distribution;

use Appwrite\ID;
use Tests\E2E\Client;
use Utopia\Distribution\Signing\Certificate;
use Utopia\Distribution\Signing\Keystore;

trait ChecksBase
{
    public function testCreateCheckConnectionMissing(): void
    {
        $app = $this->createApp(['framework' => 'android']);
        $this->assertSame(201, $app['headers']['status-code']);
        $appId = $app['body']['$id'];

        $signingIdentity = $this->createCheckSigningIdentity();
        $this->assertSame(201, $signingIdentity['headers']['status-code']);

        $target = $this->createTarget($appId, [
            'platform' => 'android',
            'signingIdentityId' => $signingIdentity['body']['$id'],
            'track' => 'internal',
        ]);
        $this->assertSame(201, $target['headers']['status-code']);

        $check = $this->createCheck($appId, [
            'targetId' => $target['body']['$id'],
            'track' => 'internal',
        ]);

        /**
         * Test for SUCCESS
         */
        $this->assertSame(200, $check['headers']['status-code']);
        $this->assertFalse($check['body']['ready']);
        $this->assertSame(['connection_missing'], \array_column($check['body']['issues'], 'code'));

        $issue = $check['body']['issues'][0];
        $this->assertSame('error', $issue['severity']);
        $this->assertSame('android', $issue['platform']);
        $this->assertSame('connectionId', $issue['field']);
        $this->assertNotEmpty($issue['message']);
        $this->assertNotEmpty($issue['remediation']);
        $this->assertFalse($issue['manualActionRequired']);
    }

    public function testCreateCheckRefusals(): void
    {
        $app = $this->createApp(['framework' => 'android']);
        $this->assertSame(201, $app['headers']['status-code']);
        $appId = $app['body']['$id'];

        $target = $this->createTarget($appId, ['platform' => 'android']);
        $this->assertSame(201, $target['headers']['status-code']);
        $targetId = $target['body']['$id'];

        /**
         * Test for FAILURE
         */
        $track = $this->createCheck($appId, ['targetId' => $targetId, 'track' => 'testflight']);
        $this->assertSame(400, $track['headers']['status-code']);
        $this->assertSame('distribution_track_invalid', $track['body']['type']);

        $incomplete = $this->createCheck($appId, ['track' => 'internal']);
        $this->assertSame(400, $incomplete['headers']['status-code']);
        $this->assertSame('general_argument_invalid', $incomplete['body']['type']);

        $missingTarget = $this->createCheck($appId, ['targetId' => ID::unique(), 'track' => 'internal']);
        $this->assertSame(404, $missingTarget['headers']['status-code']);
        $this->assertSame('distribution_target_not_found', $missingTarget['body']['type']);

        $missingApp = $this->createCheck(ID::unique(), ['targetId' => $targetId, 'track' => 'internal']);
        $this->assertSame(404, $missingApp['headers']['status-code']);
        $this->assertSame('distribution_app_not_found', $missingApp['body']['type']);

        $elsewhere = $this->createApp();
        $this->assertSame(201, $elsewhere['headers']['status-code']);
        $foreignTarget = $this->createCheck($elsewhere['body']['$id'], ['targetId' => $targetId, 'track' => 'internal']);
        $this->assertSame(404, $foreignTarget['headers']['status-code']);
        $this->assertSame('distribution_target_not_found', $foreignTarget['body']['type']);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    protected function createCheck(string $appId, array $params): array
    {
        return $this->client->call(Client::METHOD_POST, '/distribution/apps/' . $appId . '/checks', $this->headers(), $params);
    }

    /**
     * @return array<string, mixed>
     */
    protected function createCheckSigningIdentity(?int $days = null): array
    {
        if ($days === null) {
            $keystore = Keystore::generate('upload', ['CN' => 'Appwrite Test']);
            $archive = $keystore->archive;
            $password = $keystore->password;
        } else {
            $key = Certificate::key();
            $signed = \openssl_csr_sign(Certificate::request($key, ['CN' => 'Appwrite Test']), null, $key, $days, ['digest_alg' => 'sha256', 'config' => Certificate::CONFIG], \random_int(1, PHP_INT_MAX));
            $this->assertNotFalse($signed);

            $pem = null;
            $this->assertTrue(\openssl_x509_export($signed, $pem));
            $this->assertIsString($pem);

            $password = \bin2hex(\random_bytes(12));
            $archive = (new Certificate($pem))->export($key, $password, 'upload');
        }

        return $this->client->call(Client::METHOD_POST, '/distribution/signing-identities/android', $this->headers(), [
            'signingIdentityId' => ID::unique(),
            'name' => 'Keystore ' . \uniqid(),
            'keystore' => \base64_encode($archive),
            'storePassword' => $password,
            'keyAlias' => 'upload',
            'keyPassword' => $password,
        ]);
    }
}

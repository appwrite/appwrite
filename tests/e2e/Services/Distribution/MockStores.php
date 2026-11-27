<?php

namespace Tests\E2E\Services\Distribution;

use Appwrite\ID;
use Tests\E2E\Client;

trait MockStores
{
    abstract protected function headers(): array;

    /**
     * @return array{type: string, project_id: string, private_key: string, client_email: string, token_uri: string}
     */
    protected function seedGoogleServiceAccount(): array
    {
        $key = \openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
        ]);
        $this->assertNotFalse($key);
        $this->assertTrue(\openssl_pkey_export($key, $privateKey));

        $email = 'sa' . \uniqid() . '@appwrite-test.iam.gserviceaccount.com';

        $seeded = $this->client->call(Client::METHOD_POST, '/mock/distribution/google/seeds/service-accounts', $this->mockHeaders(), [
            'email' => $email,
            'publicKey' => \openssl_pkey_get_details($key)['key'],
        ]);
        $this->assertSame(201, $seeded['headers']['status-code'], 'Seeding a Google service account requires _APP_ENV=development.');

        return [
            'type' => 'service_account',
            'project_id' => 'appwrite-test',
            'private_key' => $privateKey,
            'client_email' => $email,
            'token_uri' => 'https://attacker.invalid/token',
        ];
    }

    /**
     * @return array{keyId: string, issuerId: string, privateKey: string}
     */
    protected function seedAppleKey(bool $agreements = true): array
    {
        $key = \openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        $this->assertNotFalse($key);
        $this->assertTrue(\openssl_pkey_export($key, $privateKey));
        $this->assertStringStartsWith('-----BEGIN PRIVATE KEY-----', $privateKey);

        $keyId = \strtoupper(\substr(\bin2hex(\random_bytes(5)), 0, 10));
        $issuerId = \vsprintf('%s%s-%s-%s-%s-%s%s%s', \str_split(\bin2hex(\random_bytes(16)), 4));

        $seeded = $this->client->call(Client::METHOD_POST, '/mock/distribution/apple/seeds/keys', $this->mockHeaders(), [
            'keyId' => $keyId,
            'issuerId' => $issuerId,
            'publicKey' => \openssl_pkey_get_details($key)['key'],
            'agreements' => $agreements,
        ]);
        $this->assertSame(201, $seeded['headers']['status-code'], 'Seeding an App Store Connect key requires _APP_ENV=development.');

        return [
            'keyId' => $keyId,
            'issuerId' => $issuerId,
            'privateKey' => $privateKey,
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    protected function createGoogleConnection(array $params = []): array
    {
        return $this->client->call(Client::METHOD_POST, '/distribution/connections/google', $this->headers(), \array_merge([
            'connectionId' => ID::unique(),
            'name' => 'Play ' . \uniqid(),
            'serviceAccount' => $params['serviceAccount'] ?? $this->seedGoogleServiceAccount(),
        ], $params));
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    protected function createAppleConnection(array $params = []): array
    {
        $key = $this->seedAppleKey();

        return $this->client->call(Client::METHOD_POST, '/distribution/connections/apple', $this->headers(), \array_merge([
            'connectionId' => ID::unique(),
            'name' => 'App Store ' . \uniqid(),
            'keyId' => $key['keyId'],
            'issuerId' => $key['issuerId'],
            'privateKey' => $key['privateKey'],
        ], $params));
    }

    /**
     * @return array<string, string>
     */
    private function mockHeaders(): array
    {
        return [
            'content-type' => 'application/json',
            'x-appwrite-project' => 'console',
        ];
    }
}

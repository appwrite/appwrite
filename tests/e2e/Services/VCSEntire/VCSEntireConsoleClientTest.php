<?php

declare(strict_types=1);

namespace Tests\E2E\Services\VCSEntire;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideConsole;
use Utopia\System\System;

/**
 * Entire has no self-hostable instance and registers plugins out of band, so
 * these cover the surface the server answers on its own: the install entry
 * point without a configured plugin, and the public callback and delivery
 * endpoints refusing what they cannot authenticate.
 */
final class VCSEntireConsoleClientTest extends Scope
{
    use ProjectCustom;
    use SideConsole;

    public function testCreateInstallationWhenNotConfigured(): void
    {
        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_GET, '/vcs/entire/authorize', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(500, $response['headers']['status-code']);
    }

    public function testCreateInstallationWithoutReceipt(): void
    {
        /**
         * Test for FAILURE
         */
        $response = $this->client->call(Client::METHOD_GET, '/vcs/entire/callback', [
            'content-type' => 'application/json',
        ], [
            'installation_id' => 'ins_01ARZ3NDEKTSV4RRFFQ69G5FAV',
            'state' => '{"projectId":"' . $this->getProject()['$id'] . '"}',
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);
    }

    public function testCreateInstallationWithForgedReceipt(): void
    {
        /**
         * Test for FAILURE
         */
        $encode = fn (array $data): string => \rtrim(\strtr(\base64_encode((string) \json_encode($data)), '+/', '-_'), '=');
        $receipt = $encode(['alg' => 'EdDSA', 'kid' => 'k1', 'typ' => 'JWT'])
            . '.' . $encode(['iss' => 'https://us.console.entire.io', 'aud' => 'appwrite', 'sub' => 'ins_01ARZ3NDEKTSV4RRFFQ69G5FAV', 'exp' => \time() + 300])
            . '.' . $encode(['not' => 'a signature']);

        $response = $this->client->call(Client::METHOD_GET, '/vcs/entire/callback', [
            'content-type' => 'application/json',
        ], [
            'receipt' => $receipt,
            'state' => '{"projectId":"' . $this->getProject()['$id'] . '"}',
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);
    }

    public function testCreateInstallationCancelledWithForgedState(): void
    {
        /**
         * Test for FAILURE
         */
        // A cancel comes back without a receipt, so only the state's signature
        // stands between the public callback and an open redirect
        $state = \rtrim(\strtr(\base64_encode((string) \json_encode([
            'projectId' => $this->getProject()['$id'],
            'success' => 'https://attacker.test/success',
            'failure' => 'https://attacker.test/failure',
            'signature' => \str_repeat('0', 64),
        ])), '+/', '-_'), '=');

        $response = $this->client->call(Client::METHOD_GET, '/vcs/entire/callback', [
            'content-type' => 'application/json',
        ], [
            'error' => 'access_denied',
            'state' => $state,
        ]);

        $this->assertEquals(400, $response['headers']['status-code']);
        $this->assertArrayNotHasKey('location', $response['headers']);
    }

    public function testCreateInstallationCancelledWithUnboundState(): void
    {
        /**
         * Test for FAILURE
         */
        // A state signed over the project and the two redirect URLs alone is
        // bound to no console user, carries no expiry and no nonce, so whoever
        // holds it could complete someone else's installation
        $projectId = $this->getProject()['$id'];
        $failure = 'http://localhost/console/project-' . $projectId . '/failure';
        $unbound = $this->encodeStateHelper([
            'projectId' => $projectId,
            'success' => $failure,
            'failure' => $failure,
            'signature' => \hash_hmac('sha256', (string) \json_encode([$projectId, $failure, $failure]), System::getEnv('_APP_OPENSSL_KEY_V1', '')),
        ]);

        $response = $this->client->call(Client::METHOD_GET, '/vcs/entire/callback', \array_merge([
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'error' => 'access_denied',
            'state' => $unbound,
        ], true, false);

        $this->assertEquals(400, $response['headers']['status-code']);
        $this->assertArrayNotHasKey('location', $response['headers']);
    }

    public function testCreateInstallationCancelledWithBoundState(): void
    {
        /**
         * Test for SUCCESS
         */
        // A cancel on Entire carries no receipt, so the state's signature is
        // all that may be trusted to pick the redirect back to the console
        $projectId = $this->getProject()['$id'];
        $failure = 'http://localhost/console/project-' . $projectId . '/failure';

        $response = $this->client->call(Client::METHOD_GET, '/vcs/entire/callback', \array_merge([
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'error' => 'access_denied',
            'state' => $this->encodeStateHelper($this->boundStateHelper($projectId, $failure)),
        ], true, false);

        $this->assertEquals(301, $response['headers']['status-code']);
        $this->assertStringStartsWith($failure . '?error=', (string) $response['headers']['location']);
        $this->assertStringContainsString('not approved on Entire', \urldecode((string) $response['headers']['location']));
    }

    /**
     * A state the Authorize action would have signed: bound to the console
     * user, with an expiry and a nonce.
     *
     * @return array<string, string|int>
     */
    private function boundStateHelper(string $projectId, string $redirect): array
    {
        $userId = $this->getRoot()['$id'];
        $expire = \time() + 600;
        $nonce = \bin2hex(\random_bytes(16));

        return [
            'projectId' => $projectId,
            'userId' => $userId,
            'success' => $redirect,
            'failure' => $redirect,
            'expire' => $expire,
            'nonce' => $nonce,
            'signature' => \hash_hmac('sha256', (string) \json_encode([$projectId, $userId, $redirect, $redirect, $expire, $nonce]), System::getEnv('_APP_OPENSSL_KEY_V1', '')),
        ];
    }

    /**
     * Entire expects an opaque state token, so the signed state travels base64url-encoded.
     *
     * @param array<string, string|int> $state
     */
    private function encodeStateHelper(array $state): string
    {
        return \rtrim(\strtr(\base64_encode((string) \json_encode($state, JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');
    }

    public function testCreateEventWithoutToken(): void
    {
        /**
         * Test for FAILURE
         */
        $event = $this->client->call(Client::METHOD_POST, '/vcs/entire/events', [
            'content-type' => 'application/json',
        ], $this->refPayload());

        $this->assertEquals(401, $event['headers']['status-code']);
        $this->assertEquals('general_access_forbidden', $event['body']['type']);
    }

    public function testCreateEventWithUntrustedIssuer(): void
    {
        /**
         * Test for FAILURE
         */
        $payload = $this->refPayload();
        $body = (string) \json_encode($payload, JSON_THROW_ON_ERROR);

        // A well-formed token from a host outside Entire must be refused
        // without its keys ever being fetched
        $keyPair = \sodium_crypto_sign_keypair();
        $encode = fn (string $data): string => \rtrim(\strtr(\base64_encode($data), '+/', '-_'), '=');
        $signingInput = $encode((string) \json_encode(['alg' => 'EdDSA', 'kid' => 'k1', 'typ' => 'JWT']))
            . '.' . $encode((string) \json_encode([
                'iss' => 'https://entire.io.attacker.test',
                'aud' => 'appwrite',
                'iat' => \time(),
                'exp' => \time() + 300,
                'body_sha256' => $encode(\hash('sha256', $body, true)),
            ]));
        $token = $signingInput . '.' . $encode(\sodium_crypto_sign_detached($signingInput, \sodium_crypto_sign_secretkey($keyPair)));

        $event = $this->client->call(Client::METHOD_POST, '/vcs/entire/events', [
            'content-type' => 'application/json',
            'authorization' => 'Bearer ' . $token,
        ], $payload);

        $this->assertEquals(401, $event['headers']['status-code']);
        $this->assertEquals('general_access_forbidden', $event['body']['type']);
    }

    /**
     * @return array<string, mixed>
     */
    private function refPayload(): array
    {
        return [
            'delivery_id' => 'dlv_01ARZ3NDEKTSV4RRFFQ69G5FAV',
            'installation_id' => 'ins_01ARZ3NDEKTSV4RRFFQ69G5FAV',
            'plugin_id' => 'appwrite',
            'api_url' => 'https://aws-us-east-2.api.entire.io',
            'core_url' => 'https://us.console.entire.io',
            'event' => [
                'id' => 'evt_01ARZ3NDEKTSV4RRFFQ69G5FAV',
                'type' => 'ref.updated',
                'time' => '2026-10-15T10:00:00Z',
                'payload' => [
                    'ref' => 'refs/heads/main',
                    'before' => \str_repeat('a', 40),
                    'after' => \str_repeat('b', 40),
                    'forced' => false,
                    'pusher' => ['account' => ['id' => 'acc_1', 'handle' => 'github:alice']],
                    'repository' => [
                        'id' => 'repo_01ARZ3NDEKTSV4RRFFQ69G5FAV',
                        'path' => 'et/acme/api',
                        'default_branch' => 'main',
                        'clone_url' => 'https://aws-us-east-2.entire.io/et/acme/api',
                        'entire_clone_url' => 'entire://aws-us-east-2.entire.io/et/acme/api',
                    ],
                ],
            ],
        ];
    }
}

<?php

namespace Tests\E2E\Services\Project;

use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\E2E\Client;
use Utopia\Database\Query;

trait OAuth2Base
{
    /**
     * Reset providers we mutate in tests back to a known empty/disabled state.
     * The ProjectCustom trait reuses the same project across tests in a class,
     * and the OAuth2 PATCH endpoint is additive (omitted fields are preserved),
     * so without a reset state would leak between tests.
     *
     * Assert on the reset response so a silently broken reset (e.g. validation
     * change) surfaces immediately rather than corrupting downstream tests.
     */
    #[Before(priority: -1)]
    protected function resetProjectOAuth2(): void
    {
        $response = $this->updateOAuth2('amazon', [
            'clientId' => '',
            'clientSecret' => '',
            'enabled' => false,
        ]);

        $this->assertSame(
            200,
            $response['headers']['status-code'],
            'OAuth2 reset failed — downstream tests will be unreliable. Body: ' . \json_encode($response['body'] ?? null),
        );
    }

    // =========================================================================
    // List OAuth2 providers
    // =========================================================================

    public function testListOAuth2Providers(): void
    {
        $response = $this->listOAuth2Providers();

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertArrayHasKey('total', $response['body']);
        $this->assertArrayHasKey('providers', $response['body']);
        $this->assertGreaterThan(0, $response['body']['total']);
        $this->assertSame($response['body']['total'], \count($response['body']['providers']));
    }

    public function testListOAuth2ProvidersIncludesKnownProviders(): void
    {
        $response = $this->listOAuth2Providers();

        $this->assertSame(200, $response['headers']['status-code']);

        $ids = \array_column($response['body']['providers'], '$id');

        // Spot-check a representative cross-section of providers across all
        // provider shapes (plain, multi-field, sandboxed, custom param names).
        $expected = [
            'github',
            'amazon',
            'apple',
            'auth0',
            'authentik',
            'fusionauth',
            'gitlab',
            'keycloak',
            'oidc',
            'okta',
            'microsoft',
            'dropbox',
            'paypalSandbox',
            'kick',
        ];

        foreach ($expected as $providerId) {
            $this->assertContains($providerId, $ids, "Missing provider {$providerId} in listOAuth2Providers response");
        }
    }

    /**
     * Pin the exact set of registered providers — adding or removing a
     * provider must be a deliberate change to this assertion. Catches
     * registration drift (e.g. forgetting to wire a new provider into
     * `Base::getProviderActions()`).
     */
    public function testListOAuth2ProvidersExposesEntireRegistry(): void
    {
        $response = $this->listOAuth2Providers();
        $this->assertSame(200, $response['headers']['status-code']);

        $ids = \array_column($response['body']['providers'], '$id');
        \sort($ids);

        $expected = [
            'amazon', 'apple', 'auth0', 'authentik', 'autodesk', 'bitbucket',
            'bitly', 'box', 'dailymotion', 'discord', 'disqus', 'dropbox',
            'etsy', 'facebook', 'figma', 'fusionauth', 'github', 'gitlab',
            'google', 'keycloak', 'kick', 'linkedin', 'microsoft', 'notion',
            'oidc', 'okta', 'paypal', 'paypalSandbox', 'podio', 'salesforce',
            'slack', 'spotify', 'stripe', 'tradeshift', 'tradeshiftBox',
            'twitch', 'wordpress', 'x', 'yahoo', 'yandex', 'zoho', 'zoom',
        ];
        \sort($expected);

        $this->assertSame($expected, $ids, 'Registry drift — listed providers do not match the expected set.');
    }

    public function testListOAuth2ProvidersResponseShape(): void
    {
        $response = $this->listOAuth2Providers();

        $this->assertSame(200, $response['headers']['status-code']);

        foreach ($response['body']['providers'] as $provider) {
            $this->assertArrayHasKey('$id', $provider);
            $this->assertArrayHasKey('enabled', $provider);
            $this->assertIsString($provider['$id']);
            $this->assertIsBool($provider['enabled']);
        }
    }

    public function testListOAuth2ProvidersClientSecretsNotExposed(): void
    {
        // Seed credentials so the list cannot trivially return empty values.
        $this->updateOAuth2('amazon', [
            'clientId' => 'amzn1.application-oa2-client.testListSeed',
            'clientSecret' => 'super-secret-must-not-leak',
            'enabled' => false,
        ]);

        $response = $this->listOAuth2Providers();

        $this->assertSame(200, $response['headers']['status-code']);

        $matched = false;
        foreach ($response['body']['providers'] as $provider) {
            if ($provider['$id'] !== 'amazon') {
                continue;
            }

            $matched = true;
            $this->assertSame('amzn1.application-oa2-client.testListSeed', $provider['clientId']);
            $this->assertSame('', $provider['clientSecret']);
        }

        $this->assertTrue($matched, 'List did not include the seeded provider.');
    }

    public function testListOAuth2ProvidersWithoutAuthentication(): void
    {
        $response = $this->listOAuth2Providers(authenticated: false);

        $this->assertSame(401, $response['headers']['status-code']);
    }

    public function testListOAuth2ProvidersExcludesUnregisteredConfigEntries(): void
    {
        // `mock` and `mock-unverified` exist in oAuthProviders config (enabled: true)
        // but are intentionally absent from Base::getProviderActions() — they're
        // internal Mock OAuth2 adapters used by other test suites, not public
        // providers. XList iterates the action registry, so they must never be
        // included even though config marks them enabled.
        $response = $this->listOAuth2Providers();

        $this->assertSame(200, $response['headers']['status-code']);

        $ids = \array_column($response['body']['providers'], '$id');
        $this->assertNotContains('mock', $ids);
        $this->assertNotContains('mock-unverified', $ids);
    }

    public function testListOAuth2ProvidersTotalFalse(): void
    {
        $response = $this->listOAuth2Providers(total: false);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame(0, $response['body']['total']);
        $this->assertGreaterThan(0, \count($response['body']['providers']));
    }

    public function testListOAuth2ProvidersWithLimit(): void
    {
        $response = $this->listOAuth2Providers([
            Query::limit(1)->toString(),
        ]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertCount(1, $response['body']['providers']);
        $this->assertGreaterThan(1, $response['body']['total']);
    }

    public function testListOAuth2ProvidersWithOffset(): void
    {
        $listAll = $this->listOAuth2Providers();
        $this->assertSame(200, $listAll['headers']['status-code']);

        $listOffset = $this->listOAuth2Providers([
            Query::offset(1)->toString(),
        ]);

        $this->assertSame(200, $listOffset['headers']['status-code']);
        $this->assertCount(\count($listAll['body']['providers']) - 1, $listOffset['body']['providers']);
        $this->assertSame($listAll['body']['total'], $listOffset['body']['total']);
    }

    // =========================================================================
    // Get OAuth2 provider
    // =========================================================================

    public function testGetOAuth2Provider(): void
    {
        $response = $this->getOAuth2Provider('github');

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('github', $response['body']['$id']);
        $this->assertArrayHasKey('enabled', $response['body']);
        $this->assertArrayHasKey('clientId', $response['body']);
        $this->assertArrayHasKey('clientSecret', $response['body']);
        $this->assertSame('', $response['body']['clientSecret']);
    }

    public function testGetOAuth2ProviderClientSecretWriteOnly(): void
    {
        $this->updateOAuth2('amazon', [
            'clientId' => 'amzn1.application-oa2-client.getSecretCheck',
            'clientSecret' => 'must-never-be-returned',
            'enabled' => false,
        ]);

        $response = $this->getOAuth2Provider('amazon');

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('amzn1.application-oa2-client.getSecretCheck', $response['body']['clientId']);
        $this->assertSame('', $response['body']['clientSecret']);
    }

    public function testGetOAuth2ProviderMatchesListEntry(): void
    {
        $list = $this->listOAuth2Providers();
        $this->assertSame(200, $list['headers']['status-code']);

        // Drive the loop directly off the LIST result so any provider added
        // to the registry is automatically checked for List/Get parity.
        foreach ($list['body']['providers'] as $listEntry) {
            $providerId = $listEntry['$id'];
            $get = $this->getOAuth2Provider($providerId);

            $this->assertSame(200, $get['headers']['status-code'], "GET failed for {$providerId}");
            $this->assertSame($listEntry, $get['body'], "List/Get drift on {$providerId}");
        }
    }

    public function testGetOAuth2ProviderUnsupported(): void
    {
        $response = $this->getOAuth2Provider('not-a-real-provider');

        $this->assertSame(400, $response['headers']['status-code']);
        $this->assertSame('project_provider_unsupported', $response['body']['type']);
    }

    public function testGetOAuth2ProviderRegisteredInConfigButNoUpdateClass(): void
    {
        // `mock` is present in oAuthProviders config (enabled: true) but is NOT
        // registered in Base::getProviderActions(). Get::action has two
        // separate `unsupported` throw branches — testGetOAuth2ProviderUnsupported
        // covers the first (provider missing from config); this covers the
        // second (provider in config but missing from the action registry).
        $response = $this->getOAuth2Provider('mock');

        $this->assertSame(400, $response['headers']['status-code']);
        $this->assertSame('project_provider_unsupported', $response['body']['type']);
    }

    public function testGetOAuth2ProviderWithoutAuthentication(): void
    {
        $response = $this->getOAuth2Provider('github', authenticated: false);

        $this->assertSame(401, $response['headers']['status-code']);
    }

    // =========================================================================
    // Update plain provider (Amazon — clientId + clientSecret, no extra fields)
    // =========================================================================

    public function testUpdateOAuth2Plain(): void
    {
        $response = $this->updateOAuth2('amazon', [
            'clientId' => 'amzn1.application-oa2-client.test01',
            'clientSecret' => 'test-secret-01',
            'enabled' => false,
        ]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('amazon', $response['body']['$id']);
        $this->assertSame('amzn1.application-oa2-client.test01', $response['body']['clientId']);
        $this->assertSame(false, $response['body']['enabled']);
    }

    public function testUpdateOAuth2PlainEnable(): void
    {
        // Amazon has no verifyCredentials() hook, so enabling with arbitrary
        // credentials succeeds without making a real network call.
        $response = $this->updateOAuth2('amazon', [
            'clientId' => 'amzn1.application-oa2-client.test02',
            'clientSecret' => 'test-secret-02',
            'enabled' => true,
        ]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame(true, $response['body']['enabled']);
    }

    public function testUpdateOAuth2PlainDisable(): void
    {
        $this->updateOAuth2('amazon', [
            'clientId' => 'amzn1.application-oa2-client.test03',
            'clientSecret' => 'test-secret-03',
            'enabled' => true,
        ]);

        $response = $this->updateOAuth2('amazon', [
            'enabled' => false,
        ]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame(false, $response['body']['enabled']);
        // Credentials persist across an enabled toggle.
        $this->assertSame('amzn1.application-oa2-client.test03', $response['body']['clientId']);
    }

    public function testUpdateOAuth2PlainPartial(): void
    {
        // Seed both credentials.
        $this->updateOAuth2('amazon', [
            'clientId' => 'seed-client-id',
            'clientSecret' => 'seed-secret',
            'enabled' => false,
        ]);

        // Patch only clientId.
        $response = $this->updateOAuth2('amazon', [
            'clientId' => 'updated-client-id',
        ]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('updated-client-id', $response['body']['clientId']);

        // Read back through GET to confirm the secret is still set internally
        // (write-only, so we cannot inspect the value, but enabling should still
        // succeed because the secret remains non-empty).
        $enable = $this->updateOAuth2('amazon', [
            'enabled' => true,
        ]);
        $this->assertSame(200, $enable['headers']['status-code']);
        $this->assertSame(true, $enable['body']['enabled']);
    }

    public function testUpdateOAuth2PlainEnableRequiresCredentials(): void
    {
        // Start from a clean state with no credentials.
        $this->updateOAuth2('amazon', [
            'clientId' => '',
            'clientSecret' => '',
            'enabled' => false,
        ]);

        $response = $this->updateOAuth2('amazon', [
            'enabled' => true,
        ]);

        $this->assertSame(400, $response['headers']['status-code']);
        $this->assertSame('general_argument_invalid', $response['body']['type']);
    }

    public function testUpdateOAuth2PlainEnabledOmittedDoesNotThrow(): void
    {
        // With enabled omitted (null) and no credentials, the silent-validation
        // branch must not surface as an error.
        $this->updateOAuth2('amazon', [
            'clientId' => '',
            'clientSecret' => '',
            'enabled' => false,
        ]);

        $response = $this->updateOAuth2('amazon', [
            'clientId' => 'partial-only',
        ]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame(false, $response['body']['enabled']);
        $this->assertSame('partial-only', $response['body']['clientId']);
    }

    public function testUpdateOAuth2PlainResponseModel(): void
    {
        $response = $this->updateOAuth2('amazon', [
            'clientId' => 'amzn1.application-oa2-client.modelCheck',
            'clientSecret' => 'model-check-secret',
            'enabled' => false,
        ]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertArrayHasKey('$id', $response['body']);
        $this->assertArrayHasKey('enabled', $response['body']);
        $this->assertArrayHasKey('clientId', $response['body']);
        $this->assertArrayHasKey('clientSecret', $response['body']);
    }

    public function testUpdateOAuth2WithoutAuthentication(): void
    {
        $response = $this->updateOAuth2('amazon', [
            'clientId' => 'no-auth',
            'clientSecret' => 'no-auth',
            'enabled' => false,
        ], authenticated: false);

        $this->assertSame(401, $response['headers']['status-code']);
    }

    public function testUpdateOAuth2UnknownProvider(): void
    {
        // Each Update endpoint is registered at a fixed `/oauth2/{providerId}`
        // path, so an unknown provider does not match any route → 404.
        $response = $this->updateOAuth2('not-a-real-provider', [
            'clientId' => 'whatever',
            'clientSecret' => 'whatever',
            'enabled' => false,
        ]);

        $this->assertSame(404, $response['headers']['status-code']);
    }

    public function testUpdateOAuth2InvalidEnabled(): void
    {
        $response = $this->updateOAuth2('amazon', [
            'enabled' => 'not-a-boolean',
        ]);

        $this->assertSame(400, $response['headers']['status-code']);
        $this->assertSame('general_argument_invalid', $response['body']['type']);
    }

    // =========================================================================
    // Update GitHub (verifyCredentials makes a real call to GitHub on enable)
    //
    // Only failure paths and the silent-on-disable branch are tested here.
    // Happy-path enable would require real GitHub OAuth2 credentials, which
    // CI doesn't have. Wiring, validation, and the non-enabling branch are
    // sufficient to surface most regressions; success-path issues are caught
    // by integration / staging environments instead.
    // =========================================================================

    public function testUpdateOAuth2GitHubInvalidCredentialsRejected(): void
    {
        // GitHub is the only provider with a real verifyCredentials() hook.
        // Enabling with bogus credentials must surface a 400 from the wrapping
        // exception, not silently succeed.
        $response = $this->updateOAuth2('github', [
            'clientId' => 'fake-client-id-' . \uniqid(),
            'clientSecret' => 'fake-client-secret',
            'enabled' => true,
        ]);

        $this->assertSame(400, $response['headers']['status-code']);
        $this->assertSame('general_argument_invalid', $response['body']['type']);

        // Cleanup: ensure it's left disabled.
        $this->updateOAuth2('github', [
            'clientId' => '',
            'clientSecret' => '',
            'enabled' => false,
        ]);
    }

    public function testUpdateOAuth2GitHubInvalidCredentialsSilentWhenNotEnabling(): void
    {
        // When `enabled` is omitted, verifyCredentials() failure is swallowed.
        // The provider remains disabled but the request succeeds.
        $response = $this->updateOAuth2('github', [
            'clientId' => 'still-fake-' . \uniqid(),
            'clientSecret' => 'still-fake-secret',
        ]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame(false, $response['body']['enabled']);

        // Cleanup
        $this->updateOAuth2('github', [
            'clientId' => '',
            'clientSecret' => '',
            'enabled' => false,
        ]);
    }

    // =========================================================================
    // Update Apple (serviceId + keyId + teamId + p8File)
    // =========================================================================

    public function testUpdateOAuth2Apple(): void
    {
        $response = $this->updateOAuth2('apple', [
            'serviceId' => 'ip.appwrite.app.web',
            'keyId' => 'P4000000N8',
            'teamId' => 'D4000000R6',
            'p8File' => '-----BEGIN PRIVATE KEY-----TEST-----END PRIVATE KEY-----',
            'enabled' => false,
        ]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('apple', $response['body']['$id']);
        $this->assertSame('ip.appwrite.app.web', $response['body']['serviceId']);
        $this->assertSame('P4000000N8', $response['body']['keyId']);
        $this->assertSame('D4000000R6', $response['body']['teamId']);
        $this->assertSame('', $response['body']['p8File']);
        $this->assertSame(false, $response['body']['enabled']);

        // Cleanup
        $this->updateOAuth2('apple', [
            'serviceId' => '',
            'keyId' => '',
            'teamId' => '',
            'p8File' => '',
            'enabled' => false,
        ]);
    }

    public function testUpdateOAuth2ApplePartial(): void
    {
        // Seed all four fields.
        $this->updateOAuth2('apple', [
            'serviceId' => 'ip.appwrite.app.seed',
            'keyId' => 'KEYSEED01',
            'teamId' => 'TEAMSEED01',
            'p8File' => '-----BEGIN PRIVATE KEY-----SEED-----END PRIVATE KEY-----',
            'enabled' => false,
        ]);

        // Patch only `keyId` — others must be preserved.
        $response = $this->updateOAuth2('apple', [
            'keyId' => 'KEYUPDATED',
        ]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('ip.appwrite.app.seed', $response['body']['serviceId']);
        $this->assertSame('KEYUPDATED', $response['body']['keyId']);
        $this->assertSame('TEAMSEED01', $response['body']['teamId']);
        $this->assertSame('', $response['body']['p8File']);

        // Cleanup
        $this->updateOAuth2('apple', [
            'serviceId' => '',
            'keyId' => '',
            'teamId' => '',
            'p8File' => '',
            'enabled' => false,
        ]);
    }

    public function testUpdateOAuth2ApplePartialPreservesEachField(): void
    {
        // Seed all four fields, then patch each one individually and confirm
        // the others survive across the chain. testUpdateOAuth2ApplePartial
        // only covers `keyId`; this exercises serviceId/teamId/p8File too.
        $this->updateOAuth2('apple', [
            'serviceId' => 'ip.appwrite.app.merge',
            'keyId' => 'KEYMERGE01',
            'teamId' => 'TEAMMERGE',
            'p8File' => '-----BEGIN PRIVATE KEY-----MERGE-----END PRIVATE KEY-----',
            'enabled' => false,
        ]);

        // Patch only `teamId`.
        $teamOnly = $this->updateOAuth2('apple', [
            'teamId' => 'TEAMROTATED',
        ]);
        $this->assertSame(200, $teamOnly['headers']['status-code']);
        $this->assertSame('TEAMROTATED', $teamOnly['body']['teamId']);
        $this->assertSame('KEYMERGE01', $teamOnly['body']['keyId']);
        $this->assertSame('', $teamOnly['body']['p8File']);
        $this->assertSame('ip.appwrite.app.merge', $teamOnly['body']['serviceId']);

        // Patch only `serviceId` — keyId/teamId/p8File live in the JSON blob
        // and must survive a top-level (non-blob) field update.
        $serviceOnly = $this->updateOAuth2('apple', [
            'serviceId' => 'ip.appwrite.app.rotated',
        ]);
        $this->assertSame(200, $serviceOnly['headers']['status-code']);
        $this->assertSame('ip.appwrite.app.rotated', $serviceOnly['body']['serviceId']);

        // Patch only `p8File`. keyId/teamId/serviceId must still be set
        // internally — confirm by enabling. Apple has no verifyCredentials()
        // hook, so persistCredentials only checks for non-empty serviceId and
        // non-empty stored secret blob.
        $p8Only = $this->updateOAuth2('apple', [
            'p8File' => '-----BEGIN PRIVATE KEY-----ROTATED-----END PRIVATE KEY-----',
        ]);
        $this->assertSame(200, $p8Only['headers']['status-code']);

        $enable = $this->updateOAuth2('apple', ['enabled' => true]);
        $this->assertSame(200, $enable['headers']['status-code']);
        $this->assertTrue($enable['body']['enabled']);

        // Cleanup
        $this->updateOAuth2('apple', [
            'serviceId' => '',
            'keyId' => '',
            'teamId' => '',
            'p8File' => '',
            'enabled' => false,
        ]);
    }

    public function testUpdateOAuth2AppleClearAllFieldsBlocksEnable(): void
    {
        // Seed all four Apple fields.
        $this->updateOAuth2('apple', [
            'serviceId' => 'ip.appwrite.app.clearAll',
            'keyId' => 'KEYCLEARALL',
            'teamId' => 'TEAMCLEARALL',
            'p8File' => '-----BEGIN PRIVATE KEY-----CLEARALL-----END PRIVATE KEY-----',
            'enabled' => false,
        ]);

        // Clear all credentials with empty strings. With `enabled` omitted, the
        // silent-validation branch swallows the empty-credentials throw, so the
        // call still succeeds — see testUpdateOAuth2PlainEnabledOmittedDoesNotThrow.
        $clear = $this->updateOAuth2('apple', [
            'serviceId' => '',
            'keyId' => '',
            'teamId' => '',
            'p8File' => '',
        ]);
        $this->assertSame(200, $clear['headers']['status-code']);
        $this->assertSame('', $clear['body']['serviceId']);

        // A subsequent `enabled => true` must now 400. Empty serviceId trips
        // persistCredentials' empty(appId) guard before any provider hook runs,
        // proving that the clear actually took effect on stored state.
        $enable = $this->updateOAuth2('apple', [
            'enabled' => true,
        ]);
        $this->assertSame(400, $enable['headers']['status-code']);
        $this->assertSame('general_argument_invalid', $enable['body']['type']);

        // Cleanup (already cleared; included for reset symmetry).
        $this->updateOAuth2('apple', [
            'serviceId' => '',
            'keyId' => '',
            'teamId' => '',
            'p8File' => '',
            'enabled' => false,
        ]);
    }

    public function testUpdateOAuth2AppleResponseModel(): void
    {
        $response = $this->updateOAuth2('apple', [
            'serviceId' => 'ip.appwrite.app.shape',
            'keyId' => 'SHAPEKEY01',
            'teamId' => 'SHAPETEAM',
            'p8File' => '-----BEGIN PRIVATE KEY-----SHAPE-----END PRIVATE KEY-----',
            'enabled' => false,
        ]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertArrayHasKey('$id', $response['body']);
        $this->assertArrayHasKey('enabled', $response['body']);
        $this->assertArrayHasKey('serviceId', $response['body']);
        $this->assertArrayHasKey('keyId', $response['body']);
        $this->assertArrayHasKey('teamId', $response['body']);
        $this->assertArrayHasKey('p8File', $response['body']);
        // Apple has no clientId/clientSecret in the response model.
        $this->assertArrayNotHasKey('clientId', $response['body']);
        $this->assertArrayNotHasKey('clientSecret', $response['body']);

        // Cleanup
        $this->updateOAuth2('apple', [
            'serviceId' => '',
            'keyId' => '',
            'teamId' => '',
            'p8File' => '',
            'enabled' => false,
        ]);
    }

    public function testGetOAuth2AppleSecretsWriteOnly(): void
    {
        $this->updateOAuth2('apple', [
            'serviceId' => 'ip.appwrite.app.read',
            'keyId' => 'KEYREAD',
            'teamId' => 'TEAMREAD',
            'p8File' => '-----BEGIN PRIVATE KEY-----READ-----END PRIVATE KEY-----',
            'enabled' => false,
        ]);

        $response = $this->getOAuth2Provider('apple');

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('ip.appwrite.app.read', $response['body']['serviceId']);
        $this->assertSame('KEYREAD', $response['body']['keyId']);
        $this->assertSame('TEAMREAD', $response['body']['teamId']);
        $this->assertSame('', $response['body']['p8File']);

        // Cleanup
        $this->updateOAuth2('apple', [
            'serviceId' => '',
            'keyId' => '',
            'teamId' => '',
            'p8File' => '',
            'enabled' => false,
        ]);
    }

    public function testUpdateOAuth2AppleEnableAndReadBack(): void
    {
        // Apple has no verifyCredentials() hook, so enabling with arbitrary
        // (well-formed) values succeeds without any real Apple network call.
        $update = $this->updateOAuth2('apple', [
            'serviceId' => 'ip.appwrite.app.enable',
            'keyId' => 'ENABLEKEY',
            'teamId' => 'ENABLETEAM',
            'p8File' => '-----BEGIN PRIVATE KEY-----ENABLE-----END PRIVATE KEY-----',
            'enabled' => true,
        ]);

        $this->assertSame(200, $update['headers']['status-code']);
        $this->assertTrue($update['body']['enabled']);

        // GET must hide p8File while keeping the non-secret fields.
        $get = $this->getOAuth2Provider('apple');
        $this->assertSame(200, $get['headers']['status-code']);
        $this->assertTrue($get['body']['enabled']);
        $this->assertSame('ip.appwrite.app.enable', $get['body']['serviceId']);
        $this->assertSame('ENABLEKEY', $get['body']['keyId']);
        $this->assertSame('ENABLETEAM', $get['body']['teamId']);
        $this->assertSame('', $get['body']['p8File']);

        // Cleanup
        $this->updateOAuth2('apple', [
            'serviceId' => '',
            'keyId' => '',
            'teamId' => '',
            'p8File' => '',
            'enabled' => false,
        ]);
    }

    // =========================================================================
    // Update Auth0 (clientId + clientSecret + optional endpoint)
    // =========================================================================

    public function testUpdateOAuth2Auth0(): void
    {
        $response = $this->updateOAuth2('auth0', [
            'clientId' => 'OaOkIA000000000000000000005KLSYq',
            'clientSecret' => 'auth0-test-secret',
            'endpoint' => 'example.us.auth0.com',
            'enabled' => false,
        ]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('auth0', $response['body']['$id']);
        $this->assertSame('OaOkIA000000000000000000005KLSYq', $response['body']['clientId']);
        $this->assertSame('example.us.auth0.com', $response['body']['endpoint']);

        // Cleanup
        $this->updateOAuth2('auth0', [
            'clientId' => '',
            'clientSecret' => '',
            'endpoint' => '',
            'enabled' => false,
        ]);
    }

    public function testUpdateOAuth2Auth0PartialEndpoint(): void
    {
        // Seed clientSecret + endpoint.
        $this->updateOAuth2('auth0', [
            'clientId' => 'auth0-seed-client',
            'clientSecret' => 'auth0-seed-secret',
            'endpoint' => 'seed.us.auth0.com',
            'enabled' => false,
        ]);

        // Update only endpoint.
        $response = $this->updateOAuth2('auth0', [
            'endpoint' => 'updated.us.auth0.com',
        ]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('updated.us.auth0.com', $response['body']['endpoint']);
        // clientId is unchanged on top-level provider state.
        $this->assertSame('auth0-seed-client', $response['body']['clientId']);

        // Cleanup
        $this->updateOAuth2('auth0', [
            'clientId' => '',
            'clientSecret' => '',
            'endpoint' => '',
            'enabled' => false,
        ]);
    }

    public function testUpdateOAuth2Auth0PartialPreservesEachField(): void
    {
        // testUpdateOAuth2Auth0PartialEndpoint only patches `endpoint`. Cover
        // patching `clientSecret` alone (must not wipe endpoint) and `clientId`
        // alone (must not wipe the JSON-blob fields).
        $this->updateOAuth2('auth0', [
            'clientId' => 'auth0-merge-client',
            'clientSecret' => 'auth0-merge-secret',
            'endpoint' => 'merge.us.auth0.com',
            'enabled' => false,
        ]);

        // Patch only clientSecret — clientId and endpoint must survive.
        $secretOnly = $this->updateOAuth2('auth0', [
            'clientSecret' => 'auth0-rotated-secret',
        ]);
        $this->assertSame(200, $secretOnly['headers']['status-code']);
        $this->assertSame('auth0-merge-client', $secretOnly['body']['clientId']);
        $this->assertSame('merge.us.auth0.com', $secretOnly['body']['endpoint']);

        // Patch only clientId — endpoint must survive.
        $idOnly = $this->updateOAuth2('auth0', [
            'clientId' => 'auth0-rotated-client',
        ]);
        $this->assertSame(200, $idOnly['headers']['status-code']);
        $this->assertSame('auth0-rotated-client', $idOnly['body']['clientId']);
        $this->assertSame('merge.us.auth0.com', $idOnly['body']['endpoint']);

        // Confirm the rotated clientSecret survived the chain by enabling.
        // Auth0 has no verifyCredentials() hook; non-empty secret is enough.
        $enable = $this->updateOAuth2('auth0', ['enabled' => true]);
        $this->assertSame(200, $enable['headers']['status-code']);
        $this->assertTrue($enable['body']['enabled']);

        // Cleanup
        $this->updateOAuth2('auth0', [
            'clientId' => '',
            'clientSecret' => '',
            'endpoint' => '',
            'enabled' => false,
        ]);
    }

    public function testUpdateOAuth2Auth0EndpointAcceptsEmpty(): void
    {
        // Auth0's `endpoint` validator is `Nullable(Text(256, 0))`. Passing
        // `''` must clear the stored value rather than leave it untouched
        // (would happen if the merge fell back to existing on empty-string).
        $this->updateOAuth2('auth0', [
            'clientId' => 'auth0-clear-client',
            'clientSecret' => 'auth0-clear-secret',
            'endpoint' => 'before.us.auth0.com',
            'enabled' => false,
        ]);

        $response = $this->updateOAuth2('auth0', [
            'endpoint' => '',
        ]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('', $response['body']['endpoint']);
        $this->assertSame('auth0-clear-client', $response['body']['clientId']);

        // Cleanup
        $this->updateOAuth2('auth0', [
            'clientId' => '',
            'clientSecret' => '',
            'endpoint' => '',
            'enabled' => false,
        ]);
    }

    public function testUpdateOAuth2Auth0EnableAndReadBack(): void
    {
        $update = $this->updateOAuth2('auth0', [
            'clientId' => 'auth0-enable-client',
            'clientSecret' => 'auth0-enable-secret',
            'endpoint' => 'enable.us.auth0.com',
            'enabled' => true,
        ]);

        $this->assertSame(200, $update['headers']['status-code']);
        $this->assertTrue($update['body']['enabled']);

        // GET must hide clientSecret while keeping clientId and endpoint.
        $get = $this->getOAuth2Provider('auth0');
        $this->assertSame(200, $get['headers']['status-code']);
        $this->assertTrue($get['body']['enabled']);
        $this->assertSame('auth0-enable-client', $get['body']['clientId']);
        $this->assertSame('enable.us.auth0.com', $get['body']['endpoint']);
        $this->assertSame('', $get['body']['clientSecret']);

        // Cleanup
        $this->updateOAuth2('auth0', [
            'clientId' => '',
            'clientSecret' => '',
            'endpoint' => '',
            'enabled' => false,
        ]);
    }

    // =========================================================================
    // Update Authentik (clientId + clientSecret + optional endpoint)
    // =========================================================================

    public function testUpdateOAuth2AuthentikAllowsOmittedEndpointWhenDisabled(): void
    {
        $response = $this->updateOAuth2('authentik', [
            'clientId' => 'whatever',
            'clientSecret' => 'whatever',
            'enabled' => false,
        ]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('authentik', $response['body']['$id']);

        // Cleanup
        $this->updateOAuth2('authentik', [
            'clientId' => '',
            'clientSecret' => '',
            'endpoint' => '',
            'enabled' => false,
        ]);
    }

    public function testUpdateOAuth2AuthentikEmptyEndpointRejectedWhenEnabling(): void
    {
        $response = $this->updateOAuth2('authentik', [
            'clientId' => 'whatever',
            'clientSecret' => 'whatever',
            'endpoint' => '',
            'enabled' => true,
        ]);

        $this->assertSame(400, $response['headers']['status-code']);
        $this->assertSame('general_argument_invalid', $response['body']['type']);
    }

    public function testUpdateOAuth2Authentik(): void
    {
        $response = $this->updateOAuth2('authentik', [
            'clientId' => 'dTKOPa0000000000000000000000000000e7G8hv',
            'clientSecret' => 'authentik-secret',
            'endpoint' => 'example.authentik.com',
            'enabled' => false,
        ]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('authentik', $response['body']['$id']);
        $this->assertSame('dTKOPa0000000000000000000000000000e7G8hv', $response['body']['clientId']);
        $this->assertSame('example.authentik.com', $response['body']['endpoint']);

        // Cleanup
        $this->updateOAuth2('authentik', [
            'clientId' => '',
            'clientSecret' => '',
            'endpoint' => '',
            'enabled' => false,
        ]);
    }

    public function testUpdateOAuth2AuthentikPartialPreservesSecret(): void
    {
        // The `clientSecret` and `endpoint` live in the JSON blob and must
        // survive when omitted on a subsequent call that only changes clientId.
        $this->updateOAuth2('authentik', [
            'clientId' => 'authentik-merge-client',
            'clientSecret' => 'authentik-merge-secret',
            'endpoint' => 'merge.authentik.com',
            'enabled' => false,
        ]);

        $response = $this->updateOAuth2('authentik', [
            'clientId' => 'authentik-rotated-client',
        ]);
        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('authentik-rotated-client', $response['body']['clientId']);
        $this->assertSame('merge.authentik.com', $response['body']['endpoint']);

        // Confirm clientSecret survived the omitted-field merge by enabling
        // without re-sending endpoint.
        $enable = $this->updateOAuth2('authentik', [
            'enabled' => true,
        ]);
        $this->assertSame(200, $enable['headers']['status-code']);
        $this->assertTrue($enable['body']['enabled']);

        // Cleanup
        $this->updateOAuth2('authentik', [
            'clientId' => '',
            'clientSecret' => '',
            'endpoint' => '',
            'enabled' => false,
        ]);
    }

    public function testUpdateOAuth2AuthentikEnableAndReadBack(): void
    {
        $update = $this->updateOAuth2('authentik', [
            'clientId' => 'authentik-enable-client',
            'clientSecret' => 'authentik-enable-secret',
            'endpoint' => 'enable.authentik.com',
            'enabled' => true,
        ]);

        $this->assertSame(200, $update['headers']['status-code']);
        $this->assertTrue($update['body']['enabled']);

        // GET must hide clientSecret while keeping clientId and endpoint.
        $get = $this->getOAuth2Provider('authentik');
        $this->assertSame(200, $get['headers']['status-code']);
        $this->assertTrue($get['body']['enabled']);
        $this->assertSame('authentik-enable-client', $get['body']['clientId']);
        $this->assertSame('enable.authentik.com', $get['body']['endpoint']);
        $this->assertSame('', $get['body']['clientSecret']);

        // Cleanup
        $this->updateOAuth2('authentik', [
            'clientId' => '',
            'clientSecret' => '',
            'endpoint' => '',
            'enabled' => false,
        ]);
    }

    // =========================================================================
    // Update FusionAuth (clientId + clientSecret + optional endpoint)
    // =========================================================================

    public function testUpdateOAuth2FusionAuthAllowsOmittedEndpointWhenDisabled(): void
    {
        $response = $this->updateOAuth2('fusionauth', [
            'clientId' => 'whatever',
            'clientSecret' => 'whatever',
            'enabled' => false,
        ]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('fusionauth', $response['body']['$id']);

        // Cleanup
        $this->updateOAuth2('fusionauth', [
            'clientId' => '',
            'clientSecret' => '',
            'endpoint' => '',
            'enabled' => false,
        ]);
    }

    public function testUpdateOAuth2FusionAuthEmptyEndpointRejectedWhenEnabling(): void
    {
        $response = $this->updateOAuth2('fusionauth', [
            'clientId' => 'whatever',
            'clientSecret' => 'whatever',
            'endpoint' => '',
            'enabled' => true,
        ]);

        $this->assertSame(400, $response['headers']['status-code']);
        $this->assertSame('general_argument_invalid', $response['body']['type']);
    }

    public function testUpdateOAuth2FusionAuth(): void
    {
        $response = $this->updateOAuth2('fusionauth', [
            'clientId' => 'b2222c00-0000-0000-0000-000000862097',
            'clientSecret' => 'fusionauth-secret',
            'endpoint' => 'example.fusionauth.io',
            'enabled' => false,
        ]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('fusionauth', $response['body']['$id']);
        $this->assertSame('b2222c00-0000-0000-0000-000000862097', $response['body']['clientId']);
        $this->assertSame('example.fusionauth.io', $response['body']['endpoint']);

        // Cleanup
        $this->updateOAuth2('fusionauth', [
            'clientId' => '',
            'clientSecret' => '',
            'endpoint' => '',
            'enabled' => false,
        ]);
    }

    public function testUpdateOAuth2FusionAuthPartialPreservesSecret(): void
    {
        // The `clientSecret` and `endpoint` live in the JSON blob and must
        // survive when omitted on a subsequent call that only changes clientId.
        $this->updateOAuth2('fusionauth', [
            'clientId' => 'fusionauth-merge-client',
            'clientSecret' => 'fusionauth-merge-secret',
            'endpoint' => 'merge.fusionauth.io',
            'enabled' => false,
        ]);

        $response = $this->updateOAuth2('fusionauth', [
            'clientId' => 'fusionauth-rotated-client',
        ]);
        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('fusionauth-rotated-client', $response['body']['clientId']);
        $this->assertSame('merge.fusionauth.io', $response['body']['endpoint']);

        // Confirm clientSecret survived the omitted-field merge by enabling
        // without re-sending endpoint.
        $enable = $this->updateOAuth2('fusionauth', [
            'enabled' => true,
        ]);
        $this->assertSame(200, $enable['headers']['status-code']);
        $this->assertTrue($enable['body']['enabled']);

        // Cleanup
        $this->updateOAuth2('fusionauth', [
            'clientId' => '',
            'clientSecret' => '',
            'endpoint' => '',
            'enabled' => false,
        ]);
    }

    public function testUpdateOAuth2FusionAuthEnableAndReadBack(): void
    {
        $update = $this->updateOAuth2('fusionauth', [
            'clientId' => 'fusionauth-enable-client',
            'clientSecret' => 'fusionauth-enable-secret',
            'endpoint' => 'enable.fusionauth.io',
            'enabled' => true,
        ]);

        $this->assertSame(200, $update['headers']['status-code']);
        $this->assertTrue($update['body']['enabled']);

        // GET must hide clientSecret while keeping clientId and endpoint.
        $get = $this->getOAuth2Provider('fusionauth');
        $this->assertSame(200, $get['headers']['status-code']);
        $this->assertTrue($get['body']['enabled']);
        $this->assertSame('fusionauth-enable-client', $get['body']['clientId']);
        $this->assertSame('enable.fusionauth.io', $get['body']['endpoint']);
        $this->assertSame('', $get['body']['clientSecret']);

        // Cleanup
        $this->updateOAuth2('fusionauth', [
            'clientId' => '',
            'clientSecret' => '',
            'endpoint' => '',
            'enabled' => false,
        ]);
    }

    // =========================================================================
    // Update Keycloak (clientId + clientSecret + optional endpoint + optional realmName)
    // =========================================================================

    public function testUpdateOAuth2KeycloakAllowsOmittedEndpointWhenDisabled(): void
    {
        $response = $this->updateOAuth2('keycloak', [
            'clientId' => 'whatever',
            'clientSecret' => 'whatever',
            'realmName' => 'appwrite-realm',
            'enabled' => false,
        ]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('keycloak', $response['body']['$id']);

        // Cleanup
        $this->updateOAuth2('keycloak', [
            'clientId' => '',
            'clientSecret' => '',
            'endpoint' => '',
            'realmName' => '',
            'enabled' => false,
        ]);
    }

    public function testUpdateOAuth2KeycloakEmptyEndpointRejectedWhenEnabling(): void
    {
        $response = $this->updateOAuth2('keycloak', [
            'clientId' => 'whatever',
            'clientSecret' => 'whatever',
            'endpoint' => '',
            'realmName' => 'appwrite-realm',
            'enabled' => true,
        ]);

        $this->assertSame(400, $response['headers']['status-code']);
        $this->assertSame('general_argument_invalid', $response['body']['type']);
    }

    public function testUpdateOAuth2KeycloakAllowsOmittedRealmNameWhenDisabled(): void
    {
        $response = $this->updateOAuth2('keycloak', [
            'clientId' => 'whatever',
            'clientSecret' => 'whatever',
            'endpoint' => 'keycloak.example.com',
            'enabled' => false,
        ]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('keycloak', $response['body']['$id']);

        // Cleanup
        $this->updateOAuth2('keycloak', [
            'clientId' => '',
            'clientSecret' => '',
            'endpoint' => '',
            'realmName' => '',
            'enabled' => false,
        ]);
    }

    public function testUpdateOAuth2KeycloakEmptyRealmNameRejectedWhenEnabling(): void
    {
        $response = $this->updateOAuth2('keycloak', [
            'clientId' => 'whatever',
            'clientSecret' => 'whatever',
            'endpoint' => 'keycloak.example.com',
            'realmName' => '',
            'enabled' => true,
        ]);

        $this->assertSame(400, $response['headers']['status-code']);
        $this->assertSame('general_argument_invalid', $response['body']['type']);
    }

    public function testUpdateOAuth2Keycloak(): void
    {
        $response = $this->updateOAuth2('keycloak', [
            'clientId' => 'appwrite-o0000000st-app',
            'clientSecret' => 'keycloak-secret',
            'endpoint' => 'keycloak.example.com',
            'realmName' => 'appwrite-realm',
            'enabled' => false,
        ]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('keycloak', $response['body']['$id']);
        $this->assertSame('appwrite-o0000000st-app', $response['body']['clientId']);
        $this->assertSame('keycloak.example.com', $response['body']['endpoint']);
        $this->assertSame('appwrite-realm', $response['body']['realmName']);

        // Cleanup
        $this->updateOAuth2('keycloak', [
            'clientId' => '',
            'clientSecret' => '',
            'endpoint' => '',
            'realmName' => '',
            'enabled' => false,
        ]);
    }

    public function testUpdateOAuth2KeycloakPartialPreservesSecret(): void
    {
        // The `clientSecret`, `endpoint`, and `realmName` live in the JSON
        // blob and must survive when omitted on a subsequent call that only
        // changes clientId.
        $this->updateOAuth2('keycloak', [
            'clientId' => 'keycloak-merge-client',
            'clientSecret' => 'keycloak-merge-secret',
            'endpoint' => 'merge.keycloak.com',
            'realmName' => 'merge-realm',
            'enabled' => false,
        ]);

        $response = $this->updateOAuth2('keycloak', [
            'clientId' => 'keycloak-rotated-client',
        ]);
        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('keycloak-rotated-client', $response['body']['clientId']);
        $this->assertSame('merge.keycloak.com', $response['body']['endpoint']);
        $this->assertSame('merge-realm', $response['body']['realmName']);

        // Confirm clientSecret survived the omitted-field merge by enabling
        // without re-sending endpoint or realmName.
        $enable = $this->updateOAuth2('keycloak', [
            'enabled' => true,
        ]);
        $this->assertSame(200, $enable['headers']['status-code']);
        $this->assertTrue($enable['body']['enabled']);

        // Cleanup
        $this->updateOAuth2('keycloak', [
            'clientId' => '',
            'clientSecret' => '',
            'endpoint' => '',
            'realmName' => '',
            'enabled' => false,
        ]);
    }

    public function testUpdateOAuth2KeycloakEnableAndReadBack(): void
    {
        $update = $this->updateOAuth2('keycloak', [
            'clientId' => 'keycloak-enable-client',
            'clientSecret' => 'keycloak-enable-secret',
            'endpoint' => 'enable.keycloak.com',
            'realmName' => 'enable-realm',
            'enabled' => true,
        ]);

        $this->assertSame(200, $update['headers']['status-code']);
        $this->assertTrue($update['body']['enabled']);

        // GET must hide clientSecret while keeping clientId, endpoint, realmName.
        $get = $this->getOAuth2Provider('keycloak');
        $this->assertSame(200, $get['headers']['status-code']);
        $this->assertTrue($get['body']['enabled']);
        $this->assertSame('keycloak-enable-client', $get['body']['clientId']);
        $this->assertSame('enable.keycloak.com', $get['body']['endpoint']);
        $this->assertSame('enable-realm', $get['body']['realmName']);
        $this->assertSame('', $get['body']['clientSecret']);

        // Cleanup
        $this->updateOAuth2('keycloak', [
            'clientId' => '',
            'clientSecret' => '',
            'endpoint' => '',
            'realmName' => '',
            'enabled' => false,
        ]);
    }

    // =========================================================================
    // Update Microsoft (applicationId + applicationSecret + optional tenant)
    // =========================================================================

    public function testUpdateOAuth2MicrosoftAllowsOmittedTenantWhenDisabled(): void
    {
        $response = $this->updateOAuth2('microsoft', [
            'applicationId' => 'whatever',
            'applicationSecret' => 'whatever',
            'enabled' => false,
        ]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('microsoft', $response['body']['$id']);

        // Cleanup
        $this->updateOAuth2('microsoft', [
            'applicationId' => '',
            'applicationSecret' => '',
            'tenant' => '',
            'enabled' => false,
        ]);
    }

    public function testUpdateOAuth2MicrosoftEmptyTenantRejectedWhenEnabling(): void
    {
        $response = $this->updateOAuth2('microsoft', [
            'applicationId' => 'whatever',
            'applicationSecret' => 'whatever',
            'tenant' => '',
            'enabled' => true,
        ]);

        $this->assertSame(400, $response['headers']['status-code']);
        $this->assertSame('general_argument_invalid', $response['body']['type']);
    }

    public function testUpdateOAuth2Microsoft(): void
    {
        $response = $this->updateOAuth2('microsoft', [
            'applicationId' => '00001111-aaaa-2222-bbbb-3333cccc4444',
            'applicationSecret' => 'A1bC2dE3fH4iJ5kL6mN7oP8qR9sT0u',
            'tenant' => 'common',
            'enabled' => false,
        ]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('microsoft', $response['body']['$id']);
        $this->assertSame('00001111-aaaa-2222-bbbb-3333cccc4444', $response['body']['applicationId']);
        $this->assertSame('common', $response['body']['tenant']);
        // Custom param names: applicationId/applicationSecret, not clientId/clientSecret.
        $this->assertArrayNotHasKey('clientId', $response['body']);
        $this->assertArrayNotHasKey('clientSecret', $response['body']);

        // Cleanup
        $this->updateOAuth2('microsoft', [
            'applicationId' => '',
            'applicationSecret' => '',
            'tenant' => '',
            'enabled' => false,
        ]);
    }

    public function testUpdateOAuth2MicrosoftPartialPreservesSecret(): void
    {
        // Seed full credentials.
        $this->updateOAuth2('microsoft', [
            'applicationId' => 'seed-app-id',
            'applicationSecret' => 'seed-app-secret',
            'tenant' => 'common',
            'enabled' => false,
        ]);

        // Patch with only a new applicationId, leaving applicationSecret and
        // tenant omitted. The stored JSON values must not be wiped.
        $response = $this->updateOAuth2('microsoft', [
            'applicationId' => 'updated-app-id',
        ]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('updated-app-id', $response['body']['applicationId']);
        $this->assertSame('common', $response['body']['tenant']);

        // Cleanup
        $this->updateOAuth2('microsoft', [
            'applicationId' => '',
            'applicationSecret' => '',
            'tenant' => '',
            'enabled' => false,
        ]);
    }

    public function testUpdateOAuth2MicrosoftEnableAndReadBack(): void
    {
        $update = $this->updateOAuth2('microsoft', [
            'applicationId' => 'microsoft-enable-app',
            'applicationSecret' => 'microsoft-enable-secret',
            'tenant' => 'common',
            'enabled' => true,
        ]);

        $this->assertSame(200, $update['headers']['status-code']);
        $this->assertTrue($update['body']['enabled']);

        // GET must hide applicationSecret while keeping applicationId/tenant.
        $get = $this->getOAuth2Provider('microsoft');
        $this->assertSame(200, $get['headers']['status-code']);
        $this->assertTrue($get['body']['enabled']);
        $this->assertSame('microsoft-enable-app', $get['body']['applicationId']);
        $this->assertSame('common', $get['body']['tenant']);
        $this->assertSame('', $get['body']['applicationSecret']);

        // Cleanup
        $this->updateOAuth2('microsoft', [
            'applicationId' => '',
            'applicationSecret' => '',
            'tenant' => '',
            'enabled' => false,
        ]);
    }

    // =========================================================================
    // Update Gitlab (applicationId + secret + optional endpoint, custom names)
    // =========================================================================

    public function testUpdateOAuth2Gitlab(): void
    {
        $response = $this->updateOAuth2('gitlab', [
            'applicationId' => 'd41ffe0000000000000000000000000000000000000000000000000000d5e252',
            'secret' => 'gloas-838cfa00',
            'endpoint' => 'https://gitlab.example.com',
            'enabled' => false,
        ]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('gitlab', $response['body']['$id']);
        $this->assertSame('d41ffe0000000000000000000000000000000000000000000000000000d5e252', $response['body']['applicationId']);
        $this->assertSame('https://gitlab.example.com', $response['body']['endpoint']);
        // Custom names — the response model exposes `applicationId`/`secret`.
        $this->assertArrayNotHasKey('clientId', $response['body']);
        $this->assertArrayNotHasKey('clientSecret', $response['body']);

        // Cleanup
        $this->updateOAuth2('gitlab', [
            'applicationId' => '',
            'secret' => '',
            'endpoint' => '',
            'enabled' => false,
        ]);
    }

    public function testUpdateOAuth2GitlabInvalidEndpoint(): void
    {
        $response = $this->updateOAuth2('gitlab', [
            'applicationId' => 'whatever',
            'secret' => 'whatever',
            'endpoint' => 'not a url',
        ]);

        $this->assertSame(400, $response['headers']['status-code']);
        $this->assertSame('general_argument_invalid', $response['body']['type']);
    }

    public function testUpdateOAuth2GitlabPartialEndpoint(): void
    {
        $this->updateOAuth2('gitlab', [
            'applicationId' => 'gitlab-seed-app',
            'secret' => 'gitlab-seed-secret',
            'endpoint' => 'https://seed.gitlab.com',
            'enabled' => false,
        ]);

        $response = $this->updateOAuth2('gitlab', [
            'endpoint' => 'https://updated.gitlab.com',
        ]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('https://updated.gitlab.com', $response['body']['endpoint']);
        $this->assertSame('gitlab-seed-app', $response['body']['applicationId']);

        // Cleanup
        $this->updateOAuth2('gitlab', [
            'applicationId' => '',
            'secret' => '',
            'endpoint' => '',
            'enabled' => false,
        ]);
    }

    public function testUpdateOAuth2GitlabPartialPreservesEachField(): void
    {
        // testUpdateOAuth2GitlabPartialEndpoint covers patching only `endpoint`.
        // Cover patching `secret` alone (must not wipe applicationId/endpoint)
        // and `applicationId` alone (must not wipe the JSON-blob endpoint).
        $this->updateOAuth2('gitlab', [
            'applicationId' => 'gitlab-merge-app',
            'secret' => 'gitlab-merge-secret',
            'endpoint' => 'https://merge.gitlab.com',
            'enabled' => false,
        ]);

        // Patch only `secret`.
        $secretOnly = $this->updateOAuth2('gitlab', [
            'secret' => 'gitlab-rotated-secret',
        ]);
        $this->assertSame(200, $secretOnly['headers']['status-code']);
        $this->assertSame('gitlab-merge-app', $secretOnly['body']['applicationId']);
        $this->assertSame('https://merge.gitlab.com', $secretOnly['body']['endpoint']);

        // Patch only `applicationId`.
        $idOnly = $this->updateOAuth2('gitlab', [
            'applicationId' => 'gitlab-rotated-app',
        ]);
        $this->assertSame(200, $idOnly['headers']['status-code']);
        $this->assertSame('gitlab-rotated-app', $idOnly['body']['applicationId']);
        $this->assertSame('https://merge.gitlab.com', $idOnly['body']['endpoint']);

        // Cleanup
        $this->updateOAuth2('gitlab', [
            'applicationId' => '',
            'secret' => '',
            'endpoint' => '',
            'enabled' => false,
        ]);
    }

    public function testUpdateOAuth2GitlabEnableAndReadBack(): void
    {
        $update = $this->updateOAuth2('gitlab', [
            'applicationId' => 'gitlab-enable-app',
            'secret' => 'gitlab-enable-secret',
            'endpoint' => 'https://enable.gitlab.com',
            'enabled' => true,
        ]);

        $this->assertSame(200, $update['headers']['status-code']);
        $this->assertTrue($update['body']['enabled']);

        // GET must hide `secret` while keeping applicationId and endpoint.
        $get = $this->getOAuth2Provider('gitlab');
        $this->assertSame(200, $get['headers']['status-code']);
        $this->assertTrue($get['body']['enabled']);
        $this->assertSame('gitlab-enable-app', $get['body']['applicationId']);
        $this->assertSame('https://enable.gitlab.com', $get['body']['endpoint']);
        $this->assertSame('', $get['body']['secret']);

        // Cleanup
        $this->updateOAuth2('gitlab', [
            'applicationId' => '',
            'secret' => '',
            'endpoint' => '',
            'enabled' => false,
        ]);
    }

    public function testUpdateOAuth2GitlabEndpointAcceptsEmpty(): void
    {
        // The `endpoint` validator is `Nullable(URL(allowEmpty: true))`. Passing
        // `''` must clear the stored value rather than 400 on URL validation.
        $this->updateOAuth2('gitlab', [
            'applicationId' => 'gitlab-clear-app',
            'secret' => 'gitlab-clear-secret',
            'endpoint' => 'https://before.gitlab.com',
            'enabled' => false,
        ]);

        $response = $this->updateOAuth2('gitlab', [
            'endpoint' => '',
        ]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('', $response['body']['endpoint']);

        // Cleanup
        $this->updateOAuth2('gitlab', [
            'applicationId' => '',
            'secret' => '',
            'endpoint' => '',
            'enabled' => false,
        ]);
    }

    // =========================================================================
    // Update OIDC (clientId + secret + wellKnownURL or 3 discovery URLs)
    // =========================================================================

    public function testUpdateOAuth2OidcWithWellKnown(): void
    {
        $response = $this->updateOAuth2('oidc', [
            'clientId' => 'oidc-client',
            'clientSecret' => 'oidc-secret',
            'wellKnownURL' => 'https://idp.example.com/.well-known/openid-configuration',
            'enabled' => false,
        ]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('https://idp.example.com/.well-known/openid-configuration', $response['body']['wellKnownURL']);
        $this->assertArrayHasKey('authorizationURL', $response['body']);
        $this->assertArrayHasKey('tokenUrl', $response['body']);
        $this->assertArrayHasKey('userInfoUrl', $response['body']);

        // Cleanup
        $this->updateOAuth2('oidc', [
            'clientId' => '',
            'clientSecret' => '',
            'wellKnownURL' => '',
            'authorizationURL' => '',
            'tokenUrl' => '',
            'userInfoUrl' => '',
            'enabled' => false,
        ]);
    }

    public function testUpdateOAuth2OidcWithDiscoveryURLs(): void
    {
        $response = $this->updateOAuth2('oidc', [
            'clientId' => 'oidc-discovery',
            'clientSecret' => 'oidc-discovery-secret',
            'authorizationURL' => 'https://idp.example.com/oauth2/authorize',
            'tokenUrl' => 'https://idp.example.com/oauth2/token',
            'userInfoUrl' => 'https://idp.example.com/oauth2/userinfo',
            'enabled' => false,
        ]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('https://idp.example.com/oauth2/authorize', $response['body']['authorizationURL']);
        $this->assertSame('https://idp.example.com/oauth2/token', $response['body']['tokenUrl']);
        $this->assertSame('https://idp.example.com/oauth2/userinfo', $response['body']['userInfoUrl']);

        // Cleanup
        $this->updateOAuth2('oidc', [
            'clientId' => '',
            'clientSecret' => '',
            'wellKnownURL' => '',
            'authorizationURL' => '',
            'tokenUrl' => '',
            'userInfoUrl' => '',
            'enabled' => false,
        ]);
    }

    public function testUpdateOAuth2OidcEnableMissingURLs(): void
    {
        $this->updateOAuth2('oidc', [
            'clientId' => '',
            'clientSecret' => '',
            'wellKnownURL' => '',
            'authorizationURL' => '',
            'tokenUrl' => '',
            'userInfoUrl' => '',
            'enabled' => false,
        ]);

        $response = $this->updateOAuth2('oidc', [
            'clientId' => 'oidc-no-urls',
            'clientSecret' => 'oidc-no-urls',
            'enabled' => true,
        ]);

        $this->assertSame(400, $response['headers']['status-code']);
        $this->assertSame('general_argument_invalid', $response['body']['type']);

        // Cleanup
        $this->updateOAuth2('oidc', [
            'clientId' => '',
            'clientSecret' => '',
            'enabled' => false,
        ]);
    }

    public function testUpdateOAuth2OidcEnablePartialDiscoveryFails(): void
    {
        // Only authorization+token, missing userInfo — must fail to enable.
        $this->updateOAuth2('oidc', [
            'clientId' => '',
            'clientSecret' => '',
            'wellKnownURL' => '',
            'authorizationURL' => '',
            'tokenUrl' => '',
            'userInfoUrl' => '',
            'enabled' => false,
        ]);

        $response = $this->updateOAuth2('oidc', [
            'clientId' => 'oidc-partial',
            'clientSecret' => 'oidc-partial-secret',
            'authorizationURL' => 'https://idp.example.com/oauth2/authorize',
            'tokenUrl' => 'https://idp.example.com/oauth2/token',
            'enabled' => true,
        ]);

        $this->assertSame(400, $response['headers']['status-code']);
        $this->assertSame('general_argument_invalid', $response['body']['type']);

        // Cleanup
        $this->updateOAuth2('oidc', [
            'clientId' => '',
            'clientSecret' => '',
            'wellKnownURL' => '',
            'authorizationURL' => '',
            'tokenUrl' => '',
            'userInfoUrl' => '',
            'enabled' => false,
        ]);
    }

    public function testUpdateOAuth2OidcEnableSucceedsWithWellKnown(): void
    {
        $update = $this->updateOAuth2('oidc', [
            'clientId' => 'oidc-enable-client',
            'clientSecret' => 'oidc-enable-secret',
            'wellKnownURL' => 'https://idp.example.com/.well-known/openid-configuration',
            'enabled' => true,
        ]);

        $this->assertSame(200, $update['headers']['status-code']);
        $this->assertTrue($update['body']['enabled']);

        // GET must hide clientSecret while keeping clientId and the URL.
        $get = $this->getOAuth2Provider('oidc');
        $this->assertSame(200, $get['headers']['status-code']);
        $this->assertTrue($get['body']['enabled']);
        $this->assertSame('oidc-enable-client', $get['body']['clientId']);
        $this->assertSame('https://idp.example.com/.well-known/openid-configuration', $get['body']['wellKnownURL']);
        $this->assertSame('', $get['body']['clientSecret']);

        // Cleanup
        $this->updateOAuth2('oidc', [
            'clientId' => '',
            'clientSecret' => '',
            'wellKnownURL' => '',
            'authorizationURL' => '',
            'tokenUrl' => '',
            'userInfoUrl' => '',
            'enabled' => false,
        ]);
    }

    public function testUpdateOAuth2OidcEnableInSeparateRequestWithWellKnown(): void
    {
        // Configure URLs first with `enabled: false`. Then enable in a SECOND
        // request that omits all URL fields. The merge-on-enable logic in
        // Oidc::handle() must see the previously-stored wellKnownEndpoint and
        // allow the toggle. This is the headline feature of the merge logic.
        $this->updateOAuth2('oidc', [
            'clientId' => 'oidc-split-wk-client',
            'clientSecret' => 'oidc-split-wk-secret',
            'wellKnownURL' => 'https://idp.example.com/.well-known/openid-configuration',
            'enabled' => false,
        ]);

        $enable = $this->updateOAuth2('oidc', [
            'enabled' => true,
        ]);
        $this->assertSame(200, $enable['headers']['status-code']);
        $this->assertTrue($enable['body']['enabled']);

        // Cleanup
        $this->updateOAuth2('oidc', [
            'clientId' => '',
            'clientSecret' => '',
            'wellKnownURL' => '',
            'authorizationURL' => '',
            'tokenUrl' => '',
            'userInfoUrl' => '',
            'enabled' => false,
        ]);
    }

    public function testUpdateOAuth2OidcEnableAcrossRequestsWithDiscoveryURLs(): void
    {
        // Reset to clean state — earlier tests in this section may have left
        // partial URL state when running in any order.
        $this->updateOAuth2('oidc', [
            'clientId' => '',
            'clientSecret' => '',
            'wellKnownURL' => '',
            'authorizationURL' => '',
            'tokenUrl' => '',
            'userInfoUrl' => '',
            'enabled' => false,
        ]);

        // Request 1: configure two of the three discovery URLs.
        $this->updateOAuth2('oidc', [
            'clientId' => 'oidc-split-discovery',
            'clientSecret' => 'oidc-split-discovery-secret',
            'authorizationURL' => 'https://idp.example.com/oauth2/authorize',
            'tokenUrl' => 'https://idp.example.com/oauth2/token',
            'enabled' => false,
        ]);

        // Request 2: send only the third URL plus enable=true. The merged
        // state must include the two stored URLs + the new one to satisfy
        // the all-three-discovery-URLs branch of the enable check.
        $enable = $this->updateOAuth2('oidc', [
            'userInfoUrl' => 'https://idp.example.com/oauth2/userinfo',
            'enabled' => true,
        ]);
        $this->assertSame(200, $enable['headers']['status-code']);
        $this->assertTrue($enable['body']['enabled']);

        // Confirm all three URLs ended up persisted (merge wrote the new
        // userInfoUrl while preserving the previously stored two).
        $get = $this->getOAuth2Provider('oidc');
        $this->assertSame(200, $get['headers']['status-code']);
        $this->assertSame('https://idp.example.com/oauth2/authorize', $get['body']['authorizationURL']);
        $this->assertSame('https://idp.example.com/oauth2/token', $get['body']['tokenUrl']);
        $this->assertSame('https://idp.example.com/oauth2/userinfo', $get['body']['userInfoUrl']);

        // Cleanup
        $this->updateOAuth2('oidc', [
            'clientId' => '',
            'clientSecret' => '',
            'wellKnownURL' => '',
            'authorizationURL' => '',
            'tokenUrl' => '',
            'userInfoUrl' => '',
            'enabled' => false,
        ]);
    }

    public function testUpdateOAuth2OidcEnableFailsAfterClearingWellKnown(): void
    {
        // Seed wellKnownURL only (no discovery URLs).
        $this->updateOAuth2('oidc', [
            'clientId' => 'oidc-clear-then-enable',
            'clientSecret' => 'oidc-clear-then-enable-secret',
            'wellKnownURL' => 'https://idp.example.com/.well-known/openid-configuration',
            'authorizationURL' => '',
            'tokenUrl' => '',
            'userInfoUrl' => '',
            'enabled' => false,
        ]);

        // Clear wellKnownURL and try to enable in the same request. Merge
        // sees `wellKnown=''` (the cleared empty wins over the stored value
        // because the new value is non-null) and no discovery URLs → 400.
        // This is the inverse of testUpdateOAuth2OidcEnableInSeparateRequestWithWellKnown:
        // confirms the merge correctly *replaces* with empty rather than
        // falling back to the stored non-empty value.
        $response = $this->updateOAuth2('oidc', [
            'wellKnownURL' => '',
            'enabled' => true,
        ]);
        $this->assertSame(400, $response['headers']['status-code']);
        $this->assertSame('general_argument_invalid', $response['body']['type']);

        // Cleanup
        $this->updateOAuth2('oidc', [
            'clientId' => '',
            'clientSecret' => '',
            'wellKnownURL' => '',
            'authorizationURL' => '',
            'tokenUrl' => '',
            'userInfoUrl' => '',
            'enabled' => false,
        ]);
    }

    public function testUpdateOAuth2OidcSwitchModesWellKnownToDiscovery(): void
    {
        // Configure with wellKnownURL, then switch to the three-discovery-URL
        // mode in a single request: clear wellKnown, set the three URLs,
        // enable. Merge sees wellKnown='' AND all three discovery URLs set →
        // hasAllDiscovery branch passes.
        $this->updateOAuth2('oidc', [
            'clientId' => 'oidc-switch-client',
            'clientSecret' => 'oidc-switch-secret',
            'wellKnownURL' => 'https://idp.example.com/.well-known/openid-configuration',
            'enabled' => false,
        ]);

        $switch = $this->updateOAuth2('oidc', [
            'wellKnownURL' => '',
            'authorizationURL' => 'https://idp.example.com/oauth2/authorize',
            'tokenUrl' => 'https://idp.example.com/oauth2/token',
            'userInfoUrl' => 'https://idp.example.com/oauth2/userinfo',
            'enabled' => true,
        ]);
        $this->assertSame(200, $switch['headers']['status-code']);
        $this->assertTrue($switch['body']['enabled']);
        $this->assertSame('', $switch['body']['wellKnownURL']);
        $this->assertSame('https://idp.example.com/oauth2/authorize', $switch['body']['authorizationURL']);
        $this->assertSame('https://idp.example.com/oauth2/token', $switch['body']['tokenUrl']);
        $this->assertSame('https://idp.example.com/oauth2/userinfo', $switch['body']['userInfoUrl']);

        // Cleanup
        $this->updateOAuth2('oidc', [
            'clientId' => '',
            'clientSecret' => '',
            'wellKnownURL' => '',
            'authorizationURL' => '',
            'tokenUrl' => '',
            'userInfoUrl' => '',
            'enabled' => false,
        ]);
    }

    public function testUpdateOAuth2OidcURLsAcceptEmpty(): void
    {
        // All four URL fields use `Nullable(URL(allowEmpty: true))`. Passing `''`
        // for each must clear them rather than 400 on URL validation.
        $this->updateOAuth2('oidc', [
            'clientId' => 'oidc-clear-client',
            'clientSecret' => 'oidc-clear-secret',
            'wellKnownURL' => 'https://idp.example.com/.well-known/openid-configuration',
            'authorizationURL' => 'https://idp.example.com/oauth2/authorize',
            'tokenUrl' => 'https://idp.example.com/oauth2/token',
            'userInfoUrl' => 'https://idp.example.com/oauth2/userinfo',
            'enabled' => false,
        ]);

        $response = $this->updateOAuth2('oidc', [
            'wellKnownURL' => '',
            'authorizationURL' => '',
            'tokenUrl' => '',
            'userInfoUrl' => '',
        ]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('', $response['body']['wellKnownURL']);
        $this->assertSame('', $response['body']['authorizationURL']);
        $this->assertSame('', $response['body']['tokenUrl']);
        $this->assertSame('', $response['body']['userInfoUrl']);

        // Cleanup
        $this->updateOAuth2('oidc', [
            'clientId' => '',
            'clientSecret' => '',
            'enabled' => false,
        ]);
    }

    // =========================================================================
    // Update Okta (clientId + clientSecret + optional domain/authServer)
    // =========================================================================

    public function testUpdateOAuth2Okta(): void
    {
        $response = $this->updateOAuth2('okta', [
            'clientId' => '0oa00000000000000698',
            'clientSecret' => 'okta-secret',
            'domain' => 'trial-6400025.okta.com',
            'authorizationServerId' => 'aus000000000000000h7z',
            'enabled' => false,
        ]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('okta', $response['body']['$id']);
        $this->assertSame('0oa00000000000000698', $response['body']['clientId']);
        $this->assertSame('trial-6400025.okta.com', $response['body']['domain']);
        $this->assertSame('aus000000000000000h7z', $response['body']['authorizationServerId']);

        // Cleanup
        $this->updateOAuth2('okta', [
            'clientId' => '',
            'clientSecret' => '',
            'domain' => '',
            'authorizationServerId' => '',
            'enabled' => false,
        ]);
    }

    public function testUpdateOAuth2OktaInvalidDomain(): void
    {
        $response = $this->updateOAuth2('okta', [
            'clientId' => 'whatever',
            'clientSecret' => 'whatever',
            'domain' => 'https://trial-6400025.okta.com/',
        ]);

        $this->assertSame(400, $response['headers']['status-code']);
        $this->assertSame('general_argument_invalid', $response['body']['type']);
    }

    public function testUpdateOAuth2OktaEnableRequiresDomain(): void
    {
        $this->updateOAuth2('okta', [
            'clientId' => '',
            'clientSecret' => '',
            'domain' => '',
            'authorizationServerId' => '',
            'enabled' => false,
        ]);

        $response = $this->updateOAuth2('okta', [
            'clientId' => 'okta-no-domain',
            'clientSecret' => 'okta-no-domain-secret',
            'enabled' => true,
        ]);

        $this->assertSame(400, $response['headers']['status-code']);
        $this->assertSame('general_argument_invalid', $response['body']['type']);

        // Cleanup
        $this->updateOAuth2('okta', [
            'clientId' => '',
            'clientSecret' => '',
            'enabled' => false,
        ]);
    }

    public function testUpdateOAuth2OktaEnableSucceedsWithDomain(): void
    {
        $update = $this->updateOAuth2('okta', [
            'clientId' => 'okta-enable-client',
            'clientSecret' => 'okta-enable-secret',
            'domain' => 'enable.okta.com',
            'authorizationServerId' => 'aus000000000000000h7z',
            'enabled' => true,
        ]);

        $this->assertSame(200, $update['headers']['status-code']);
        $this->assertTrue($update['body']['enabled']);

        // GET must hide clientSecret while keeping clientId, domain and authServerId.
        $get = $this->getOAuth2Provider('okta');
        $this->assertSame(200, $get['headers']['status-code']);
        $this->assertTrue($get['body']['enabled']);
        $this->assertSame('okta-enable-client', $get['body']['clientId']);
        $this->assertSame('enable.okta.com', $get['body']['domain']);
        $this->assertSame('aus000000000000000h7z', $get['body']['authorizationServerId']);
        $this->assertSame('', $get['body']['clientSecret']);

        // Cleanup
        $this->updateOAuth2('okta', [
            'clientId' => '',
            'clientSecret' => '',
            'domain' => '',
            'authorizationServerId' => '',
            'enabled' => false,
        ]);
    }

    public function testUpdateOAuth2OktaPartialPreservesEachField(): void
    {
        // Okta has no field-by-field partial test in the existing suite. Cover
        // each of `domain`, `authorizationServerId`, and `clientSecret` being
        // patched alone — all three live in the same JSON blob.
        $this->updateOAuth2('okta', [
            'clientId' => 'okta-merge-client',
            'clientSecret' => 'okta-merge-secret',
            'domain' => 'merge.okta.com',
            'authorizationServerId' => 'aus000000000000merge',
            'enabled' => false,
        ]);

        // Patch only `domain` — others must survive.
        $domainOnly = $this->updateOAuth2('okta', [
            'domain' => 'rotated.okta.com',
        ]);
        $this->assertSame(200, $domainOnly['headers']['status-code']);
        $this->assertSame('rotated.okta.com', $domainOnly['body']['domain']);
        $this->assertSame('okta-merge-client', $domainOnly['body']['clientId']);
        $this->assertSame('aus000000000000merge', $domainOnly['body']['authorizationServerId']);

        // Patch only `authorizationServerId`.
        $authServerOnly = $this->updateOAuth2('okta', [
            'authorizationServerId' => 'aus000000000rotated00',
        ]);
        $this->assertSame(200, $authServerOnly['headers']['status-code']);
        $this->assertSame('rotated.okta.com', $authServerOnly['body']['domain']);
        $this->assertSame('aus000000000rotated00', $authServerOnly['body']['authorizationServerId']);

        // Patch only `clientSecret` — domain and authServerId in the JSON blob
        // must survive. Confirm the rotated secret persisted by enabling.
        $secretOnly = $this->updateOAuth2('okta', [
            'clientSecret' => 'okta-rotated-secret',
        ]);
        $this->assertSame(200, $secretOnly['headers']['status-code']);
        $this->assertSame('rotated.okta.com', $secretOnly['body']['domain']);
        $this->assertSame('aus000000000rotated00', $secretOnly['body']['authorizationServerId']);

        $enable = $this->updateOAuth2('okta', ['enabled' => true]);
        $this->assertSame(200, $enable['headers']['status-code']);
        $this->assertTrue($enable['body']['enabled']);

        // Cleanup
        $this->updateOAuth2('okta', [
            'clientId' => '',
            'clientSecret' => '',
            'domain' => '',
            'authorizationServerId' => '',
            'enabled' => false,
        ]);
    }

    public function testUpdateOAuth2OktaAuthServerIdAcceptsEmpty(): void
    {
        // `authorizationServerId` is `Nullable(Text(256, 0))`. Passing `''`
        // must clear the stored value while leaving the rest of the JSON blob
        // (clientSecret, oktaDomain) untouched.
        $this->updateOAuth2('okta', [
            'clientId' => 'okta-clear-auth-server',
            'clientSecret' => 'okta-clear-auth-server-secret',
            'domain' => 'authserver.okta.com',
            'authorizationServerId' => 'aus0000000000beforeauth',
            'enabled' => false,
        ]);

        $response = $this->updateOAuth2('okta', [
            'authorizationServerId' => '',
        ]);
        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('', $response['body']['authorizationServerId']);
        // domain (also stored in the JSON blob) must NOT have been wiped.
        $this->assertSame('authserver.okta.com', $response['body']['domain']);

        // Cleanup
        $this->updateOAuth2('okta', [
            'clientId' => '',
            'clientSecret' => '',
            'domain' => '',
            'authorizationServerId' => '',
            'enabled' => false,
        ]);
    }

    public function testUpdateOAuth2OktaDomainAcceptsEmpty(): void
    {
        // The `domain` validator is `Nullable(Domain(allowEmpty: true))`. Passing
        // `''` must clear the stored value rather than 400 on Domain validation.
        $this->updateOAuth2('okta', [
            'clientId' => 'okta-clear-client',
            'clientSecret' => 'okta-clear-secret',
            'domain' => 'before.okta.com',
            'enabled' => false,
        ]);

        $response = $this->updateOAuth2('okta', [
            'domain' => '',
        ]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('', $response['body']['domain']);

        // Cleanup
        $this->updateOAuth2('okta', [
            'clientId' => '',
            'clientSecret' => '',
            'domain' => '',
            'enabled' => false,
        ]);
    }

    // =========================================================================
    // Update Dropbox (custom param names: appKey + appSecret)
    // =========================================================================

    public function testUpdateOAuth2DropboxFieldNames(): void
    {
        $response = $this->updateOAuth2('dropbox', [
            'appKey' => 'jl000000000009t',
            'appSecret' => 'g200000000000vw',
            'enabled' => false,
        ]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('dropbox', $response['body']['$id']);
        $this->assertSame('jl000000000009t', $response['body']['appKey']);
        $this->assertArrayHasKey('appSecret', $response['body']);
        $this->assertArrayNotHasKey('clientId', $response['body']);
        $this->assertArrayNotHasKey('clientSecret', $response['body']);

        // GET enforces write-only on the secret regardless of the custom name.
        $get = $this->getOAuth2Provider('dropbox');
        $this->assertSame(200, $get['headers']['status-code']);
        $this->assertSame('jl000000000009t', $get['body']['appKey']);
        $this->assertSame('', $get['body']['appSecret']);

        // Cleanup
        $this->updateOAuth2('dropbox', [
            'appKey' => '',
            'appSecret' => '',
            'enabled' => false,
        ]);
    }

    public function testUpdateOAuth2DropboxPartial(): void
    {
        // Seed both fields, then patch only `appKey` and verify `appSecret`
        // persists by enabling — Dropbox has no verifyCredentials() hook, so
        // enabling succeeds purely from local state.
        $this->updateOAuth2('dropbox', [
            'appKey' => 'dropbox-seed-key',
            'appSecret' => 'dropbox-seed-secret',
            'enabled' => false,
        ]);

        $response = $this->updateOAuth2('dropbox', [
            'appKey' => 'dropbox-updated-key',
        ]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('dropbox-updated-key', $response['body']['appKey']);

        $enable = $this->updateOAuth2('dropbox', [
            'enabled' => true,
        ]);
        $this->assertSame(200, $enable['headers']['status-code']);
        $this->assertSame(true, $enable['body']['enabled']);

        // Cleanup
        $this->updateOAuth2('dropbox', [
            'appKey' => '',
            'appSecret' => '',
            'enabled' => false,
        ]);
    }

    public function testUpdateOAuth2DropboxEnableAndReadBack(): void
    {
        $update = $this->updateOAuth2('dropbox', [
            'appKey' => 'dropbox-enable-key',
            'appSecret' => 'dropbox-enable-secret',
            'enabled' => true,
        ]);

        $this->assertSame(200, $update['headers']['status-code']);
        $this->assertTrue($update['body']['enabled']);

        // GET must hide `appSecret` while keeping `appKey`.
        $get = $this->getOAuth2Provider('dropbox');
        $this->assertSame(200, $get['headers']['status-code']);
        $this->assertTrue($get['body']['enabled']);
        $this->assertSame('dropbox-enable-key', $get['body']['appKey']);
        $this->assertSame('', $get['body']['appSecret']);

        // Cleanup
        $this->updateOAuth2('dropbox', [
            'appKey' => '',
            'appSecret' => '',
            'enabled' => false,
        ]);
    }

    // =========================================================================
    // Update Paypal Sandbox (inherits from Paypal — independent provider ID)
    // =========================================================================

    public function testUpdateOAuth2PaypalSandbox(): void
    {
        $response = $this->updateOAuth2('paypalSandbox', [
            'clientId' => 'paypal-sandbox-client',
            'clientSecret' => 'paypal-sandbox-secret',
            'enabled' => false,
        ]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('paypalSandbox', $response['body']['$id']);
        $this->assertSame('paypal-sandbox-client', $response['body']['clientId']);

        // Sandbox is independent of the regular paypal entry.
        $regular = $this->getOAuth2Provider('paypal');
        $this->assertSame(200, $regular['headers']['status-code']);
        $this->assertSame('paypal', $regular['body']['$id']);
        $this->assertNotSame('paypal-sandbox-client', $regular['body']['clientId']);

        // Cleanup
        $this->updateOAuth2('paypalSandbox', [
            'clientId' => '',
            'clientSecret' => '',
            'enabled' => false,
        ]);
    }

    public function testUpdateOAuth2PaypalSandboxResponseModel(): void
    {
        // PaypalSandbox inherits from Paypal: param/response field is
        // `secretKey` instead of `clientSecret`. A regression that adds the
        // default `clientSecret` to the response model would leak the
        // unwritten field; pin its absence on both PATCH and GET.
        $update = $this->updateOAuth2('paypalSandbox', [
            'clientId' => 'paypal-sandbox-shape',
            'secretKey' => 'paypal-sandbox-shape-secret',
            'enabled' => false,
        ]);
        $this->assertSame(200, $update['headers']['status-code']);
        $this->assertArrayHasKey('secretKey', $update['body']);
        $this->assertArrayNotHasKey('clientSecret', $update['body']);

        $get = $this->getOAuth2Provider('paypalSandbox');
        $this->assertSame(200, $get['headers']['status-code']);
        $this->assertArrayHasKey('secretKey', $get['body']);
        $this->assertArrayNotHasKey('clientSecret', $get['body']);

        // Cleanup
        $this->updateOAuth2('paypalSandbox', [
            'clientId' => '',
            'secretKey' => '',
            'enabled' => false,
        ]);
    }

    public function testUpdateOAuth2PaypalDoesNotAffectSandbox(): void
    {
        // Reverse direction: writing to regular paypal must leave sandbox state intact.
        $this->updateOAuth2('paypalSandbox', [
            'clientId' => 'sandbox-untouched',
            'clientSecret' => 'sandbox-secret',
            'enabled' => false,
        ]);

        $this->updateOAuth2('paypal', [
            'clientId' => 'paypal-prod',
            'secretKey' => 'paypal-prod-secret',
            'enabled' => false,
        ]);

        $sandbox = $this->getOAuth2Provider('paypalSandbox');
        $this->assertSame(200, $sandbox['headers']['status-code']);
        $this->assertSame('sandbox-untouched', $sandbox['body']['clientId']);

        // Cleanup both
        $this->updateOAuth2('paypal', [
            'clientId' => '',
            'secretKey' => '',
            'enabled' => false,
        ]);
        $this->updateOAuth2('paypalSandbox', [
            'clientId' => '',
            'clientSecret' => '',
            'enabled' => false,
        ]);
    }

    // =========================================================================
    // Update Tradeshift Sandbox (inherits from Tradeshift — independent provider ID)
    // =========================================================================

    public function testUpdateOAuth2TradeshiftBox(): void
    {
        $response = $this->updateOAuth2('tradeshiftBox', [
            'oauth2ClientId' => 'tradeshift-sandbox-client',
            'oauth2ClientSecret' => 'tradeshift-sandbox-secret',
            'enabled' => false,
        ]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertSame('tradeshiftBox', $response['body']['$id']);
        $this->assertSame('tradeshift-sandbox-client', $response['body']['oauth2ClientId']);

        // Sandbox is independent of the regular tradeshift entry.
        $regular = $this->getOAuth2Provider('tradeshift');
        $this->assertSame(200, $regular['headers']['status-code']);
        $this->assertSame('tradeshift', $regular['body']['$id']);
        $this->assertNotSame('tradeshift-sandbox-client', $regular['body']['oauth2ClientId']);

        // Cleanup
        $this->updateOAuth2('tradeshiftBox', [
            'oauth2ClientId' => '',
            'oauth2ClientSecret' => '',
            'enabled' => false,
        ]);
    }

    public function testUpdateOAuth2TradeshiftBoxResponseModel(): void
    {
        // TradeshiftSandbox inherits from Tradeshift: both clientId AND
        // clientSecret are renamed (oauth2ClientId / oauth2ClientSecret).
        // Pin that the default field names are absent from PATCH and GET
        // responses so a stray addition to the response model is caught.
        $update = $this->updateOAuth2('tradeshiftBox', [
            'oauth2ClientId' => 'tradeshift-box-shape',
            'oauth2ClientSecret' => 'tradeshift-box-shape-secret',
            'enabled' => false,
        ]);
        $this->assertSame(200, $update['headers']['status-code']);
        $this->assertArrayHasKey('oauth2ClientId', $update['body']);
        $this->assertArrayHasKey('oauth2ClientSecret', $update['body']);
        $this->assertArrayNotHasKey('clientId', $update['body']);
        $this->assertArrayNotHasKey('clientSecret', $update['body']);

        $get = $this->getOAuth2Provider('tradeshiftBox');
        $this->assertSame(200, $get['headers']['status-code']);
        $this->assertArrayHasKey('oauth2ClientId', $get['body']);
        $this->assertArrayHasKey('oauth2ClientSecret', $get['body']);
        $this->assertArrayNotHasKey('clientId', $get['body']);
        $this->assertArrayNotHasKey('clientSecret', $get['body']);

        // Cleanup
        $this->updateOAuth2('tradeshiftBox', [
            'oauth2ClientId' => '',
            'oauth2ClientSecret' => '',
            'enabled' => false,
        ]);
    }

    public function testUpdateOAuth2TradeshiftDoesNotAffectSandbox(): void
    {
        // Reverse direction: writing to regular tradeshift must not touch sandbox state.
        $this->updateOAuth2('tradeshiftBox', [
            'oauth2ClientId' => 'tradeshift-sandbox-untouched',
            'oauth2ClientSecret' => 'tradeshift-sandbox-secret',
            'enabled' => false,
        ]);

        $this->updateOAuth2('tradeshift', [
            'oauth2ClientId' => 'tradeshift-prod',
            'oauth2ClientSecret' => 'tradeshift-prod-secret',
            'enabled' => false,
        ]);

        $sandbox = $this->getOAuth2Provider('tradeshiftBox');
        $this->assertSame(200, $sandbox['headers']['status-code']);
        $this->assertSame('tradeshift-sandbox-untouched', $sandbox['body']['oauth2ClientId']);

        // Cleanup both
        $this->updateOAuth2('tradeshift', [
            'oauth2ClientId' => '',
            'oauth2ClientSecret' => '',
            'enabled' => false,
        ]);
        $this->updateOAuth2('tradeshiftBox', [
            'oauth2ClientId' => '',
            'oauth2ClientSecret' => '',
            'enabled' => false,
        ]);
    }

    // =========================================================================
    // Smoke test: every plain (clientId + clientSecret) provider
    //
    // Ensures each provider's Update endpoint is wired up correctly: routing,
    // provider class, response model and `$id`. Custom-shaped providers
    // (apple, auth0, authentik, fusionauth, gitlab, keycloak, microsoft, oidc,
    // okta, dropbox) and sandboxes (paypalSandbox, tradeshiftSandbox) have
    // dedicated tests above.
    // Github is excluded because its `verifyCredentials()` hook is exercised
    // separately.
    // =========================================================================

    /**
     * Provider, ID-field, secret-field. Many providers rename one or both of
     * the two credential params (`clientId`/`clientSecret`) to match the
     * upstream provider's terminology, so the smoke test parameterises both.
     *
     * @return array<string, array<string>>
     */
    public static function plainProviders(): array
    {
        return [
            'discord' => ['discord', 'clientId', 'clientSecret'],
            'figma' => ['figma', 'clientId', 'clientSecret'],
            'dailymotion' => ['dailymotion', 'apiKey', 'apiSecret'],
            'bitbucket' => ['bitbucket', 'key', 'secret'],
            'bitly' => ['bitly', 'clientId', 'clientSecret'],
            'box' => ['box', 'clientId', 'clientSecret'],
            'autodesk' => ['autodesk', 'clientId', 'clientSecret'],
            'google' => ['google', 'clientId', 'clientSecret'],
            'zoom' => ['zoom', 'clientId', 'clientSecret'],
            'zoho' => ['zoho', 'clientId', 'clientSecret'],
            'yandex' => ['yandex', 'clientId', 'clientSecret'],
            'x' => ['x', 'customerKey', 'secretKey'],
            'wordpress' => ['wordpress', 'clientId', 'clientSecret'],
            'twitch' => ['twitch', 'clientId', 'clientSecret'],
            'stripe' => ['stripe', 'clientId', 'apiSecretKey'],
            'spotify' => ['spotify', 'clientId', 'clientSecret'],
            'slack' => ['slack', 'clientId', 'clientSecret'],
            'podio' => ['podio', 'clientId', 'clientSecret'],
            'notion' => ['notion', 'oauthClientId', 'oauthClientSecret'],
            'salesforce' => ['salesforce', 'customerKey', 'customerSecret'],
            'yahoo' => ['yahoo', 'clientId', 'clientSecret'],
            'linkedin' => ['linkedin', 'clientId', 'primaryClientSecret'],
            'disqus' => ['disqus', 'publicKey', 'secretKey'],
            'etsy' => ['etsy', 'keyString', 'sharedSecret'],
            'facebook' => ['facebook', 'appId', 'appSecret'],
            'tradeshift' => ['tradeshift', 'oauth2ClientId', 'oauth2ClientSecret'],
            'paypal' => ['paypal', 'clientId', 'secretKey'],
            'kick' => ['kick', 'clientId', 'clientSecret'],
        ];
    }

    #[DataProvider('plainProviders')]
    public function testUpdateOAuth2PlainProvider(string $providerId, string $idField, string $secretField): void
    {
        $clientId = $providerId . '-smoke-client';
        $clientSecret = $providerId . '-smoke-secret';

        $update = $this->updateOAuth2($providerId, [
            $idField => $clientId,
            $secretField => $clientSecret,
            'enabled' => false,
        ]);

        $this->assertSame(200, $update['headers']['status-code']);
        $this->assertSame($providerId, $update['body']['$id']);
        $this->assertSame($clientId, $update['body'][$idField]);
        $this->assertFalse($update['body']['enabled']);

        // GET round-trip — confirms the value actually persisted (catches a
        // PATCH that only echoes input without writing) and that the secret
        // is hidden on read.
        $get = $this->getOAuth2Provider($providerId);
        $this->assertSame(200, $get['headers']['status-code']);
        $this->assertSame($providerId, $get['body']['$id']);
        $this->assertSame($clientId, $get['body'][$idField]);
        $this->assertSame('', $get['body'][$secretField]);
        $this->assertFalse($get['body']['enabled']);

        // Cleanup
        $this->updateOAuth2($providerId, [
            $idField => '',
            $secretField => '',
            'enabled' => false,
        ]);
    }

    /**
     * For providers that rename `clientId` / `clientSecret` to a custom field
     * (e.g. `apiKey`/`apiSecret`, `customerKey`/`secretKey`, `oauthClientId`),
     * the renamed field replaces the default — the response model must NOT
     * also expose the default name. Catches a regression where adding a
     * custom param name forgets to remove the default from the response.
     */
    #[DataProvider('plainProviders')]
    public function testUpdateOAuth2PlainProviderResponseDoesNotLeakDefaultNames(string $providerId, string $idField, string $secretField): void
    {
        if ($idField === 'clientId' && $secretField === 'clientSecret') {
            // Default-named provider — nothing to leak. Avoids a no-op assertion.
            $this->markTestSkipped("{$providerId} uses default field names.");
        }

        $update = $this->updateOAuth2($providerId, [
            $idField => $providerId . '-leak-check-id',
            $secretField => $providerId . '-leak-check-secret',
            'enabled' => false,
        ]);
        $this->assertSame(200, $update['headers']['status-code']);

        if ($idField !== 'clientId') {
            $this->assertArrayNotHasKey('clientId', $update['body'], "PATCH response for {$providerId} leaks default `clientId` despite using `{$idField}`.");
        }
        if ($secretField !== 'clientSecret') {
            $this->assertArrayNotHasKey('clientSecret', $update['body'], "PATCH response for {$providerId} leaks default `clientSecret` despite using `{$secretField}`.");
        }

        $get = $this->getOAuth2Provider($providerId);
        $this->assertSame(200, $get['headers']['status-code']);
        if ($idField !== 'clientId') {
            $this->assertArrayNotHasKey('clientId', $get['body'], "GET response for {$providerId} leaks default `clientId` despite using `{$idField}`.");
        }
        if ($secretField !== 'clientSecret') {
            $this->assertArrayNotHasKey('clientSecret', $get['body'], "GET response for {$providerId} leaks default `clientSecret` despite using `{$secretField}`.");
        }

        // Cleanup
        $this->updateOAuth2($providerId, [
            $idField => '',
            $secretField => '',
            'enabled' => false,
        ]);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * @param array<string, mixed> $params
     */
    protected function updateOAuth2(string $provider, array $params, bool $authenticated = true): mixed
    {
        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ];

        if ($authenticated) {
            $headers = \array_merge($headers, $this->getHeaders());
        }

        return $this->client->call(
            Client::METHOD_PATCH,
            '/project/oauth2/' . $provider,
            $headers,
            $params,
        );
    }

    protected function getOAuth2Provider(string $provider, bool $authenticated = true): mixed
    {
        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ];

        if ($authenticated) {
            $headers = \array_merge($headers, $this->getHeaders());
        }

        return $this->client->call(
            Client::METHOD_GET,
            '/project/oauth2/' . $provider,
            $headers,
        );
    }

    protected function listOAuth2Providers(?array $queries = null, ?bool $total = null, bool $authenticated = true): mixed
    {
        $params = [];

        if ($queries !== null) {
            $params['queries'] = $queries;
        }

        if ($total !== null) {
            $params['total'] = $total;
        }

        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ];

        if ($authenticated) {
            $headers = \array_merge($headers, $this->getHeaders());
        }

        return $this->client->call(
            Client::METHOD_GET,
            '/project/oauth2',
            $headers,
            $params,
        );
    }
}

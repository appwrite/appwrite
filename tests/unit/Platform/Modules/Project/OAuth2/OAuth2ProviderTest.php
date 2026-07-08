<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Modules\Project\OAuth2;

use Appwrite\Event\Event as QueueEvent;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Apple\Update as AppleUpdate;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Auth0\Update as Auth0Update;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Authentik\Update as AuthentikUpdate;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Base;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Dropbox\Update as DropboxUpdate;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\FusionAuth\Update as FusionAuthUpdate;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Gitlab\Update as GitlabUpdate;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Google\Update as GoogleUpdate;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Keycloak\Update as KeycloakUpdate;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Microsoft\Update as MicrosoftUpdate;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Oidc\Update as OidcUpdate;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Okta\Update as OktaUpdate;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\PaypalSandbox\Update as PaypalSandboxUpdate;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\TradeshiftSandbox\Update as TradeshiftSandboxUpdate;
use Appwrite\Utopia\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;

final class OAuth2ProviderTest extends TestCase
{
    public function testProviderRegistryIsExplicitAndComplete(): void
    {
        $actions = Base::getProviderActions();
        $ids = \array_keys($actions);
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

        $this->assertSame($expected, $ids);
        $this->assertArrayNotHasKey('mock', $actions);
        $this->assertArrayNotHasKey('mock-unverified', $actions);
        $this->assertSame(PaypalSandboxUpdate::class, $actions['paypalSandbox']);
        $this->assertSame(TradeshiftSandboxUpdate::class, $actions['tradeshiftBox']);

        foreach ($actions as $providerId => $actionClass) {
            $this->assertSame($providerId, $actionClass::getProviderId());
        }
    }

    /**
     * @param class-string<Base> $actionClass
     * @param array<int, string> $expectedIds
     */
    #[DataProvider('providerParameters')]
    public function testProviderParameterMetadata(string $actionClass, array $expectedIds): void
    {
        $parameters = $actionClass::getParameters();
        $ids = \array_column($parameters, '$id');

        $this->assertSame($expectedIds, $ids);

        foreach ($parameters as $parameter) {
            $this->assertArrayHasKey('$id', $parameter);
            $this->assertArrayHasKey('name', $parameter);
            $this->assertArrayHasKey('example', $parameter);
            $this->assertArrayHasKey('hint', $parameter);
            $this->assertNotSame('', $parameter['$id']);
            $this->assertNotSame('', $parameter['name']);
        }
    }

    public static function providerParameters(): \Iterator
    {
        yield 'default provider' => [Base::getProviderActions()['amazon'], ['clientId', 'clientSecret']];
        yield 'custom id and secret names' => [DropboxUpdate::class, ['appKey', 'appSecret']];
        yield 'apple' => [AppleUpdate::class, ['serviceId', 'keyId', 'teamId', 'p8File']];
        yield 'auth0' => [Auth0Update::class, ['clientId', 'clientSecret', 'endpoint']];
        yield 'authentik' => [AuthentikUpdate::class, ['clientId', 'clientSecret', 'endpoint']];
        yield 'fusionauth' => [FusionAuthUpdate::class, ['clientId', 'clientSecret', 'endpoint']];
        yield 'gitlab' => [GitlabUpdate::class, ['applicationId', 'secret', 'endpoint']];
        yield 'google' => [GoogleUpdate::class, ['clientId', 'clientSecret', 'prompt']];
        yield 'keycloak' => [KeycloakUpdate::class, ['clientId', 'clientSecret', 'endpoint', 'realmName']];
        yield 'microsoft' => [MicrosoftUpdate::class, ['applicationId', 'applicationSecret', 'tenant']];
        yield 'oidc' => [OidcUpdate::class, ['clientId', 'clientSecret', 'wellKnownURL', 'authorizationURL', 'tokenURL', 'userInfoURL', 'prompt', 'maxAge']];
        yield 'okta' => [OktaUpdate::class, ['clientId', 'clientSecret', 'domain', 'authorizationServerId']];
    }

    /**
     * @param class-string<Base> $actionClass
     */
    #[DataProvider('plainProviders')]
    public function testPlainProviderReadResponsesUseProviderSpecificFieldNames(
        string $providerId,
        string $actionClass,
        string $idField,
        string $secretField
    ): void {
        $action = new $actionClass();
        $response = $action->buildReadResponse($this->project([
            $providerId . 'Enabled' => true,
            $providerId . 'Appid' => 'stored-id',
            $providerId . 'Secret' => 'stored-secret',
        ]));

        $this->assertSame($providerId, $response->getAttribute('$id'));
        $this->assertTrue($response->getAttribute('enabled'));
        $this->assertSame('stored-id', $response->getAttribute($idField));
        $this->assertSame('', $response->getAttribute($secretField));

        if ($idField !== 'clientId') {
            $this->assertFalse($response->offsetExists('clientId'));
        }
        if ($secretField !== 'clientSecret') {
            $this->assertFalse($response->offsetExists('clientSecret'));
        }
    }

    public static function plainProviders(): \Iterator
    {
        $actions = Base::getProviderActions();
        yield 'amazon' => ['amazon', $actions['amazon'], 'clientId', 'clientSecret'];
        yield 'dailymotion' => ['dailymotion', $actions['dailymotion'], 'apiKey', 'apiSecret'];
        yield 'bitbucket' => ['bitbucket', $actions['bitbucket'], 'key', 'secret'];
        yield 'dropbox' => ['dropbox', DropboxUpdate::class, 'appKey', 'appSecret'];
        yield 'etsy' => ['etsy', $actions['etsy'], 'keyString', 'sharedSecret'];
        yield 'facebook' => ['facebook', $actions['facebook'], 'appId', 'appSecret'];
        yield 'notion' => ['notion', $actions['notion'], 'oauthClientId', 'oauthClientSecret'];
        yield 'paypal' => ['paypal', $actions['paypal'], 'clientId', 'secretKey'];
        yield 'salesforce' => ['salesforce', $actions['salesforce'], 'customerKey', 'customerSecret'];
        yield 'stripe' => ['stripe', $actions['stripe'], 'clientId', 'apiSecretKey'];
        yield 'tradeshift' => ['tradeshift', $actions['tradeshift'], 'oauth2ClientId', 'oauth2ClientSecret'];
        yield 'x' => ['x', $actions['x'], 'customerKey', 'secretKey'];
    }

    public function testAppleReadResponseHidesP8FileAndExposesNonSecretFields(): void
    {
        $response = (new AppleUpdate())->buildReadResponse($this->project([
            'appleEnabled' => true,
            'appleAppid' => 'ip.appwrite.app.web',
            'appleSecret' => \json_encode([
                'p8' => 'private-key',
                'keyID' => 'KEY123',
                'teamID' => 'TEAM123',
            ]),
        ]));

        $this->assertSame('apple', $response->getAttribute('$id'));
        $this->assertTrue($response->getAttribute('enabled'));
        $this->assertSame('ip.appwrite.app.web', $response->getAttribute('serviceId'));
        $this->assertSame('KEY123', $response->getAttribute('keyId'));
        $this->assertSame('TEAM123', $response->getAttribute('teamId'));
        $this->assertSame('', $response->getAttribute('p8File'));
        $this->assertFalse($response->offsetExists('clientId'));
        $this->assertFalse($response->offsetExists('clientSecret'));
    }

    /**
     * @param class-string<Base> $actionClass
     * @param array<string, mixed> $storedSecret
     * @param array<string, mixed> $expected
     */
    #[DataProvider('jsonBackedReadResponses')]
    public function testJsonBackedReadResponsesExposeOnlyNonSecretExtras(
        string $providerId,
        string $actionClass,
        array $storedSecret,
        array $expected
    ): void {
        $response = (new $actionClass())->buildReadResponse($this->project([
            $providerId . 'Enabled' => true,
            $providerId . 'Appid' => 'stored-id',
            $providerId . 'Secret' => \json_encode($storedSecret),
        ]));

        $this->assertSame($providerId, $response->getAttribute('$id'));
        $this->assertSame('stored-id', $response->getAttribute($actionClass::getClientIdParamName()));
        $this->assertSame('', $response->getAttribute($actionClass::getClientSecretParamName()));

        foreach ($expected as $key => $value) {
            $this->assertSame($value, $response->getAttribute($key));
        }
    }

    public static function jsonBackedReadResponses(): \Iterator
    {
        yield 'auth0' => ['auth0', Auth0Update::class, ['clientSecret' => 'secret', 'auth0Domain' => 'example.us.auth0.com'], ['endpoint' => 'example.us.auth0.com']];
        yield 'authentik' => ['authentik', AuthentikUpdate::class, ['clientSecret' => 'secret', 'authentikDomain' => 'example.authentik.com'], ['endpoint' => 'example.authentik.com']];
        yield 'fusionauth' => ['fusionauth', FusionAuthUpdate::class, ['clientSecret' => 'secret', 'fusionAuthDomain' => 'example.fusionauth.io'], ['endpoint' => 'example.fusionauth.io']];
        yield 'gitlab' => ['gitlab', GitlabUpdate::class, ['clientSecret' => 'secret', 'endpoint' => 'https://gitlab.example.com'], ['endpoint' => 'https://gitlab.example.com']];
        yield 'google' => ['google', GoogleUpdate::class, ['clientSecret' => 'secret', 'prompt' => ['select_account']], ['prompt' => ['select_account']]];
        yield 'keycloak' => ['keycloak', KeycloakUpdate::class, ['clientSecret' => 'secret', 'keycloakDomain' => 'keycloak.example.com', 'keycloakRealm' => 'appwrite-realm'], ['endpoint' => 'keycloak.example.com', 'realmName' => 'appwrite-realm']];
        yield 'microsoft' => ['microsoft', MicrosoftUpdate::class, ['clientSecret' => 'secret', 'tenantID' => 'common'], ['tenant' => 'common']];
        yield 'oidc' => ['oidc', OidcUpdate::class, ['clientSecret' => 'secret', 'wellKnownEndpoint' => 'https://idp.example/.well-known/openid-configuration', 'authorizationEndpoint' => '', 'tokenEndpoint' => '', 'userInfoEndpoint' => ''], ['wellKnownURL' => 'https://idp.example/.well-known/openid-configuration', 'authorizationURL' => '', 'tokenURL' => '', 'userInfoURL' => '']];
        yield 'okta' => ['okta', OktaUpdate::class, ['clientSecret' => 'secret', 'oktaDomain' => 'trial-6400025.okta.com', 'authorizationServerId' => 'aus123'], ['domain' => 'trial-6400025.okta.com', 'authorizationServerId' => 'aus123']];
    }

    public function testInvalidStoredJsonReturnsEmptyExtras(): void
    {
        $response = (new Auth0Update())->buildReadResponse($this->project([
            'auth0Enabled' => false,
            'auth0Appid' => 'stored-id',
            'auth0Secret' => '{not-json',
        ]));

        $this->assertSame('stored-id', $response->getAttribute('clientId'));
        $this->assertSame('', $response->getAttribute('clientSecret'));
        $this->assertSame('', $response->getAttribute('endpoint'));
    }

    public function testApplePartialUpdatesMergeStoredSecretFields(): void
    {
        $result = $this->runHandle(
            new AppleUpdate(),
            $this->project([
                'appleEnabled' => false,
                'appleAppid' => 'ip.appwrite.app.seed',
                'appleSecret' => \json_encode([
                    'p8' => 'old-p8',
                    'keyID' => 'OLDKEY',
                    'teamID' => 'OLDTEAM',
                ]),
            ]),
            [null, 'NEWKEY', null, null, false],
        );

        $stored = \json_decode($result['updatedProject']->getAttribute('oAuthProviders')['appleSecret'], true);
        $this->assertSame('ip.appwrite.app.seed', $result['updatedProject']->getAttribute('oAuthProviders')['appleAppid']);
        $this->assertSame('old-p8', $stored['p8']);
        $this->assertSame('NEWKEY', $stored['keyID']);
        $this->assertSame('OLDTEAM', $stored['teamID']);
        $this->assertSame('NEWKEY', $result['response']->getAttribute('keyId'));
        $this->assertSame('', $result['response']->getAttribute('p8File'));
    }

    /**
     * @param class-string<Base> $actionClass
     * @param array<string, mixed> $initialSecret
     * @param array<int, mixed> $args
     * @param array<string, mixed> $expectedSecret
     * @param array<string, mixed> $expectedResponse
     */
    #[DataProvider('partialJsonMergeScenarios')]
    public function testPartialJsonBackedUpdatesPreserveExistingFields(
        string $providerId,
        string $actionClass,
        array $initialSecret,
        array $args,
        array $expectedSecret,
        array $expectedResponse
    ): void {
        $result = $this->runHandle(
            new $actionClass(),
            $this->project([
                $providerId . 'Enabled' => false,
                $providerId . 'Appid' => 'seed-id',
                $providerId . 'Secret' => \json_encode($initialSecret),
            ]),
            $args,
        );

        $stored = \json_decode($result['updatedProject']->getAttribute('oAuthProviders')[$providerId . 'Secret'], true);
        foreach ($expectedSecret as $key => $value) {
            $this->assertSame($value, $stored[$key]);
        }

        foreach ($expectedResponse as $key => $value) {
            $this->assertSame($value, $result['response']->getAttribute($key));
        }
    }

    public static function partialJsonMergeScenarios(): \Iterator
    {
        yield 'auth0 endpoint only' => [
            'auth0',
            Auth0Update::class,
            ['clientSecret' => 'old-secret', 'auth0Domain' => 'old.us.auth0.com'],
            [null, null, 'new.us.auth0.com', false],
            ['clientSecret' => 'old-secret', 'auth0Domain' => 'new.us.auth0.com'],
            ['clientId' => 'seed-id', 'clientSecret' => '', 'endpoint' => 'new.us.auth0.com'],
        ];
        yield 'authentik client only' => [
            'authentik',
            AuthentikUpdate::class,
            ['clientSecret' => 'old-secret', 'authentikDomain' => 'old.authentik.com'],
            ['rotated-id', null, null, false],
            ['clientSecret' => 'old-secret', 'authentikDomain' => 'old.authentik.com'],
            ['clientId' => 'rotated-id', 'endpoint' => 'old.authentik.com'],
        ];
        yield 'fusionauth secret only' => [
            'fusionauth',
            FusionAuthUpdate::class,
            ['clientSecret' => 'old-secret', 'fusionAuthDomain' => 'old.fusionauth.io'],
            [null, 'new-secret', null, false],
            ['clientSecret' => 'new-secret', 'fusionAuthDomain' => 'old.fusionauth.io'],
            ['clientId' => 'seed-id', 'endpoint' => 'old.fusionauth.io'],
        ];
        yield 'gitlab endpoint only' => [
            'gitlab',
            GitlabUpdate::class,
            ['clientSecret' => 'old-secret', 'endpoint' => 'https://old.gitlab.example'],
            [null, null, 'https://new.gitlab.example', false],
            ['clientSecret' => 'old-secret', 'endpoint' => 'https://new.gitlab.example'],
            ['applicationId' => 'seed-id', 'endpoint' => 'https://new.gitlab.example'],
        ];
        yield 'keycloak realm only' => [
            'keycloak',
            KeycloakUpdate::class,
            ['clientSecret' => 'old-secret', 'keycloakDomain' => 'keycloak.example.com', 'keycloakRealm' => 'old-realm'],
            [null, null, null, 'new-realm', false],
            ['clientSecret' => 'old-secret', 'keycloakDomain' => 'keycloak.example.com', 'keycloakRealm' => 'new-realm'],
            ['clientId' => 'seed-id', 'endpoint' => 'keycloak.example.com', 'realmName' => 'new-realm'],
        ];
        yield 'microsoft tenant only' => [
            'microsoft',
            MicrosoftUpdate::class,
            ['clientSecret' => 'old-secret', 'tenantID' => 'organizations'],
            [null, null, 'common', false],
            ['clientSecret' => 'old-secret', 'tenantID' => 'common'],
            ['applicationId' => 'seed-id', 'tenant' => 'common'],
        ];
        yield 'okta authorization server only' => [
            'okta',
            OktaUpdate::class,
            ['clientSecret' => 'old-secret', 'oktaDomain' => 'trial-6400025.okta.com', 'authorizationServerId' => 'old-server'],
            [null, null, null, 'new-server', false],
            ['clientSecret' => 'old-secret', 'oktaDomain' => 'trial-6400025.okta.com', 'authorizationServerId' => 'new-server'],
            ['clientId' => 'seed-id', 'domain' => 'trial-6400025.okta.com', 'authorizationServerId' => 'new-server'],
        ];
    }

    public function testOidcAcceptsWellKnownMode(): void
    {
        $result = $this->runHandle(new OidcUpdate(), $this->project(), [
            'oidc-client',
            'oidc-secret',
            'https://idp.example/.well-known/openid-configuration',
            null,
            null,
            null,
            null,
            null,
            true,
        ]);

        $stored = \json_decode($result['updatedProject']->getAttribute('oAuthProviders')['oidcSecret'], true);
        $this->assertSame('https://idp.example/.well-known/openid-configuration', $stored['wellKnownEndpoint']);
        $this->assertTrue($result['response']->getAttribute('enabled'));
        $this->assertSame('https://idp.example/.well-known/openid-configuration', $result['response']->getAttribute('wellKnownURL'));
    }

    public function testOidcAcceptsDiscoveryUrlMode(): void
    {
        $result = $this->runHandle(new OidcUpdate(), $this->project(), [
            'oidc-client',
            'oidc-secret',
            null,
            'https://idp.example/oauth2/authorize',
            'https://idp.example/oauth2/token',
            'https://idp.example/oauth2/userinfo',
            null,
            null,
            true,
        ]);

        $stored = \json_decode($result['updatedProject']->getAttribute('oAuthProviders')['oidcSecret'], true);
        $this->assertSame('https://idp.example/oauth2/authorize', $stored['authorizationEndpoint']);
        $this->assertSame('https://idp.example/oauth2/token', $stored['tokenEndpoint']);
        $this->assertSame('https://idp.example/oauth2/userinfo', $stored['userInfoEndpoint']);
        $this->assertTrue($result['response']->getAttribute('enabled'));
    }

    public function testOidcRejectsEnableWithIncompleteDiscoveryConfig(): void
    {
        $this->expectException(Exception::class);

        $this->runHandle(
            new OidcUpdate(),
            $this->project(),
            [
                'oidc-client',
                'oidc-secret',
                null,
                'https://idp.example/oauth2/authorize',
                null,
                'https://idp.example/oauth2/userinfo',
                null,
                null,
                true,
            ],
            assertQueueParam: false,
        );
    }

    public function testOidcCanSwitchFromWellKnownToDiscoveryMode(): void
    {
        $result = $this->runHandle(
            new OidcUpdate(),
            $this->project([
                'oidcEnabled' => false,
                'oidcAppid' => 'oidc-client',
                'oidcSecret' => \json_encode([
                    'clientSecret' => 'oidc-secret',
                    'wellKnownEndpoint' => 'https://old.example/.well-known/openid-configuration',
                    'authorizationEndpoint' => '',
                    'tokenEndpoint' => '',
                    'userInfoEndpoint' => '',
                ]),
            ]),
            [
                null,
                null,
                '',
                'https://idp.example/oauth2/authorize',
                'https://idp.example/oauth2/token',
                'https://idp.example/oauth2/userinfo',
                null,
                null,
                true,
            ],
        );

        $stored = \json_decode($result['updatedProject']->getAttribute('oAuthProviders')['oidcSecret'], true);
        $this->assertSame('', $stored['wellKnownEndpoint']);
        $this->assertSame('https://idp.example/oauth2/authorize', $stored['authorizationEndpoint']);
        $this->assertTrue($result['response']->getAttribute('enabled'));
    }

    public function testGooglePromptRulesAndBackwardCompatibleSecretStorage(): void
    {
        $result = $this->runHandle(
            new GoogleUpdate(),
            $this->project([
                'googleEnabled' => false,
                'googleAppid' => 'google-client',
                'googleSecret' => 'legacy-plain-secret',
            ]),
            [null, null, ['none'], false],
        );

        $stored = \json_decode($result['updatedProject']->getAttribute('oAuthProviders')['googleSecret'], true);
        $this->assertSame('legacy-plain-secret', $stored['clientSecret']);
        $this->assertSame(['none'], $stored['prompt']);
        $this->assertSame(['none'], $result['response']->getAttribute('prompt'));
    }

    public function testGoogleRejectsEmptyPrompt(): void
    {
        $this->expectException(Exception::class);

        $this->runHandle(
            new GoogleUpdate(),
            $this->project(),
            [
                'google-client',
                'google-secret',
                [],
                false,
            ],
            assertQueueParam: false,
        );
    }

    public function testGoogleRejectsNoneWithOtherPrompts(): void
    {
        $this->expectException(Exception::class);

        $this->runHandle(
            new GoogleUpdate(),
            $this->project(),
            [
                'google-client',
                'google-secret',
                ['none', 'consent'],
                false,
            ],
            assertQueueParam: false,
        );
    }

    public function testOktaRequiresDomainWhenEnabling(): void
    {
        $this->expectException(Exception::class);

        $this->runHandle(
            new OktaUpdate(),
            $this->project(),
            [
                'okta-client',
                'okta-secret',
                '',
                null,
                true,
            ],
            assertQueueParam: false,
        );
    }

    public function testOktaCanEnableWithStoredDomain(): void
    {
        $result = $this->runHandle(
            new OktaUpdate(),
            $this->project([
                'oktaEnabled' => false,
                'oktaAppid' => 'okta-client',
                'oktaSecret' => \json_encode([
                    'clientSecret' => 'okta-secret',
                    'oktaDomain' => 'trial-6400025.okta.com',
                    'authorizationServerId' => '',
                ]),
            ]),
            [null, null, null, null, true],
        );

        $this->assertTrue($result['response']->getAttribute('enabled'));
        $this->assertSame('trial-6400025.okta.com', $result['response']->getAttribute('domain'));
    }

    /**
     * @param array<string, mixed> $oAuthProviders
     */
    private function project(array $oAuthProviders = []): Document
    {
        return new Document([
            '$id' => 'project-test',
            'oAuthProviders' => $oAuthProviders,
        ]);
    }

    /**
     * @param array<int, mixed> $args
     * @return array{updatedProject: Document, response: Document}
     */
    private function runHandle(Base $action, Document $project, array $args, bool $assertQueueParam = true): array
    {
        $updatedProject = null;
        $responseDocument = null;

        $dbForPlatform = $this->createStub(Database::class);
        $dbForPlatform
            ->method('updateDocument')
            ->willReturnCallback(function (string $collection, string $id, Document $updates) use ($project, &$updatedProject): Document {
                $this->assertSame('projects', $collection);
                $this->assertSame('project-test', $id);

                $updatedProject = new Document($project->getArrayCopy());
                $updatedProject->setAttribute('oAuthProviders', $updates->getAttribute('oAuthProviders', []));

                return $updatedProject;
            });

        $authorization = $this->createStub(Authorization::class);
        $authorization
            ->method('skip')
            ->willReturnCallback(fn (callable $callback): mixed => $callback());

        $response = $this->createStub(Response::class);
        $response
            ->method('dynamic')
            ->willReturnCallback(function (Document $document) use (&$responseDocument): void {
                $responseDocument = $document;
            });

        $queueForEvents = $this->getMockBuilder(QueueEvent::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['setParam'])
            ->getMock();
        $queueForEvents
            ->expects($assertQueueParam ? $this->once() : $this->atMost(1))
            ->method('setParam')
            ->with('providerId', $action::getProviderId());

        $handle = [$action, 'handle'];
        $this->assertIsCallable($handle);
        $handle(...[
            ...$args,
            $response,
            $dbForPlatform,
            $project,
            $authorization,
            $queueForEvents,
        ]);

        $this->assertInstanceOf(Document::class, $updatedProject);
        $this->assertInstanceOf(Document::class, $responseDocument);

        return [
            'updatedProject' => $updatedProject,
            'response' => $responseDocument,
        ];
    }
}

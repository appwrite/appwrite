<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\SAML;

use Appwrite\Auth\OAuth2\Saml as SamlAdapter;
use Appwrite\Auth\SAML\AuthnRequest;
use Appwrite\Auth\SAML\Identity;
use Appwrite\Auth\SAML\Response;
use Appwrite\Auth\SAML\Settings;
use Appwrite\Auth\SAML\Ticket;
use PHPUnit\Framework\TestCase;
use Utopia\Cache\Adapter\Memory;
use Utopia\Cache\Cache;

/**
 * Covers the parts of the SAML flow that are not protocol parsing: the
 * server-side state that replaces RelayState, single-use semantics, and the
 * adapter that hands a validated identity to the shared OAuth2 pipeline.
 */
final class FlowTest extends TestCase
{
    private AssertionBuilder $builder;
    private Settings $settings;
    private Cache $cache;

    protected function setUp(): void
    {
        $this->builder = new AssertionBuilder();
        $this->settings = new Settings(
            idpEntityId: AssertionBuilder::IDP_ENTITY_ID,
            idpSsoUrl: 'https://idp.example.com/sso',
            x509Cert: $this->builder->getCertificate(),
            spEntityId: AssertionBuilder::SP_ENTITY_ID,
            acsUrl: AssertionBuilder::ACS_URL,
        );
        $this->cache = new Cache(new Memory());
    }

    public function testAuthnRequestIsDeflatedAndBase64Encoded(): void
    {
        $request = new AuthnRequest($this->settings);
        $url = $request->getRedirectUrl('relay-token');

        $query = [];
        \parse_str((string)\parse_url($url, PHP_URL_QUERY), $query);

        $this->assertSame('relay-token', $query['RelayState']);

        $inflated = \gzinflate(\base64_decode($query['SAMLRequest'], true));

        $this->assertSame($request->toXml(), $inflated);
        $this->assertStringContainsString(AssertionBuilder::ACS_URL, $inflated);
    }

    /**
     * SAML request IDs are xsd:ID, which may not begin with a digit.
     */
    public function testRequestIdIsAValidNcName(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->assertMatchesRegularExpression('/^_[0-9a-f]{32}$/', (new AuthnRequest($this->settings))->getId());
        }
    }

    /**
     * The stored state must carry `token`: the shared redirect route reads
     * $state['token'] unconditionally when choosing between a session and a
     * token, so omitting it would fail on the token flow only.
     */
    public function testStoredStateRoundTripsAllThreeKeys(): void
    {
        $ticket = new Ticket(new Cache(new Memory()));
        $relay = Ticket::token();

        $ticket->save(Ticket::REQUESTS, $relay, [
            'success' => 'https://app.example/ok',
            'failure' => 'https://app.example/no',
            'token' => true,
            'requestId' => '_request123',
        ], Ticket::REQUEST_TTL);

        $state = $ticket->consume(Ticket::REQUESTS, $relay);

        $this->assertSame('https://app.example/ok', $state['success']);
        $this->assertSame('https://app.example/no', $state['failure']);
        $this->assertTrue($state['token']);
        $this->assertSame('_request123', $state['requestId']);
    }

    public function testRelayStateIsSingleUse(): void
    {
        $ticket = new Ticket(new Cache(new Memory()));
        $relay = Ticket::token();

        $ticket->save(Ticket::REQUESTS, $relay, ['success' => 'https://app.example/ok'], Ticket::REQUEST_TTL);

        $this->assertNotNull($ticket->consume(Ticket::REQUESTS, $relay));
        $this->assertNull($ticket->consume(Ticket::REQUESTS, $relay));
    }

    /**
     * RelayState is capped at 80 bytes by the SAML binding spec.
     */
    public function testRelayTokenFitsTheRelayStateLimit(): void
    {
        $this->assertLessThanOrEqual(80, \strlen(Ticket::token()));
    }

    public function testExpiredStateIsNotReturned(): void
    {
        $ticket = new Ticket(new Cache(new Memory()));
        $relay = Ticket::token();

        $ticket->save(Ticket::REQUESTS, $relay, ['success' => 'https://app.example/ok'], -1);

        $this->assertNull($ticket->consume(Ticket::REQUESTS, $relay));
    }

    public function testAssertionCanOnlyBeClaimedOnce(): void
    {
        // A dedicated cache: the in-memory adapter keys only on the collection
        // and ignores the per-record hash, so records from earlier assertions
        // in this test would otherwise collide. Redis, which is what runs in
        // production, keys on both.
        $ticket = new Ticket(new Cache(new Memory()));
        $expiry = \time() + 300;

        $this->assertTrue($ticket->claimAssertion('_assertion1', $expiry));
        $this->assertFalse($ticket->claimAssertion('_assertion1', $expiry));
    }

    /**
     * The whole point of the adapter: a validated assertion reaches the shared
     * OAuth2 pipeline through the provider interface it already speaks.
     */
    public function testAdapterExposesValidatedIdentityToThePipeline(): void
    {
        $response = new Response($this->settings, $this->builder->build([
            'attributes' => '<saml:Attribute Name="email"><saml:AttributeValue>alice@example.com</saml:AttributeValue></saml:Attribute>'
                . '<saml:Attribute Name="displayName"><saml:AttributeValue>Alice Smith</saml:AttributeValue></saml:Attribute>',
        ]));
        $response->validate('_request123');

        $identity = Identity::fromResponse($response, $this->settings);

        $code = Ticket::token();
        (new Ticket($this->cache))->save(Ticket::IDENTITIES, $code, [
            'id' => $identity->getId(),
            'email' => $identity->getEmail(),
            'name' => $identity->getName(),
        ], Ticket::IDENTITY_TTL);

        SamlAdapter::setTicket(new Ticket($this->cache));
        $adapter = new SamlAdapter('', '', '');

        $accessToken = $adapter->getAccessToken($code);

        // The exchange code is a credential and must not end up on the identity
        // or session document, so nothing is handed back to be persisted.
        $this->assertSame('', $accessToken);

        $this->assertSame('user@example.com', $adapter->getUserID($accessToken));
        $this->assertSame('alice@example.com', $adapter->getUserEmail($accessToken));
        $this->assertSame('Alice Smith', $adapter->getUserName($accessToken));
        $this->assertTrue($adapter->isEmailVerified($accessToken));

        // SAML issues no provider tokens, so nothing resembling one is stored.
        $this->assertSame('', $adapter->getRefreshToken($code));
        $this->assertSame(0, $adapter->getAccessTokenExpiry($code));
    }

    /**
     * The exchange code is single-use, so a replayed redirect cannot mint a
     * second session.
     */
    public function testExchangeCodeCannotBeReplayed(): void
    {
        $code = Ticket::token();
        (new Ticket($this->cache))->save(Ticket::IDENTITIES, $code, [
            'id' => 'user@example.com',
            'email' => 'user@example.com',
            'name' => '',
        ], Ticket::IDENTITY_TTL);

        SamlAdapter::setTicket(new Ticket($this->cache));

        $first = new SamlAdapter('', '', '');
        $first->getAccessToken($code);
        $this->assertSame('user@example.com', $first->getUserID(''));

        $second = new SamlAdapter('', '', '');

        $this->expectException(\Throwable::class);
        $second->getAccessToken($code);
    }

    /**
     * SAML sign-in cannot be started from the OAuth2 endpoint: there is no
     * login URL to build without constructing a signed AuthnRequest.
     */
    public function testAdapterRefusesToBuildAnOAuth2LoginUrl(): void
    {
        $this->expectException(\Throwable::class);

        (new SamlAdapter('', '', ''))->getLoginURL();
    }
}

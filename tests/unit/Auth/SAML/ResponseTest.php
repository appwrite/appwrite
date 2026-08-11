<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\SAML;

use Appwrite\Auth\SAML\Exception;
use Appwrite\Auth\SAML\Identity;
use Appwrite\Auth\SAML\Response;
use Appwrite\Auth\SAML\Settings;
use PHPUnit\Framework\TestCase;

/**
 * Validation tests for incoming SAML responses.
 *
 * Everything in a SAML response is attacker controlled until its signature has
 * been verified, so the negative cases below matter more than the happy path:
 * each one is a way a real service provider has been broken before.
 */
final class ResponseTest extends TestCase
{
    private AssertionBuilder $builder;
    private Settings $settings;

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
    }

    private function validate(string $payload, ?string $inResponseTo = '_request123'): Response
    {
        $response = new Response($this->settings, $payload);
        $response->validate($inResponseTo);

        return $response;
    }

    public function testValidAssertionPasses(): void
    {
        $response = $this->validate($this->builder->build());

        $this->assertSame('user@example.com', $response->getNameId());
        $this->assertNotSame('', $response->getAssertionId());
    }

    public function testValidResponseLevelSignaturePasses(): void
    {
        $response = $this->validate($this->builder->build([], 'response'));

        $this->assertSame('user@example.com', $response->getNameId());
    }

    /**
     * Okta and several other identity providers sign the Response *and* the
     * Assertion by default. Two signatures are normal and stronger, not
     * suspicious, so both must be accepted with the assertion signature
     * preferred.
     */
    public function testResponseSignedAtBothLevelsIsAccepted(): void
    {
        $response = new Response($this->settings, $this->builder->buildDoubleSigned());
        $response->validate('_request123');

        $this->assertSame('user@example.com', $response->getNameId());
    }

    public function testIdentityIsExtracted(): void
    {
        $response = $this->validate($this->builder->build([
            'attributes' => '<saml:Attribute Name="email"><saml:AttributeValue>alice@example.com</saml:AttributeValue></saml:Attribute>'
                . '<saml:Attribute Name="firstName"><saml:AttributeValue>Alice</saml:AttributeValue></saml:Attribute>'
                . '<saml:Attribute Name="lastName"><saml:AttributeValue>Smith</saml:AttributeValue></saml:Attribute>',
        ]));

        $identity = Identity::fromResponse($response, $this->settings);

        $this->assertSame('user@example.com', $identity->getId());
        $this->assertSame('alice@example.com', $identity->getEmail());
        $this->assertSame('Alice Smith', $identity->getName());
    }

    public function testEmailFallsBackToNameIdWhenItIsAnAddress(): void
    {
        $response = $this->validate($this->builder->build(['attributes' => '']));

        $identity = Identity::fromResponse($response, $this->settings);

        $this->assertSame('user@example.com', $identity->getEmail());
    }

    /**
     * Appwrite cannot create a user without an email, so an assertion with an
     * opaque NameID and no email attribute must fail with a message that tells
     * the admin to release one.
     */
    public function testMissingEmailIsRejectedWithActionableMessage(): void
    {
        $response = $this->validate($this->builder->build([
            'nameId' => 'a7f3c9e1-opaque-persistent-id',
            'attributes' => '',
        ]));

        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/email attribute/i');

        Identity::fromResponse($response, $this->settings);
    }

    public function testTamperedAssertionIsRejected(): void
    {
        $xml = $this->builder->buildSignedXml();
        $tampered = \str_replace('user@example.com', 'attacker@evil.com', $xml);

        $this->expectException(Exception::class);

        $this->validate(\base64_encode($tampered));
    }

    /**
     * An assertion signed by a key other than the configured one must not be
     * accepted, even though the signature is internally consistent.
     */
    public function testAssertionSignedByForeignKeyIsRejected(): void
    {
        [, $foreignKey] = AssertionBuilder::foreignKeyPair();

        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/signature verification failed/i');

        $this->validate($this->builder->build([], 'assertion', $foreignKey));
    }

    public function testUnsignedResponseIsRejected(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/not signed/i');

        $this->validate(\base64_encode($this->builder->buildXml()));
    }

    /**
     * Signature wrapping: the original signed assertion is left intact so its
     * signature still verifies, with a forged assertion injected alongside it.
     * A service provider that verifies the signature but reads claims from the
     * wrong element authenticates the attacker.
     */
    public function testSignatureWrappingIsRejected(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/exactly one Assertion/i');

        $this->validate($this->builder->buildWrappingAttack());
    }

    /**
     * A response whose DOCTYPE pulls in a local file must be refused outright,
     * rather than parsed with the entity expanded into the assertion.
     */
    public function testDoctypeIsRejected(): void
    {
        $payload = '<?xml version="1.0"?><!DOCTYPE foo [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>'
            . '<samlp:Response xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol">&xxe;</samlp:Response>';

        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/document type declaration/i');

        $this->validate(\base64_encode($payload));
    }

    public function testMalformedXmlIsRejected(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/well-formed/i');

        $this->validate(\base64_encode('<samlp:Response><unclosed>'));
    }

    public function testNonBase64PayloadIsRejected(): void
    {
        $this->expectException(Exception::class);

        $this->validate('%%%not base64%%%');
    }

    public function testExpiredAssertionIsRejected(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/expired/i');

        $this->validate($this->builder->build([
            'notBefore' => \gmdate('Y-m-d\TH:i:s\Z', \time() - 7200),
            'notOnOrAfter' => \gmdate('Y-m-d\TH:i:s\Z', \time() - 3600),
        ]));
    }

    public function testFutureAssertionIsRejected(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/not yet valid/i');

        $this->validate($this->builder->build([
            'notBefore' => \gmdate('Y-m-d\TH:i:s\Z', \time() + 3600),
            'notOnOrAfter' => \gmdate('Y-m-d\TH:i:s\Z', \time() + 7200),
        ]));
    }

    /**
     * An assertion minted for a different service provider must not be
     * replayable against us.
     */
    public function testWrongAudienceIsRejected(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/audience/i');

        $this->validate($this->builder->build(['audience' => 'https://someone-else.example/sp']));
    }

    public function testWrongIssuerIsRejected(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/issuer/i');

        $this->validate($this->builder->build(['issuer' => 'https://evil-idp.example/metadata']));
    }

    public function testWrongRecipientIsRejected(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/recipient/i');

        $this->validate($this->builder->build(['recipient' => 'https://appwrite.test/somewhere-else']));
    }

    public function testFailedStatusIsRejected(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/rejected the sign-in request/i');

        $this->validate($this->builder->build(['status' => 'urn:oasis:names:tc:SAML:2.0:status:Responder']));
    }

    /**
     * The response must correspond to the AuthnRequest that started this
     * sign-in, otherwise an assertion captured from one flow can be replayed
     * into another.
     */
    public function testMismatchedInResponseToIsRejected(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/authentication request/i');

        $this->validate($this->builder->build(['inResponseTo' => '_someOtherRequest']));
    }

    public function testMissingInResponseToIsRejected(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/authentication request/i');

        $this->validate($this->builder->build(['inResponseTo' => '']));
    }

    /**
     * Only the bearer method means "possession of this assertion proves
     * identity". holder-of-key and sender-vouches require the presenter to
     * demonstrate something further, which this service provider does not do,
     * so accepting them would authenticate whoever delivered the assertion.
     */
    public function testNonBearerConfirmationMethodIsRejected(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/bearer method/i');

        $this->validate($this->builder->build([
            'method' => 'urn:oasis:names:tc:SAML:2.0:cm:holder-of-key',
        ]));
    }

    /**
     * Recipient is what binds a bearer assertion to this ACS. Without it, one
     * captured at another service provider could be replayed here.
     */
    public function testConfirmationWithoutRecipientIsRejected(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/missing a recipient/i');

        $this->validate($this->builder->build(['recipient' => '']));
    }

    /**
     * A bearer assertion with no expiry would stay usable forever.
     */
    public function testConfirmationWithoutExpiryIsRejected(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/missing an expiry/i');

        $this->validate($this->builder->build(['notOnOrAfter' => '']));
    }

    /**
     * Identity-provider-initiated sign-in is out of scope, so an unsolicited
     * response must be refused rather than silently accepted.
     */
    public function testUnsolicitedResponseIsRejected(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/unsolicited/i');

        $this->validate($this->builder->build(['inResponseTo' => '']), null);
    }

    /**
     * Replay prevention itself lives in the ACS route, which needs the
     * assertion ID and expiry to key and expire its record.
     */
    public function testAssertionIdAndExpiryAreExposedForReplayPrevention(): void
    {
        $expiry = \time() + 300;

        $response = $this->validate($this->builder->build([
            'assertionId' => '_specificAssertionId',
            'notOnOrAfter' => \gmdate('Y-m-d\TH:i:s\Z', $expiry),
        ]));

        $this->assertSame('_specificAssertionId', $response->getAssertionId());
        $this->assertEqualsWithDelta($expiry, $response->getExpiry(), 2);
    }
}

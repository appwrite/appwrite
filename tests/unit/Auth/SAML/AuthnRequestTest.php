<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\SAML;

use Appwrite\Auth\SAML\AuthnRequest;
use Appwrite\Auth\SAML\Exception;
use Appwrite\Auth\SAML\Metadata;
use Appwrite\Auth\SAML\Settings;
use PHPUnit\Framework\TestCase;

/**
 * Covers the outbound half of the protocol: the settings a project stores, the
 * authentication request sent to the identity provider, and the service
 * provider metadata an administrator imports on their side.
 *
 * Nothing here parses input from an identity provider.
 */
final class AuthnRequestTest extends TestCase
{
    private const IDP_ENTITY_ID = 'https://idp.example.com/metadata';
    private const IDP_SSO_URL = 'https://idp.example.com/sso';
    private const SP_ENTITY_ID = 'https://appwrite.test/sp';
    private const ACS_URL = 'https://appwrite.test/v1/account/sessions/saml/project/callback';

    private string $certificate;

    protected function setUp(): void
    {
        $key = \openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        $csr = \openssl_csr_new(['commonName' => 'saml-test-idp'], $key, ['digest_alg' => 'sha256']);
        $x509 = \openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);

        $certificate = '';
        \openssl_x509_export($x509, $certificate);

        $this->certificate = $certificate;
    }

    private function settings(string $ssoUrl = self::IDP_SSO_URL, ?string $cert = null): Settings
    {
        return new Settings(
            idpEntityId: self::IDP_ENTITY_ID,
            idpSsoUrl: $ssoUrl,
            x509Cert: $cert ?? $this->certificate,
            spEntityId: self::SP_ENTITY_ID,
            acsUrl: self::ACS_URL,
        );
    }

    // --- Settings ----------------------------------------------------------

    /**
     * Identity provider setup screens show the certificate body without the PEM
     * header, so both forms have to be accepted.
     */
    public function testBareBase64CertificateIsNormalizedToPem(): void
    {
        $bare = \preg_replace('/-----[^-]+-----|\s/', '', $this->certificate);

        $settings = $this->settings(cert: $bare);

        $this->assertStringContainsString('BEGIN CERTIFICATE', $settings->getX509Cert());
        $this->assertNotFalse(\openssl_x509_read($settings->getX509Cert()));
    }

    public function testUnparseableCertificateIsRejected(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/could not be parsed/i');

        $this->settings(cert: 'not-a-certificate');
    }

    public function testInvalidSignOnUrlIsRejected(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/valid URL/i');

        $this->settings('not a url');
    }

    public function testUnsupportedNameIdFormatIsRejected(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/NameID format/i');

        new Settings(
            idpEntityId: self::IDP_ENTITY_ID,
            idpSsoUrl: self::IDP_SSO_URL,
            x509Cert: $this->certificate,
            spEntityId: self::SP_ENTITY_ID,
            acsUrl: self::ACS_URL,
            nameIdFormat: 'urn:example:not-a-real-format',
        );
    }

    /**
     * An explicit mapping wins outright; otherwise the common attribute names
     * are tried in order.
     */
    public function testExplicitAttributeMappingOverridesTheDefaults(): void
    {
        $this->assertContains('email', $this->settings()->getAttributeCandidates('email'));

        $mapped = new Settings(
            idpEntityId: self::IDP_ENTITY_ID,
            idpSsoUrl: self::IDP_SSO_URL,
            x509Cert: $this->certificate,
            spEntityId: self::SP_ENTITY_ID,
            acsUrl: self::ACS_URL,
            attributeMap: ['email' => 'urn:custom:mail'],
        );

        $this->assertSame(['urn:custom:mail'], $mapped->getAttributeCandidates('email'));
    }

    // --- AuthnRequest ------------------------------------------------------

    /**
     * The HTTP-Redirect binding requires the request to be deflated (raw,
     * headerless), base64 encoded and URL encoded.
     */
    public function testRequestIsDeflatedAndBase64Encoded(): void
    {
        $request = new AuthnRequest($this->settings());
        $url = $request->getRedirectUrl('relay-token');

        $query = [];
        \parse_str((string)\parse_url($url, PHP_URL_QUERY), $query);

        $this->assertSame('relay-token', $query['RelayState']);
        $this->assertSame($request->toXml(), \gzinflate(\base64_decode($query['SAMLRequest'], true)));
    }

    public function testRequestCarriesDestinationIssuerAndAcsUrl(): void
    {
        $xml = \simplexml_load_string((new AuthnRequest($this->settings()))->toXml());

        $this->assertSame(self::IDP_SSO_URL, (string)$xml['Destination']);
        $this->assertSame(self::ACS_URL, (string)$xml['AssertionConsumerServiceURL']);
        $this->assertStringContainsString('HTTP-POST', (string)$xml['ProtocolBinding']);

        $xml->registerXPathNamespace('saml', 'urn:oasis:names:tc:SAML:2.0:assertion');
        $this->assertSame(self::SP_ENTITY_ID, (string)$xml->xpath('//saml:Issuer')[0]);
    }

    /**
     * SAML request IDs are xsd:ID, which is an XML NCName and may not begin
     * with a digit.
     */
    public function testRequestIdIsAValidNcName(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->assertMatchesRegularExpression('/^_[0-9a-f]{32}$/', (new AuthnRequest($this->settings()))->getId());
        }
    }

    /**
     * Identity providers whose sign-in URL already carries a query string must
     * still get a well-formed URL.
     */
    public function testExistingQueryStringOnTheSignOnUrlIsPreserved(): void
    {
        $url = (new AuthnRequest($this->settings(self::IDP_SSO_URL . '?tenant=42')))->getRedirectUrl();

        $this->assertSame(1, \substr_count($url, '?'));
        $this->assertStringContainsString('tenant=42', $url);
    }

    // --- Metadata ----------------------------------------------------------

    public function testMetadataAdvertisesThePostBindingAndAcsUrl(): void
    {
        $xml = (new Metadata($this->settings()))->toXml();

        $this->assertStringContainsString('AssertionConsumerService', $xml);
        $this->assertStringContainsString(self::ACS_URL, $xml);
        $this->assertStringContainsString('HTTP-POST', $xml);
        $this->assertStringContainsString('WantAssertionsSigned="true"', $xml);
        $this->assertNotFalse(\simplexml_load_string($xml));
    }
}

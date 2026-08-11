<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\SAML;

use DOMDocument;
use DOMXPath;
use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RobRichards\XMLSecLibs\XMLSecurityKey;

/**
 * Builds SAML responses for tests, signed with a throwaway key pair generated
 * per run.
 *
 * Generating the key material here rather than committing a fixture keeps real
 * IdP certificates out of the repository and lets the negative tests forge
 * assertions with a second, untrusted key.
 */
final class AssertionBuilder
{
    public const string IDP_ENTITY_ID = 'https://idp.example.com/metadata';
    public const string SP_ENTITY_ID = 'https://appwrite.test/sp';
    public const string ACS_URL = 'https://appwrite.test/v1/account/sessions/saml/project/callback';

    /**
     * @var string
     */
    private string $certificate;

    /**
     * @var string
     */
    private string $privateKey;

    public function __construct()
    {
        $key = \openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        $csr = \openssl_csr_new(['commonName' => 'saml-test-idp'], $key, ['digest_alg' => 'sha256']);
        $x509 = \openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);

        // Typed properties cannot be passed by reference while uninitialized.
        $certificate = '';
        $privateKey = '';

        \openssl_x509_export($x509, $certificate);
        \openssl_pkey_export($key, $privateKey);

        $this->certificate = $certificate;
        $this->privateKey = $privateKey;
    }

    /**
     * @return string
     */
    public function getCertificate(): string
    {
        return $this->certificate;
    }

    /**
     * Unsigned response XML, with every value overridable so tests can vary one
     * thing at a time.
     *
     * @param array<string, string> $options
     *
     * @return string
     */
    public function buildXml(array $options = []): string
    {
        $now = \time();

        $assertionId = $options['assertionId'] ?? '_assertion' . \bin2hex(\random_bytes(8));
        $responseId = $options['responseId'] ?? '_response' . \bin2hex(\random_bytes(8));
        $issuer = $options['issuer'] ?? self::IDP_ENTITY_ID;
        $audience = $options['audience'] ?? self::SP_ENTITY_ID;
        $recipient = $options['recipient'] ?? self::ACS_URL;
        $status = $options['status'] ?? 'urn:oasis:names:tc:SAML:2.0:status:Success';
        $nameId = $options['nameId'] ?? 'user@example.com';
        $inResponseTo = $options['inResponseTo'] ?? '_request123';
        $notBefore = $options['notBefore'] ?? \gmdate('Y-m-d\TH:i:s\Z', $now - 300);
        $notOnOrAfter = $options['notOnOrAfter'] ?? \gmdate('Y-m-d\TH:i:s\Z', $now + 300);
        $attributes = $options['attributes'] ?? '<saml:Attribute Name="email"><saml:AttributeValue>user@example.com</saml:AttributeValue></saml:Attribute>';

        $method = $options['method'] ?? 'urn:oasis:names:tc:SAML:2.0:cm:bearer';

        $inResponseToAttr = $inResponseTo === '' ? '' : ' InResponseTo="' . $inResponseTo . '"';
        $recipientAttr = $recipient === '' ? '' : ' Recipient="' . $recipient . '"';
        $confirmationExpiryAttr = $notOnOrAfter === '' ? '' : ' NotOnOrAfter="' . $notOnOrAfter . '"';

        return <<<XML
        <samlp:Response xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol" xmlns:saml="urn:oasis:names:tc:SAML:2.0:assertion" ID="{$responseId}" Version="2.0" IssueInstant="{$notBefore}">
          <samlp:Status><samlp:StatusCode Value="{$status}"/></samlp:Status>
          <saml:Assertion ID="{$assertionId}" Version="2.0" IssueInstant="{$notBefore}">
            <saml:Issuer>{$issuer}</saml:Issuer>
            <saml:Subject>
              <saml:NameID>{$nameId}</saml:NameID>
              <saml:SubjectConfirmation Method="{$method}">
                <saml:SubjectConfirmationData{$inResponseToAttr}{$recipientAttr}{$confirmationExpiryAttr}/>
              </saml:SubjectConfirmation>
            </saml:Subject>
            <saml:Conditions NotBefore="{$notBefore}" NotOnOrAfter="{$notOnOrAfter}">
              <saml:AudienceRestriction><saml:Audience>{$audience}</saml:Audience></saml:AudienceRestriction>
            </saml:Conditions>
            <saml:AttributeStatement>{$attributes}</saml:AttributeStatement>
          </saml:Assertion>
        </samlp:Response>
        XML;
    }

    /**
     * Sign the assertion (or another element) and return the base64 payload an
     * IdP would POST to the ACS.
     *
     * @param array<string, string> $options
     * @param string $signElement
     * @param string|null $signWithKey
     *
     * @return string
     */
    public function build(array $options = [], string $signElement = 'assertion', ?string $signWithKey = null): string
    {
        return \base64_encode($this->buildSignedXml($options, $signElement, $signWithKey));
    }

    /**
     * @param array<string, string> $options
     * @param string $signElement
     * @param string|null $signWithKey
     *
     * @return string
     */
    public function buildSignedXml(array $options = [], string $signElement = 'assertion', ?string $signWithKey = null): string
    {
        $doc = new DOMDocument();
        $doc->loadXML($this->buildXml($options));

        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('saml', 'urn:oasis:names:tc:SAML:2.0:assertion');

        $target = $signElement === 'response'
            ? $doc->documentElement
            : $xpath->query('//saml:Assertion')->item(0);

        $dsig = new XMLSecurityDSig();
        $dsig->setCanonicalMethod(XMLSecurityDSig::EXC_C14N);
        $dsig->addReference(
            $target,
            XMLSecurityDSig::SHA256,
            ['http://www.w3.org/2000/09/xmldsig#enveloped-signature', XMLSecurityDSig::EXC_C14N],
            ['id_name' => 'ID', 'overwrite' => false]
        );

        $key = new XMLSecurityKey(XMLSecurityKey::RSA_SHA256, ['type' => 'private']);
        $key->loadKey($signWithKey ?? $this->privateKey, false);
        $dsig->sign($key);
        $dsig->appendSignature($target);

        return $doc->saveXML();
    }

    /**
     * A response signed at both the Response and Assertion level, which is what
     * Okta emits by default.
     *
     * @return string
     */
    public function buildDoubleSigned(array $options = []): string
    {
        // Sign the assertion first, then the response around it, matching the
        // order an identity provider applies them.
        $assertionSigned = $this->buildSignedXml($options, 'assertion');

        $doc = new DOMDocument();
        $doc->loadXML($assertionSigned);

        $dsig = new XMLSecurityDSig();
        $dsig->setCanonicalMethod(XMLSecurityDSig::EXC_C14N);
        $dsig->addReference(
            $doc->documentElement,
            XMLSecurityDSig::SHA256,
            ['http://www.w3.org/2000/09/xmldsig#enveloped-signature', XMLSecurityDSig::EXC_C14N],
            ['id_name' => 'ID', 'overwrite' => false]
        );

        $key = new XMLSecurityKey(XMLSecurityKey::RSA_SHA256, ['type' => 'private']);
        $key->loadKey($this->privateKey, false);
        $dsig->sign($key);
        $dsig->appendSignature($doc->documentElement);

        return \base64_encode($doc->saveXML());
    }

    /**
     * A signature-wrapping payload: the legitimately signed assertion is kept
     * intact so its signature still verifies, and a forged assertion carrying
     * attacker-controlled claims is injected alongside it.
     *
     * @return string
     */
    public function buildWrappingAttack(): string
    {
        $legitimate = $this->buildSignedXml();

        $doc = new DOMDocument();
        $doc->loadXML($legitimate);

        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('saml', 'urn:oasis:names:tc:SAML:2.0:assertion');
        $xpath->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');

        $signed = $xpath->query('//saml:Assertion')->item(0);

        $forged = $signed->cloneNode(true);
        $forged->setAttribute('ID', '_forged' . \bin2hex(\random_bytes(8)));

        // Strip the signature from the clone and swap in the attacker identity.
        foreach ($xpath->query('.//ds:Signature', $forged) as $signature) {
            $forged->removeChild($signature);
        }

        foreach ($xpath->query('.//saml:NameID', $forged) as $nameId) {
            $nameId->textContent = 'attacker@evil.com';
        }

        $doc->documentElement->insertBefore($forged, $signed);

        return \base64_encode($doc->saveXML());
    }

    /**
     * A second key pair, for forging a signature the configured certificate
     * must not accept.
     *
     * @return array{0: string, 1: string} certificate, private key
     */
    public static function foreignKeyPair(): array
    {
        $key = \openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        $csr = \openssl_csr_new(['commonName' => 'attacker'], $key, ['digest_alg' => 'sha256']);
        $x509 = \openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);

        $certificate = '';
        $privateKey = '';

        \openssl_x509_export($x509, $certificate);
        \openssl_pkey_export($key, $privateKey);

        return [$certificate, $privateKey];
    }
}

<?php

namespace Appwrite\Auth\SAML;

use DOMDocument;

/**
 * Builds a SAML 2.0 `<AuthnRequest>` and encodes it for the HTTP-Redirect
 * binding, which is how an SP-initiated sign-in starts.
 *
 * The request ID is retained by the caller and stored server side, so the
 * matching `InResponseTo` on the way back can be checked against a request we
 * actually issued. See Response::validate().
 */
class AuthnRequest
{
    /**
     * @var string
     */
    private readonly string $id;

    /**
     * @var string
     */
    private readonly string $issueInstant;

    /**
     * @param Settings $settings
     */
    public function __construct(private readonly Settings $settings)
    {
        $this->id = self::generateId();
        $this->issueInstant = \gmdate('Y-m-d\TH:i:s\Z');
    }

    /**
     * SAML request IDs are xsd:ID, which is an XML NCName: it may not start
     * with a digit. Prefixing the hex with an underscore satisfies that
     * regardless of what the random bytes happen to be.
     *
     * @return string
     */
    private static function generateId(): string
    {
        return '_' . \bin2hex(\random_bytes(16));
    }

    /**
     * @return string
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * The `<AuthnRequest>` as XML.
     *
     * `AssertionConsumerServiceURL` and `Destination` are both included: the
     * former tells the IdP where to POST the assertion, the latter lets the IdP
     * confirm the request was addressed to it.
     *
     * @return string
     */
    public function toXml(): string
    {
        $doc = new DOMDocument('1.0', 'UTF-8');

        $request = $doc->createElementNS('urn:oasis:names:tc:SAML:2.0:protocol', 'samlp:AuthnRequest');
        $request->setAttribute('ID', $this->id);
        $request->setAttribute('Version', '2.0');
        $request->setAttribute('IssueInstant', $this->issueInstant);
        $request->setAttribute('Destination', $this->settings->getIdpSsoUrl());
        $request->setAttribute('ProtocolBinding', 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST');
        $request->setAttribute('AssertionConsumerServiceURL', $this->settings->getAcsUrl());
        $doc->appendChild($request);

        $issuer = $doc->createElementNS('urn:oasis:names:tc:SAML:2.0:assertion', 'saml:Issuer', $this->settings->getSpEntityId());
        $request->appendChild($issuer);

        $policy = $doc->createElementNS('urn:oasis:names:tc:SAML:2.0:protocol', 'samlp:NameIDPolicy');
        $policy->setAttribute('Format', $this->settings->getNameIdFormat());
        $policy->setAttribute('AllowCreate', 'true');
        $request->appendChild($policy);

        return $doc->saveXML();
    }

    /**
     * Full IdP sign-in URL for the HTTP-Redirect binding.
     *
     * Per SAML 2.0 bindings, the request is deflated (raw, headerless), base64
     * encoded, and URL encoded. `RelayState` is opaque to the IdP and echoed
     * back to the ACS; the spec caps it at 80 bytes, so callers pass a lookup
     * token rather than the state itself.
     *
     * @param string $relayState
     *
     * @return string
     */
    public function getRedirectUrl(string $relayState = ''): string
    {
        $deflated = \gzdeflate($this->toXml());

        if ($deflated === false) {
            throw new Exception('Failed to compress SAML authentication request.');
        }

        $query = ['SAMLRequest' => \base64_encode($deflated)];

        if ($relayState !== '') {
            $query['RelayState'] = $relayState;
        }

        $separator = \str_contains($this->settings->getIdpSsoUrl(), '?') ? '&' : '?';

        return $this->settings->getIdpSsoUrl() . $separator . \http_build_query($query);
    }
}

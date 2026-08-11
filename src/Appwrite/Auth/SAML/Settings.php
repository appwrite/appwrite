<?php

namespace Appwrite\Auth\SAML;

use Appwrite\Extend\Exception as AppwriteException;

/**
 * Immutable SAML Service Provider configuration.
 *
 * Holds both sides of the trust relationship: what the Identity Provider told
 * us about itself (entity ID, sign-in URL, signing certificate) and what we
 * publish about ourselves (entity ID, Assertion Consumer Service URL).
 *
 * Validation happens on construction so an invalid configuration is rejected
 * when an admin saves it, rather than when a user first tries to sign in.
 */
class Settings
{
    /**
     * Subset of SAML 2.0 NameID formats worth exposing. `unspecified` leaves
     * the choice to the IdP, which is what most deployments want.
     */
    public const array NAME_ID_FORMATS = [
        'urn:oasis:names:tc:SAML:1.1:nameid-format:unspecified',
        'urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress',
        'urn:oasis:names:tc:SAML:2.0:nameid-format:persistent',
        'urn:oasis:names:tc:SAML:2.0:nameid-format:transient',
    ];

    public const string DEFAULT_NAME_ID_FORMAT = 'urn:oasis:names:tc:SAML:1.1:nameid-format:unspecified';

    /**
     * Attribute names an IdP commonly uses for each claim, tried in order when
     * the admin has not mapped the claim explicitly. Okta and Entra ID both
     * emit either a bare name or the corresponding Claims schema URI.
     */
    public const array DEFAULT_ATTRIBUTE_MAP = [
        'email' => [
            'email',
            'emailAddress',
            'mail',
            'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/emailaddress',
        ],
        'name' => [
            'name',
            'displayName',
            'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/name',
        ],
        'firstName' => [
            'firstName',
            'givenName',
            'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/givenname',
        ],
        'lastName' => [
            'lastName',
            'surname',
            'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/surname',
        ],
    ];

    /**
     * @var string
     */
    private string $x509Cert;

    /**
     * @param string $idpEntityId IdP entity ID, matched against the assertion Issuer.
     * @param string $idpSsoUrl IdP sign-in URL the AuthnRequest is sent to.
     * @param string $x509Cert IdP signing certificate, PEM or bare base64.
     * @param string $spEntityId Our entity ID, matched against the assertion AudienceRestriction.
     * @param string $acsUrl Our Assertion Consumer Service URL, matched against the SubjectConfirmationData Recipient.
     * @param string $nameIdFormat Requested NameID format.
     * @param array<string, string> $attributeMap Claim name to IdP attribute name, overriding DEFAULT_ATTRIBUTE_MAP.
     *
     * @throws Exception when any value is missing or malformed.
     */
    public function __construct(
        private readonly string $idpEntityId,
        private readonly string $idpSsoUrl,
        string $x509Cert,
        private readonly string $spEntityId,
        private readonly string $acsUrl,
        private readonly string $nameIdFormat = self::DEFAULT_NAME_ID_FORMAT,
        private readonly array $attributeMap = [],
    ) {
        $this->x509Cert = self::normalizeCertificate($x509Cert);

        if (empty($this->idpEntityId)) {
            throw new Exception('SAML identity provider entity ID is required.', AppwriteException::GENERAL_ARGUMENT_INVALID);
        }

        if (!\filter_var($this->idpSsoUrl, FILTER_VALIDATE_URL)) {
            throw new Exception('SAML identity provider sign-in URL must be a valid URL.', AppwriteException::GENERAL_ARGUMENT_INVALID);
        }

        if (empty($this->spEntityId)) {
            throw new Exception('SAML service provider entity ID is required.', AppwriteException::GENERAL_ARGUMENT_INVALID);
        }

        if (!\filter_var($this->acsUrl, FILTER_VALIDATE_URL)) {
            throw new Exception('SAML assertion consumer service URL must be a valid URL.', AppwriteException::GENERAL_ARGUMENT_INVALID);
        }

        if (!\in_array($this->nameIdFormat, self::NAME_ID_FORMATS, true)) {
            throw new Exception('Unsupported SAML NameID format: ' . $this->nameIdFormat, AppwriteException::GENERAL_ARGUMENT_INVALID);
        }
    }

    /**
     * Accept a certificate as a full PEM block, or as the bare base64 body that
     * IdP setup screens and IdP metadata `<X509Certificate>` elements contain,
     * and return a normalized PEM block.
     *
     * @param string $cert
     *
     * @return string
     *
     * @throws Exception when the value is not a certificate OpenSSL can parse.
     */
    private static function normalizeCertificate(string $cert): string
    {
        $cert = \trim($cert);

        if (empty($cert)) {
            throw new Exception('SAML identity provider signing certificate is required.', AppwriteException::GENERAL_ARGUMENT_INVALID);
        }

        if (!\str_contains($cert, 'BEGIN CERTIFICATE')) {
            $body = \preg_replace('/\s+/', '', $cert);
            $cert = "-----BEGIN CERTIFICATE-----\n"
                . \chunk_split($body, 64, "\n")
                . "-----END CERTIFICATE-----\n";
        }

        // openssl_x509_read is the authority on whether this parses at all.
        // Errors are queued by OpenSSL, so drain them to keep the queue from
        // leaking into an unrelated call later in the request.
        $parsed = @\openssl_x509_read($cert);
        while (\openssl_error_string() !== false) {
            // Discard.
        }

        if ($parsed === false) {
            throw new Exception('SAML identity provider signing certificate could not be parsed. Provide the IdP X.509 signing certificate in PEM format.', AppwriteException::GENERAL_ARGUMENT_INVALID);
        }

        return $cert;
    }

    /**
     * @return string
     */
    public function getIdpEntityId(): string
    {
        return $this->idpEntityId;
    }

    /**
     * @return string
     */
    public function getIdpSsoUrl(): string
    {
        return $this->idpSsoUrl;
    }

    /**
     * @return string
     */
    public function getX509Cert(): string
    {
        return $this->x509Cert;
    }

    /**
     * @return string
     */
    public function getSpEntityId(): string
    {
        return $this->spEntityId;
    }

    /**
     * @return string
     */
    public function getAcsUrl(): string
    {
        return $this->acsUrl;
    }

    /**
     * @return string
     */
    public function getNameIdFormat(): string
    {
        return $this->nameIdFormat;
    }

    /**
     * Candidate IdP attribute names for a claim, most specific first. An
     * explicit mapping wins outright; otherwise fall back to the common names.
     *
     * @param string $claim
     *
     * @return array<int, string>
     */
    public function getAttributeCandidates(string $claim): array
    {
        $configured = $this->attributeMap[$claim] ?? null;

        if (!empty($configured)) {
            return [$configured];
        }

        return self::DEFAULT_ATTRIBUTE_MAP[$claim] ?? [];
    }

    /**
     * @return array<string, string>
     */
    public function getAttributeMap(): array
    {
        return $this->attributeMap;
    }
}

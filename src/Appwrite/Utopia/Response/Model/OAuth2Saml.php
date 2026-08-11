<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Auth\SAML\Settings;
use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

/**
 * SAML configuration for a project.
 *
 * Unlike every other provider in this family, this extends Model rather than
 * OAuth2Base: SAML has no client secret, so inheriting the base rules would
 * publish a `clientSecret` field that is never populated. The identity
 * provider authenticates itself with an XML signature instead.
 */
class OAuth2Saml extends Model
{
    public array $conditions = [
        '$id' => 'saml',
    ];

    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'Provider ID.',
                'default' => '',
                'example' => 'saml',
            ])
            ->addRule('enabled', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'SAML provider is active and can be used to create sessions.',
                'default' => false,
                'example' => false,
            ])
            ->addRule('spEntityId', [
                'type' => self::TYPE_STRING,
                'description' => 'Service provider entity ID that Appwrite presents to the identity provider.',
                'default' => '',
                'example' => 'https://cloud.appwrite.io/v1/account/sessions/saml/6a79b295001cd2ff15cd/metadata',
            ])
            ->addRule('idpEntityId', [
                'type' => self::TYPE_STRING,
                'description' => 'Identity provider entity ID, matched against the issuer of incoming assertions.',
                'default' => '',
                'example' => 'http://www.okta.com/exk1fa2b3c4d5e6f7g8h9',
            ])
            ->addRule('idpSsoUrl', [
                'type' => self::TYPE_STRING,
                'description' => 'Identity provider single sign-on URL that authentication requests are sent to.',
                'default' => '',
                'example' => 'https://dev-123456.okta.com/app/dev-123456_appwrite_1/exk1fa2b3c4d5e6f7g8h9/sso/saml',
            ])
            ->addRule('x509Cert', [
                'type' => self::TYPE_STRING,
                'description' => 'Identity provider X.509 signing certificate, used to verify assertion signatures.',
                'default' => '',
                'example' => '-----BEGIN CERTIFICATE-----\nMIIDpDCCAoyg...\n-----END CERTIFICATE-----',
            ])
            ->addRule('nameIdFormat', [
                'type' => self::TYPE_STRING,
                'description' => 'Requested NameID format.',
                'default' => Settings::DEFAULT_NAME_ID_FORMAT,
                'example' => Settings::DEFAULT_NAME_ID_FORMAT,
            ])
            ->addRule('emailAttribute', [
                'type' => self::TYPE_STRING,
                'description' => 'Assertion attribute carrying the user email address. Empty means the common attribute names are tried.',
                'default' => '',
                'example' => 'email',
            ])
            ->addRule('nameAttribute', [
                'type' => self::TYPE_STRING,
                'description' => 'Assertion attribute carrying the user display name. Empty means the common attribute names are tried.',
                'default' => '',
                'example' => 'displayName',
            ]);
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'OAuth2Saml';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_OAUTH2_SAML;
    }
}

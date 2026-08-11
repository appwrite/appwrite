<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Saml;

use Appwrite\Auth\OAuth2\Saml as SamlAdapter;
use Appwrite\Auth\SAML\Settings;
use Appwrite\Event\Event as QueueEvent;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Platform\Enum;
use Utopia\Validator\Boolean;
use Utopia\Validator\Nullable;
use Utopia\Validator\Text;
use Utopia\Validator\URL;
use Utopia\Validator\WhiteList;

/**
 * Configure the SAML identity provider for a project.
 *
 * SAML is not an OAuth2 provider, but it reuses this module's credential
 * storage: the SP entity ID goes in the `Appid` slot and the rest of the
 * configuration is JSON-encoded into the `Secret` slot, exactly as OIDC packs
 * its discovery endpoints. See Base::persistCredentials().
 *
 * Like Oidc\Update, this defines its own `handle()` rather than overriding
 * `Base::action()`, whose signature only carries a client ID and secret.
 */
class Update extends Base
{
    public static function getProviderId(): string
    {
        return 'saml';
    }

    public static function getProviderClass(): string
    {
        return SamlAdapter::class;
    }

    public static function getProviderLabel(): string
    {
        return 'Saml';
    }

    public static function getProviderSDKMethod(): string
    {
        return 'updateOAuth2Saml';
    }

    public static function getResponseModel(): string
    {
        return Response::MODEL_OAUTH2_SAML;
    }

    /**
     * SAML has no client ID. The nearest equivalent is the service provider
     * entity ID, which is what identifies us to the IdP.
     */
    public static function getClientIdName(): string
    {
        return 'Service provider entity ID';
    }

    public static function getClientIdExample(): string
    {
        return 'https://cloud.appwrite.io/v1/account/sessions/saml/6a79b295001cd2ff15cd/metadata';
    }

    public static function getClientIdParamName(): string
    {
        return 'spEntityId';
    }

    /**
     * SAML has no client secret: the IdP proves itself with an XML signature
     * rather than a shared secret, so the credential field is suppressed and
     * the certificate is exposed as its own parameter instead.
     */
    public static function getClientSecretName(): string
    {
        return '';
    }

    public static function getClientSecretExample(): string
    {
        return '';
    }

    /**
     * @return array<int, array<string, string>>
     */
    public static function getParameters(): array
    {
        return \array_merge(parent::getParameters(), [
            [
                '$id' => 'idpEntityId',
                'name' => 'Identity provider entity ID',
                'example' => 'http://www.okta.com/exk1fa2b3c4d5e6f7g8h9',
                'hint' => '',
            ],
            [
                '$id' => 'idpSsoUrl',
                'name' => 'Identity provider sign-in URL',
                'example' => 'https://dev-123456.okta.com/app/dev-123456_appwrite_1/exk1fa2b3c4d5e6f7g8h9/sso/saml',
                'hint' => '',
            ],
            [
                '$id' => 'x509Cert',
                'name' => 'Identity provider signing certificate',
                'example' => '-----BEGIN CERTIFICATE-----\nMIIDpDCCAoyg...\n-----END CERTIFICATE-----',
                'hint' => '',
            ],
            [
                '$id' => 'nameIdFormat',
                'name' => 'NameID format',
                'example' => Settings::DEFAULT_NAME_ID_FORMAT,
                'hint' => '',
            ],
            [
                '$id' => 'emailAttribute',
                'name' => 'Email attribute',
                'example' => 'email',
                'hint' => '',
            ],
            [
                '$id' => 'nameAttribute',
                'name' => 'Name attribute',
                'example' => 'displayName',
                'hint' => '',
            ],
        ]);
    }

    public function __construct()
    {
        $providerId = static::getProviderId();

        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/project/oauth2/' . $providerId)
            ->desc('Update project SAML')
            ->groups(['api', 'project'])
            ->label('scope', 'project.oauth2.write')
            ->label('event', 'oauth2.[providerId].update')
            ->label('audits.event', 'project.oauth2.[providerId].update')
            ->label('audits.resource', 'project.oauth2/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'project',
                group: 'oauth2',
                name: static::getProviderSDKMethod(),
                description: 'Update the project SAML configuration.',
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: static::getResponseModel(),
                    )
                ],
            ))
            ->param('spEntityId', null, new Nullable(new Text(2048, 0)), 'Service provider entity ID. This is the identifier Appwrite presents to the identity provider. Defaults to the project metadata URL when left empty.', optional: true)
            ->param('idpEntityId', null, new Nullable(new Text(2048, 0)), 'Identity provider entity ID, as published in the identity provider metadata. For example: http://www.okta.com/exk1fa2b3c4d5e6f7g8h9', optional: true)
            ->param('idpSsoUrl', null, new Nullable(new URL(allowEmpty: true)), 'Identity provider single sign-on URL that authentication requests are sent to.', optional: true)
            // The certificate is a PEM block of roughly 1-2KB; the 512 limit
            // used for OAuth2 client secrets is far too small for it.
            ->param('x509Cert', null, new Nullable(new Text(8192, 0)), 'Identity provider X.509 signing certificate, in PEM format or as the bare base64 body. Used to verify the signature on incoming assertions.', optional: true)
            ->param('nameIdFormat', null, new Nullable(new WhiteList(Settings::NAME_ID_FORMATS, true)), 'Requested NameID format.', optional: true, enum: new Enum(name: 'ProjectOAuth2SamlNameIdFormat'))
            ->param('emailAttribute', null, new Nullable(new Text(256, 0)), 'Name of the assertion attribute carrying the user email address. Leave empty to try the common attribute names. An email is required to create an Appwrite account, so the identity provider must release one.', optional: true)
            ->param('nameAttribute', null, new Nullable(new Text(256, 0)), 'Name of the assertion attribute carrying the user display name. Leave empty to try the common attribute names.', optional: true)
            ->param('enabled', null, new Nullable(new Boolean()), 'SAML sign-in method status. Set to true to enable new session creation. Setting to true validates the configuration and will throw if it is incomplete or malformed.', true)
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('project')
            ->inject('authorization')
            ->inject('queueForEvents')
            ->callback($this->handle(...));
    }

    public function buildReadResponse(Document $project): Document
    {
        $providerId = static::getProviderId();
        $oAuthProviders = $project->getAttribute('oAuthProviders', []);
        $decoded = $this->decodeStoredSecret($project);

        return new Document([
            '$id' => $providerId,
            'enabled' => $oAuthProviders[$providerId . 'Enabled'] ?? false,
            'spEntityId' => $oAuthProviders[$providerId . 'Appid'] ?? '',
            'idpEntityId' => $decoded['idpEntityId'] ?? '',
            'idpSsoUrl' => $decoded['idpSsoUrl'] ?? '',
            // Unlike an OAuth2 client secret, the IdP certificate is public
            // information: it is published in IdP metadata and is only used to
            // verify signatures. Returning it lets an admin confirm what is
            // configured without having to re-paste it.
            'x509Cert' => $decoded['x509Cert'] ?? '',
            'nameIdFormat' => $decoded['nameIdFormat'] ?? Settings::DEFAULT_NAME_ID_FORMAT,
            'emailAttribute' => $decoded['attributeMap']['email'] ?? '',
            'nameAttribute' => $decoded['attributeMap']['name'] ?? '',
        ]);
    }

    /**
     * Custom callback used instead of the parent's `action()`, which only
     * accepts a client ID and secret. Named differently to avoid an
     * LSP-incompatible override, matching the approach in Oidc\Update.
     *
     * Values are merged over what is already stored so an admin can update a
     * single field without re-sending the whole configuration.
     */
    public function handle(
        ?string $spEntityId,
        ?string $idpEntityId,
        ?string $idpSsoUrl,
        ?string $x509Cert,
        ?string $nameIdFormat,
        ?string $emailAttribute,
        ?string $nameAttribute,
        ?bool $enabled,
        Response $response,
        Database $dbForPlatform,
        Document $project,
        Authorization $authorization,
        QueueEvent $queueForEvents
    ): void {
        $providerId = static::getProviderId();
        $queueForEvents->setParam('providerId', $providerId);

        $existing = $this->decodeStoredSecret($project);
        $existingMap = $existing['attributeMap'] ?? [];

        $attributeMap = \array_filter([
            'email' => $emailAttribute ?? ($existingMap['email'] ?? ''),
            'name' => $nameAttribute ?? ($existingMap['name'] ?? ''),
        ]);

        $merged = [
            'idpEntityId' => $idpEntityId ?? ($existing['idpEntityId'] ?? ''),
            'idpSsoUrl' => $idpSsoUrl ?? ($existing['idpSsoUrl'] ?? ''),
            'x509Cert' => $x509Cert ?? ($existing['x509Cert'] ?? ''),
            'nameIdFormat' => $nameIdFormat ?? ($existing['nameIdFormat'] ?? Settings::DEFAULT_NAME_ID_FORMAT),
            'attributeMap' => $attributeMap,
        ];

        $oAuthProviders = $project->getAttribute('oAuthProviders', []);
        $entityId = $spEntityId ?? ($oAuthProviders[$providerId . 'Appid'] ?? '');

        // Enabling runs the configuration through Settings, which is the same
        // validation the sign-in flow will apply, so a project can never be
        // left enabled with a configuration that cannot complete a sign-in.
        if ($enabled === true) {
            $this->assertConfigurationIsUsable($merged, $entityId);
        }

        $project = $this->persist($project, $dbForPlatform, $authorization, $entityId, \json_encode($merged), $enabled);

        $response->dynamic($this->buildReadResponse($project), static::getResponseModel());
    }

    /**
     * @param array<string, mixed> $config
     * @param string $entityId
     *
     * @return void
     */
    private function assertConfigurationIsUsable(array $config, string $entityId): void
    {
        if (empty($entityId)) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Enabling SAML requires a service provider entity ID.');
        }

        try {
            new Settings(
                idpEntityId: $config['idpEntityId'],
                idpSsoUrl: $config['idpSsoUrl'],
                x509Cert: $config['x509Cert'],
                spEntityId: $entityId,
                // The real ACS URL is derived per request from the project and
                // hostname. Any valid URL satisfies the constructor here; this
                // check is about the identity provider half of the config.
                acsUrl: $entityId,
                nameIdFormat: $config['nameIdFormat'],
                attributeMap: $config['attributeMap'],
            );
        } catch (\Throwable $error) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Could not enable SAML: ' . $error->getMessage());
        }
    }

    /**
     * Store the SAML configuration on the project.
     *
     * Base::persistCredentials cannot be reused: when enabling, it requires a
     * non-empty client secret and instantiates the provider class with OAuth2
     * constructor arguments, neither of which applies to SAML.
     *
     * @param Document $project
     * @param Database $dbForPlatform
     * @param Authorization $authorization
     * @param string $entityId
     * @param string $secret
     * @param bool|null $enabled
     *
     * @return Document
     */
    private function persist(
        Document $project,
        Database $dbForPlatform,
        Authorization $authorization,
        string $entityId,
        string $secret,
        ?bool $enabled
    ): Document {
        $providerId = static::getProviderId();

        if (!\in_array($providerId, \array_keys(Config::getParam('oAuthProviders')))) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Provider ' . $providerId . ' is not supported by server configuration.');
        }

        $oAuthProviders = $project->getAttribute('oAuthProviders', []);
        $oAuthProviders[$providerId . 'Appid'] = $entityId;
        $oAuthProviders[$providerId . 'Secret'] = $secret;

        if (!\is_null($enabled)) {
            $oAuthProviders[$providerId . 'Enabled'] = $enabled;
        }

        $updates = new Document([
            'oAuthProviders' => $oAuthProviders
        ]);

        $project = $authorization->skip(fn () => $dbForPlatform->updateDocument('projects', $project->getId(), $updates));
        $authorization->skip(fn () => $dbForPlatform->purgeCachedDocument('projects', $project->getId()));

        return $project;
    }
}

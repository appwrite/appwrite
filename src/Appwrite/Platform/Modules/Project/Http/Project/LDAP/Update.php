<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\LDAP;

use Appwrite\Auth\LDAP\Client;
use Appwrite\Extend\Exception;
use Appwrite\Network\Validator\HostnameOrIP;
use Appwrite\Network\Validator\PublicHostname;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\SDK\Specification\Validator\PasswordFormat;
use Appwrite\Utopia\Response;
use InvalidArgumentException;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Platform\Enum;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Boolean;
use Utopia\Validator\Nullable;
use Utopia\Validator\Range;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

class Update extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'updateProjectLDAP';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/project/ldap')
            ->httpAlias('/v1/projects/:projectId/ldap')
            ->desc('Update project LDAP configuration')
            ->groups(['api', 'project'])
            ->label('scope', 'project.ldap.write')
            ->label('audits.event', 'project.ldap.update')
            ->label('audits.resource', 'project.ldap/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'project',
                group: 'ldap',
                name: 'updateLDAP',
                description: <<<EOT
                Update the LDAP configuration for your project. Use this endpoint to connect your project to an LDAP directory, such as OpenLDAP or Active Directory, so users can sign in with their directory credentials. Omitted parameters keep their stored value. Enabling LDAP connects to the directory and binds as the service account, so a project cannot be left enabled with a configuration that cannot sign anyone in.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_PROJECT,
                    )
                ],
            ))
            ->param('enabled', null, new Nullable(new Boolean()), 'Enable or disable LDAP sign-in. Enabling connects to the directory to validate the configuration.', optional: true)
            ->param('hosts', null, new Nullable(new ArrayList(new HostnameOrIP(), Client::HOSTS_MAX)), 'Directory hostnames or IP addresses, tried in order until one answers. IPv6 addresses can be written with or without square brackets. Maximum of ' . Client::HOSTS_MAX . ' hosts are allowed. For example: ["ldap.example.com"]', optional: true, example: '["ldap.example.com"]')
            ->param('port', null, new Nullable(new Range(1, 65535)), 'Directory port. Defaults to 636 for LDAPS and 389 otherwise.', optional: true, example: '636')
            ->param('encryption', null, new Nullable(new WhiteList(Client::ENCRYPTIONS, true)), 'Transport encryption. Use "ldaps" for TLS from the first byte, "starttls" to upgrade a plain connection, or "none" for a trusted network only. Allowed values are: none, ldaps, starttls. Defaults to ldaps.', optional: true, enum: new Enum(name: 'ProjectLDAPEncryption'))
            ->param('verification', null, new Nullable(new Boolean()), 'Certificate verification, false for disabled or true for enabled. Defaults to true. Has no effect when encryption is none.', optional: true)
            ->param('certificate', null, new Nullable(new Text(16384, 0)), 'Certificate in PEM format of the authority that signed the directory certificate, or the directory certificate itself when it is self-signed. Several certificates can be given one after another. Leave empty to use the system trust store. Pass an empty string to clear a previously set value.', optional: true)
            ->param('timeout', null, new Nullable(new Range(1, Client::TIMEOUT_MAX)), 'Seconds to wait for the directory to connect and answer each operation. Maximum is ' . Client::TIMEOUT_MAX . ' seconds. Defaults to ' . Client::TIMEOUT_DEFAULT . '.', optional: true, example: '10')
            ->param('baseDn', null, new Nullable(new Text(512, 0)), 'Subtree the user search starts from. For example: ou=people,dc=example,dc=com', optional: true, example: 'ou=people,dc=example,dc=com')
            ->param('bindDn', null, new Nullable(new Text(512, 0)), 'Service account used to search for users. Pass an empty string if the directory allows anonymous search. For example: cn=appwrite,ou=services,dc=example,dc=com', optional: true, example: 'cn=appwrite,ou=services,dc=example,dc=com')
            ->param('bindPassword', null, new Nullable(new PasswordFormat(new Text(256, 0))), 'Service account password. This property is stored securely and cannot be read in future (write-only). Required again when hosts, port, encryption, verification, certificate or bindDn change.', optional: true)
            ->param('userFilter', null, new Nullable(new Text(1024, 0)), 'LDAP filter that finds the user signing in. ' . Client::USERNAME_PLACEHOLDER . ' is replaced with the username they enter and must appear at least once. Pass an empty string to use the default, ' . Client::USER_FILTER_DEFAULT . '. For example: (sAMAccountName={username}) on Active Directory, or (|(uid={username})(mail={username})) to accept a username or an email address.', optional: true, example: Client::USER_FILTER_DEFAULT)
            ->param('requiredGroupDn', null, new Nullable(new Text(1024, 0)), 'Group a user must belong to in order to sign in, checked on every sign-in. Pass an empty string to allow every user the directory authenticates. For example: cn=staff,ou=groups,dc=example,dc=com', optional: true, example: 'cn=staff,ou=groups,dc=example,dc=com')
            ->param('idAttribute', null, new Nullable(new Text(128)), 'Attribute holding the identifier that stays the same when an entry is renamed or moved. Defaults to entryUUID; use objectGUID on Active Directory.', optional: true, example: 'entryUUID')
            ->param('emailAttribute', null, new Nullable(new Text(128)), 'Attribute holding the email address. Defaults to mail.', optional: true, example: 'mail')
            ->param('nameAttribute', null, new Nullable(new Text(128)), 'Attribute holding the display name. Defaults to cn.', optional: true, example: 'cn')
            ->param('phoneAttribute', null, new Nullable(new Text(128, 0)), 'Attribute holding the phone number, in international format. Leave empty, the default, to leave the phone unmapped. For example: mobile', optional: true, example: 'mobile')
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('project')
            ->inject('authorization')
            ->inject('publicHostname')
            ->callback($this->action(...));
    }

    /**
     * @param list<string>|null $hosts
     */
    public function action(
        ?bool $enabled,
        ?array $hosts,
        ?int $port,
        ?string $encryption,
        ?bool $verification,
        ?string $certificate,
        ?int $timeout,
        ?string $baseDn,
        ?string $bindDn,
        ?string $bindPassword,
        ?string $userFilter,
        ?string $requiredGroupDn,
        ?string $idAttribute,
        ?string $emailAttribute,
        ?string $nameAttribute,
        ?string $phoneAttribute,
        Response $response,
        Database $dbForPlatform,
        Document $project,
        Authorization $authorization,
        PublicHostname $publicHostname
    ): void {
        $ldap = $project->getAttribute('ldap', []);
        $auths = $project->getAttribute('auths', []);
        $key = Config::getParam('auth')['ldap']['key'];

        // The stored password is write-only, so it is only reused for the directory it was set
        // for, reached and trusted the way it was then: a caller who cannot read it must not be
        // able to send it to another server, or to this one over a connection nobody verifies.
        if (!\is_null($hosts)) {
            $hosts = \array_map(HostnameOrIP::normalize(...), $hosts);
        }

        $storedPort = (int) ($ldap['port'] ?? 0) ?: Client::port($ldap['encryption'] ?? Client::ENCRYPTION_LDAPS);
        $serverChanged = (!\is_null($hosts) && \array_values($hosts) !== \array_values($ldap['hosts'] ?? []))
            || (!\is_null($port) && $port !== $storedPort)
            || (!\is_null($encryption) && $encryption !== ($ldap['encryption'] ?? ''))
            || (!\is_null($verification) && $verification !== (bool) ($ldap['verification'] ?? true))
            || (!\is_null($certificate) && \trim($certificate) !== \trim((string) ($ldap['certificate'] ?? '')))
            || (!\is_null($bindDn) && $bindDn !== ($ldap['bindDn'] ?? ''));
        if ($serverChanged && \is_null($bindPassword) && ($ldap['bindPassword'] ?? '') !== '') {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Param "bindPassword" is required when changing hosts, port, encryption, verification, certificate or bindDn.');
        }

        // Hosts are refused now if they point inside the network; they are resolved again when dialled.
        foreach ($hosts ?? [] as $host) {
            try {
                $publicHostname->addresses($host);
            } catch (InvalidArgumentException $error) {
                throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Invalid `hosts` param: ' . $error->getMessage());
            }
        }

        // The user filter must find one person by what they type, and the certificate must be
        // one a connection can use: both are refused now rather than on the first sign-in.
        if (!\is_null($userFilter) && \trim($userFilter) !== '') {
            try {
                Client::checkUserFilter(\trim($userFilter));
            } catch (InvalidArgumentException $error) {
                throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Invalid `userFilter` param: ' . $error->getMessage());
            }
        }

        if (!\is_null($certificate) && \trim($certificate) !== '') {
            try {
                Client::checkCertificate($certificate);
            } catch (InvalidArgumentException $error) {
                throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Invalid `certificate` param: ' . $error->getMessage());
            }
        }

        // Apply changes — null means "not provided, keep existing".
        // Empty string explicitly clears a previously-set value.
        $keys = ['hosts', 'port', 'encryption', 'verification', 'certificate', 'timeout', 'baseDn', 'bindDn', 'bindPassword', 'userFilter', 'requiredGroupDn', 'idAttribute', 'emailAttribute', 'nameAttribute', 'phoneAttribute'];
        foreach ($keys as $name) {
            if (!\is_null(${$name})) {
                $ldap[$name] = ${$name};
            }
        }

        $ldap = \array_merge([
            'hosts' => [],
            'port' => 0,
            'encryption' => Client::ENCRYPTION_LDAPS,
            'verification' => true,
            'certificate' => '',
            'timeout' => Client::TIMEOUT_DEFAULT,
            'baseDn' => '',
            'bindDn' => '',
            'bindPassword' => '',
            'userFilter' => '',
            'requiredGroupDn' => '',
            'idAttribute' => Client::ATTRIBUTE_ID,
            'emailAttribute' => Client::ATTRIBUTE_EMAIL,
            'nameAttribute' => Client::ATTRIBUTE_NAME,
            'phoneAttribute' => '',
        ], $ldap);

        $enabled ??= (bool) ($auths[$key] ?? false);

        // An enabled directory must be able to sign someone in: a change to an enabled
        // configuration is checked the same way as enabling it.
        if ($enabled) {
            foreach (['hosts', 'baseDn'] as $name) {
                if (empty($ldap[$name])) {
                    throw new Exception(Exception::PROJECT_LDAP_CONFIG_INVALID, 'Param "' . $name . '" is required to enable LDAP.');
                }
            }

            (new Client($publicHostname, $ldap))->test();
        }

        $auths[$key] = $enabled;

        $project = $authorization->skip(fn () => $dbForPlatform->updateDocument('projects', $project->getId(), new Document([
            'ldap' => $ldap,
            'auths' => $auths,
        ])));
        $authorization->skip(fn () => $dbForPlatform->purgeCachedDocument('projects', $project->getId()));

        $response->dynamic($project, Response::MODEL_PROJECT);
    }
}

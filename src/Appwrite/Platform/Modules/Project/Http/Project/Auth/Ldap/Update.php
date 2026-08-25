<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\Auth\Ldap;

use Appwrite\Auth\LDAP\Client;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Platform\Enum;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Boolean;
use Utopia\Validator\Integer;
use Utopia\Validator\Nullable;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

class Update extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'updateLdap';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/project/auth/ldap')
            ->desc('Update project LDAP')
            ->groups(['api', 'project'])
            ->label('scope', 'project.write')
            ->label('event', 'authMethod.[methodId].update')
            ->label('audits.event', 'project.auth.ldap.update')
            ->label('audits.resource', 'project.auth/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'project',
                group: null,
                name: 'updateLDAP',
                description: <<<EOT
                Configure the LDAP directory this project authenticates against.

                Enabling validates the configuration by connecting and binding with the service account, so a project cannot be left enabled with settings that cannot complete a sign-in.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_AUTH_LDAP,
                    )
                ],
            ))
            ->param('host', null, new Nullable(new Text(256, 0)), 'Directory hostname or IP address.', optional: true)
            ->param('port', null, new Nullable(new Integer()), 'Directory port. Defaults to 389, or 636 with SSL.', optional: true)
            ->param('encryption', null, new Nullable(new WhiteList(Client::ENCRYPTIONS, true)), 'Transport security. A simple bind sends the password in the clear, so "none" should only be used on a trusted network.', optional: true, enum: new Enum(name: 'ProjectAuthLdapEncryption'))
            ->param('baseDn', null, new Nullable(new Text(512, 0)), 'Subtree the user search starts from. For example: ou=people,dc=example,dc=com', optional: true)
            ->param('bindDn', null, new Nullable(new Text(512, 0)), 'Service account used to search for users. Leave empty if the directory allows anonymous search.', optional: true)
            ->param('bindPassword', null, new Nullable(new Text(512, 0)), 'Service account password.', optional: true)
            ->param('userFilter', null, new Nullable(new Text(1024, 0)), 'Search filter locating the user, containing the ' . Client::PLACEHOLDER . ' placeholder. For example: (uid=' . Client::PLACEHOLDER . ')', optional: true)
            ->param('provisionGroupDn', null, new Nullable(new Text(1024, 0)), 'Optional group the user must belong to for an account to be created, given as its distinguished name, for example cn=staff,ou=groups,dc=example,dc=com. Membership is accepted whether the group lists the user in member or uniqueMember, or the user lists the group in memberOf. Checked on every sign-in, so removing someone from the group revokes their access. Leave empty to allow every user the directory authenticates.', optional: true)
            ->param('emailAttribute', null, new Nullable(new Text(128, 0)), 'Attribute holding the email address. Required, because an account cannot be created without one.', optional: true)
            ->param('nameAttribute', null, new Nullable(new Text(128, 0)), 'Attribute holding the display name.', optional: true)
            ->param('enabled', null, new Nullable(new Boolean()), 'LDAP sign-in status. Setting this to true validates the configuration and throws if the directory cannot be reached.', optional: true)
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('project')
            ->inject('authorization')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    public function action(
        ?string $host,
        ?int $port,
        ?string $encryption,
        ?string $baseDn,
        ?string $bindDn,
        ?string $bindPassword,
        ?string $userFilter,
        ?string $provisionGroupDn,
        ?string $emailAttribute,
        ?string $nameAttribute,
        ?bool $enabled,
        Response $response,
        Database $dbForPlatform,
        Document $project,
        Authorization $authorization,
        Event $queueForEvents
    ): void {
        $queueForEvents->setParam('methodId', 'ldap');

        $auths = $project->getAttribute('auths', []);

        $directories = $project->getAttribute('auths', [])['ldapDirectories'] ?? '[]';
        $directories = \json_decode($directories, true);

        // TODO: Fix when adding array support
        $directory = $directories[0] ?? [];

        // The stored bind password belongs to the host it was entered for.
        // Carrying it over to a different host or bind DN would let a caller
        // with project.write point the connection at a server they control and
        // have Appwrite hand over the service credentials, so the password must
        // be supplied again whenever either changes.
        // Anything that changes where the password goes, or how protected it is
        // in transit, counts: a different port is a different destination, and
        // dropping encryption puts the credential on the wire in the clear.
        $movingDestination = ($host !== null && $host !== ($directory['host'] ?? ''))
            || ($bindDn !== null && $bindDn !== ($directory['bindDn'] ?? ''))
            || ($port !== null && $port !== (int)($directory['port'] ?? Client::DEFAULT_PORT));

        $weakeningTransport = $encryption !== null
            && $encryption !== ($directory['encryption'] ?? Client::ENCRYPTION_TLS)
            && $encryption === Client::ENCRYPTION_NONE;

        if (($movingDestination || $weakeningTransport) && $bindPassword === null && !empty($directory['bindPassword'] ?? '')) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Changing the LDAP host, port, bind DN or encryption requires the bind password to be provided again.');
        }

        $directory = [
            'host' => $host ?? ($directory['host'] ?? ''),
            'port' => $port ?? ($directory['port'] ?? Client::DEFAULT_PORT),
            'encryption' => $encryption ?? ($directory['encryption'] ?? Client::ENCRYPTION_TLS),
            'baseDn' => $baseDn ?? ($directory['baseDn'] ?? ''),
            'bindDn' => $bindDn ?? ($directory['bindDn'] ?? ''),
            'bindPassword' => $bindPassword ?? ($directory['bindPassword'] ?? ''),
            'userFilter' => $userFilter ?? ($directory['userFilter'] ?? '(uid=' . Client::PLACEHOLDER . ')'),
            'provisionGroupDn' => $provisionGroupDn ?? ($directory['provisionGroupDn'] ?? ''),
            'emailAttribute' => $emailAttribute ?? ($directory['emailAttribute'] ?? 'mail'),
            'nameAttribute' => $nameAttribute ?? ($directory['nameAttribute'] ?? 'cn'),
        ];

        if ($enabled === true) {
            try {
                $client = new Client(
                    host: $directory['host'],
                    port: (int)$directory['port'],
                    encryption: $directory['encryption'],
                    baseDn: $directory['baseDn'],
                    bindDn: $directory['bindDn'],
                    bindPassword: $directory['bindPassword'],
                    userFilter: $directory['userFilter'],
                    provisionGroupDn: $directory['provisionGroupDn'],
                    emailAttribute: $directory['emailAttribute'],
                    nameAttribute: $directory['nameAttribute'],
                );

                $client->verify();
            } catch (\Throwable $error) {
                throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Could not enable LDAP: ' . $error->getMessage());
            }
        }

        // TODO: Fix when adding array support
        $auths['ldapDirectories'] = \json_encode([$directory]);

        if (!\is_null($enabled)) {
            $auths[Config::getParam('auth')['ldap']['key']] = $enabled;
        }

        $project = $authorization->skip(fn () => $dbForPlatform->updateDocument('projects', $project->getId(), new Document([
            'auths' => $auths,
        ])));

        $authorization->skip(fn () => $dbForPlatform->purgeCachedDocument('projects', $project->getId()));

        $response->dynamic(new Document([
            '$id' => 'ldap',
            'enabled' => $auths[Config::getParam('auth')['ldap']['key']] ?? false,
            'host' => $directory['host'],
            'port' => (int)$directory['port'],
            'encryption' => $directory['encryption'],
            'baseDn' => $directory['baseDn'],
            'bindDn' => $directory['bindDn'],
            'userFilter' => $directory['userFilter'],
            'provisionGroupDn' => $directory['provisionGroupDn'],
            'emailAttribute' => $directory['emailAttribute'],
            'nameAttribute' => $directory['nameAttribute'],
        ]), Response::MODEL_AUTH_LDAP);
    }
}

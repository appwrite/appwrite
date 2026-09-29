<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\Policies\Passkey;

use Appwrite\Auth\Passkey\Ceremony;
use Appwrite\Auth\Passkey\Validator\Host;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Auth\Passkeys\Origin;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Text;

class Update extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'updateProjectPasskeyPolicy';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/project/policies/passkey')
            ->desc('Update passkey policy')
            ->groups(['api', 'project'])
            ->label('scope', ['policies.write', 'project.policies.write'])
            ->label('event', 'projects.[projectId].policies.[policy].update')
            ->label('audits.event', 'projects.[projectId].policies.[policy].update')
            ->label('audits.resource', 'project/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'project',
                group: 'policies',
                name: 'updatePasskeyPolicy',
                description: <<<EOT
                Configure the relying party passkeys are bound to. The relying party ID is the domain of your application, and origins are the exact web origins allowed to register and sign in with passkeys. Passkeys stay unavailable until both are set and the passkey auth method is enabled. The relying party ID cannot change while users have passkeys, and any change invalidates ceremonies in progress.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_PROJECT,
                    )
                ],
            ))
            ->param('rpId', null, new Text(253, 0), 'Relying party ID: the domain of your application, such as `example.com`. Use `localhost` for local development.', optional: true)
            ->param('origins', null, new ArrayList(new Text(2048), 10), 'Web origins allowed to use passkeys, such as `https://example.com` or `https://app.example.com`. Each must be HTTPS on the relying party ID or one of its subdomains, without a path. HTTP is only allowed for `localhost`. Maximum of 10 origins.', optional: true)
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('dbForProject')
            ->inject('project')
            ->inject('authorization')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    /**
     * @param ?array<string> $origins
     */
    public function action(
        ?string $rpId,
        ?array $origins,
        Response $response,
        Database $dbForPlatform,
        Database $dbForProject,
        Document $project,
        Authorization $authorization,
        Event $queueForEvents,
    ): void {
        $auths = $project->getAttribute('auths', []);
        $current = $auths['passkeyRpId'] ?? '';

        $rpId ??= $current;
        $origins ??= $auths['passkeyOrigins'] ?? [];

        if ($rpId !== '' && !(new Host())->isValid($rpId)) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Invalid `rpId` param: ' . (new Host())->getDescription());
        }

        if ($rpId === '' && !empty($origins)) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Set `rpId` before adding origins.');
        }

        $validator = new Origin($rpId);
        $normalized = [];
        foreach ($origins as $origin) {
            $value = $validator->normalize($origin);
            if ($value === null) {
                throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Invalid origin "' . $origin . '": ' . $validator->getDescription());
            }
            $normalized[] = $value;
        }
        $normalized = \array_values(\array_unique($normalized));

        // Passkeys are bound to the RP ID they were created for, so changing it would strand them
        if ($current !== '' && $rpId !== $current) {
            $passkey = $authorization->skip(fn () => $dbForProject->findOne('authenticators', [
                Query::equal('type', [Ceremony::TYPE]),
                Query::equal('verified', [true]),
            ]));
            if (!$passkey->isEmpty()) {
                throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'The relying party ID cannot change while users have passkeys registered.');
            }
        }

        $auths['passkeyRpId'] = $rpId;
        $auths['passkeyOrigins'] = $normalized;

        $project = $authorization->skip(fn () => $dbForPlatform->updateDocument('projects', $project->getId(), new Document([
            'auths' => $auths,
        ])));
        $authorization->skip(fn () => $dbForPlatform->purgeCachedDocument('projects', $project->getId()));

        $queueForEvents
            ->setParam('projectId', $project->getId())
            ->setParam('policy', 'passkey');

        $response->dynamic($project, Response::MODEL_PROJECT);
    }
}

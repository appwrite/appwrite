<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\AuthMethods;

use Appwrite\Event\Event;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Boolean;
use Utopia\Validator\WhiteList;

class Update extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'updateProjectAuthMethod';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/project/auth-methods/:methodId')
            ->httpAlias('/v1/projects/:projectId/auth/:methodId')
            ->desc('Update project auth method status')
            ->groups(['api', 'project'])
            ->label('scope', 'project.write')
            ->label('event', 'authMethod.[methodId].update')
            ->label('audits.event', 'project.authMethods.[methodId].update')
            ->label('audits.resource', 'project.authMethods/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'project',
                group: null,
                name: 'updateAuthMethod',
                description: <<<EOT
                Update properties of a specific auth method. Use this endpoint to enable or disable a method in your project. 
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_PROJECT,
                    )
                ],
            ))

            ->param('methodId', '', new WhiteList(\array_keys(Config::getParam('auth')), true), 'Auth Method ID. Possible values: ' . implode(',', \array_keys(Config::getParam('auth'))), false)
            ->param('enabled', null, new Boolean(), 'Auth method status.')
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('project')
            ->inject('authorization')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    public function action(
        string $methodId,
        bool $enabled,
        Response $response,
        Database $dbForPlatform,
        Document $project,
        Authorization $authorization,
        Event $queueForEvents
    ): void {
        $auth = Config::getParam('auth')[$methodId] ?? [];
        $authKey = $auth['key'] ?? '';

        $auths = $project->getAttribute('auths', []);
        $auths[$authKey] = $enabled;

        $project = $authorization->skip(fn () => $dbForPlatform->updateDocument('projects', $project->getId(), new Document([
            'auths' => $auths,
        ])));

        $queueForEvents->setParam('methodId', $methodId);

        $response->dynamic($project, Response::MODEL_PROJECT);
    }
}

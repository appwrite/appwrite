<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\Protocols;

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
        return 'updateProjectProtocol';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/project/protocols/:protocolId')
            ->httpAlias('/v1/project/protocols/:protocolId/status')
            ->httpAlias('/v1/projects/:projectId/api')
            ->desc('Update project protocol')
            ->groups(['api', 'project'])
            ->label('scope', 'project.write')
            ->label('event', 'protocols.[protocolId].update')
            ->label('audits.event', 'project.protocols.[protocolId].update')
            ->label('audits.resource', 'project.protocols/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'project',
                group: null,
                name: 'updateProtocol',
                description: <<<EOT
                Update properties of a specific protocol. Use this endpoint to enable or disable a protocol in your project. 
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_PROJECT,
                    )
                ],
            ))
            ->param('protocolId', '', new WhiteList(array_keys(Config::getParam('protocols')), true), 'Protocol name. Can be one of: ' . \implode(', ', array_keys(Config::getParam('protocols'))))
            ->param('enabled', null, new Boolean(), 'Protocol status.')
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('project')
            ->inject('authorization')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    public function action(
        string $protocolId,
        bool $enabled,
        Response $response,
        Database $dbForPlatform,
        Document $project,
        Authorization $authorization,
        Event $queueForEvents,
    ): void {
        $protocols = $project->getAttribute('apis', []);
        $protocols[$protocolId] = $enabled;

        $project = $authorization->skip(fn () => $dbForPlatform->updateDocument('projects', $project->getId(), new Document([
            'apis' => $protocols,
        ])));

        $queueForEvents->setParam('protocolId', $protocolId);

        $response->dynamic($project, Response::MODEL_PROJECT);
    }
}

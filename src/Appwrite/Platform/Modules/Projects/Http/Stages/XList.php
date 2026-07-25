<?php

namespace Appwrite\Platform\Modules\Projects\Http\Stages;

use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;

class XList extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'listStages';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/projects/:projectId/stages')
            ->desc('List stages')
            ->groups(['api', 'projects'])
            ->label('scope', 'stages.read')
            ->label('sdk', new Method(
                namespace: 'projects',
                group: 'stages',
                name: 'listStages',
                description: '/docs/references/projects/list-stages.md',
                auth: [AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_STAGE_LIST,
                    )
                ],
            ))
            ->param('projectId', '', new UID(), 'Project unique ID.')
            ->inject('response')
            ->inject('dbForPlatform')
            ->callback($this->action(...));
    }

    public function action(string $projectId, Response $response, Database $dbForPlatform): void
    {
        $project = $dbForPlatform->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $response->dynamic(new Document([
            'stages' => $this->buildStagesList($project),
        ]), Response::MODEL_STAGE_LIST);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildStagesList(Document $project): array
    {
        $methods = \array_keys(Config::getParam('onboarding', []));
        $byMethod = $project->getAttribute('onboarding', []);
        if (! \is_array($byMethod)) {
            $byMethod = [];
        }

        $out = [];
        foreach ($methods as $method) {
            $row = $byMethod[$method] ?? null;
            $status = \is_array($row) ? ($row['status'] ?? null) : null;

            $at = \is_array($row) ? ($row['at'] ?? '') : '';
            $actorType = \is_array($row) ? ($row['actorType'] ?? '') : '';

            $out[] = [
                'id' => $method,
                'sdk' => $method,
                'status' => $status ?? 'pending',
                'at' => $at,
                'actorType' => $actorType,
            ];
        }

        return $out;
    }
}

<?php

namespace Appwrite\Platform\Modules\Projects\Http\Stages;

use Appwrite\Auth\Key;
use Appwrite\Extend\Exception;
use Appwrite\Locking\Lock;
use Appwrite\Onboarding\Stages;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Response;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Boolean;
use Utopia\Validator\Text;

class Update extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'updateStage';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/projects/:projectId/stages/:stageId')
            ->desc('Update stage')
            ->groups(['api', 'projects'])
            ->label('scope', 'stages.write')
            ->label('audits.event', 'stages.update')
            ->label('audits.resource', 'project/{request.projectId}')
            ->label('sdk', new Method(
                namespace: 'projects',
                group: 'stages',
                name: 'updateStage',
                description: '/docs/references/projects/update-stage.md',
                auth: [AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_STAGE,
                    )
                ],
            ))
            ->param('projectId', '', new UID(), 'Project unique ID.')
            ->param('stageId', '', new Text(128), 'SDK method key (namespace.method).')
            ->param('skip', true, new Boolean(), 'Mark the stage as skipped.', true)
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('apiKey')
            ->inject('user')
            ->inject('mode')
            ->inject('authorization')
            ->inject('lock')
            ->callback($this->action(...));
    }

    public function action(string $projectId, string $stageId, bool $skip, Response $response, Database $dbForPlatform, ?Key $apiKey, User $user, string $mode, Authorization $authorization, Lock $lock): void
    {
        $project = $dbForPlatform->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $this->assertOnboardingMethod($stageId);

        $row = $skip
            ? (new Stages($dbForPlatform, $authorization, $lock))->skip($project, $stageId, $this->resolveActorType($apiKey, $user, $mode))
            : $this->storedRow($project, $stageId);

        $response->dynamic(new Document($this->formatStageRow($stageId, $row)), Response::MODEL_STAGE);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function storedRow(Document $project, string $stageId): ?array
    {
        $stages = $project->getAttribute('onboarding', []);
        $row = \is_array($stages) ? ($stages[$stageId] ?? null) : null;

        return \is_array($row) ? $row : null;
    }

    private function assertOnboardingMethod(string $method): void
    {
        if (! \array_key_exists($method, Config::getParam('onboarding', []))) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Unknown SDK method: ' . $method);
        }
    }

    /**
     * @param  array<string, mixed>|null  $row
     * @return array<string, mixed>
     */
    private function formatStageRow(string $method, ?array $row): array
    {
        $status = \is_array($row) ? ($row['status'] ?? null) : null;
        $at = \is_array($row) ? ($row['at'] ?? '') : '';
        $actorType = \is_array($row) ? ($row['actorType'] ?? '') : '';

        return [
            'id' => $method,
            'sdk' => $method,
            'status' => $status ?? 'pending',
            'at' => $at,
            'actorType' => $actorType,
        ];
    }

    private function resolveActorType(?Key $apiKey, User $user, string $mode): string
    {
        if ($apiKey !== null && $apiKey->getRole() === User::ROLE_KEYS) {
            return match ($apiKey->getType()) {
                API_KEY_ACCOUNT => ACTOR_TYPE_KEY_ACCOUNT,
                API_KEY_ORGANIZATION => ACTOR_TYPE_KEY_ORGANIZATION,
                API_KEY_STANDARD, API_KEY_EPHEMERAL => ACTOR_TYPE_KEY_PROJECT,
                default => ACTOR_TYPE_KEY_PROJECT,
            };
        }

        if (! $user->isEmpty()) {
            return $mode === APP_MODE_ADMIN ? ACTOR_TYPE_ADMIN : ACTOR_TYPE_USER;
        }

        return ACTOR_TYPE_GUEST;
    }
}

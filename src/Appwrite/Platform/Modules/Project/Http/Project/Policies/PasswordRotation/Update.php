<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\Policies\PasswordRotation;

use Appwrite\Event\Event;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Boolean;
use Utopia\Validator\Range;

class Update extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'updatePasswordRotationPolicy';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/project/policies/password-rotation')
            ->desc('Update password rotation policy')
            ->groups(['api', 'project'])
            ->label('scope', ['policies.write', 'project.policies.write'])
            ->label('event', 'projects.[projectId].policies.[policy].update')
            ->label('audits.event', 'projects.[projectId].policies.[policy].update')
            ->label('audits.resource', 'project/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'project',
                group: 'policies',
                name: 'updatePasswordRotationPolicy',
                description: 'Require a password reset when an email/password sign-in uses a password older than the configured duration. Options left out keep their current value.',
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_PROJECT,
                    )
                ],
            ))
            ->param('enabled', null, new Boolean(), 'Enable password rotation. Disabled by default.', optional: true)
            ->param('duration', null, new Range(1, PHP_INT_MAX), 'Maximum password age in days. Must be a positive integer. Defaults to 365 days.', optional: true)
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('project')
            ->inject('authorization')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    public function action(
        ?bool $enabled,
        ?int $duration,
        Response $response,
        Database $dbForPlatform,
        Document $project,
        Authorization $authorization,
        Event $queueForEvents,
    ): void {
        $auths = $project->getAttribute('auths', []);
        $auths['passwordRotation'] = [
            'enabled' => $enabled ?? $auths['passwordRotation']['enabled'] ?? false,
            'duration' => $duration ?? $auths['passwordRotation']['duration'] ?? 365,
        ];

        $project = $authorization->skip(fn () => $dbForPlatform->updateDocument('projects', $project->getId(), new Document([
            'auths' => $auths,
        ])));
        $authorization->skip(fn () => $dbForPlatform->purgeCachedDocument('projects', $project->getId()));

        $queueForEvents
            ->setParam('projectId', $project->getId())
            ->setParam('policy', 'password-rotation');

        $response->dynamic($project, Response::MODEL_PROJECT);
    }
}

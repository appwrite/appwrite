<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\Policies\PasswordStrength\RequireLowercase;

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

class Update extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'updateProjectPasswordStrengthRequireLowercase';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/project/policies/password-strength/require-lowercase')
            ->desc('Update password strength lowercase requirement')
            ->groups(['api', 'project'])
            ->label('scope', ['policies.write', 'project.policies.write'])
            ->label('event', 'projects.[projectId].policies.[policy].update')
            ->label('audits.event', 'projects.[projectId].policies.[policy].update')
            ->label('audits.resource', 'project/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'project',
                group: 'policies',
                name: 'updatePasswordStrengthRequireLowercase',
                description: <<<'EOT'
                Update whether passwords must include at least one lowercase letter.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_PROJECT,
                    ),
                ],
            ))
            ->param('enabled', null, new Boolean, 'Whether passwords must include at least one lowercase letter.')
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('project')
            ->inject('authorization')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    public function action(
        bool $enabled,
        Response $response,
        Database $dbForPlatform,
        Document $project,
        Authorization $authorization,
        Event $queueForEvents,
    ): void {
        $auths = $project->getAttribute('auths', []);
        $auths['passwordStrength'] = \array_merge([
            'minLength' => 8,
            'requireUppercase' => false,
            'requireLowercase' => false,
            'requireNumber' => false,
            'requireSpecialChar' => false,
        ], $auths['passwordStrength'] ?? []);
        $auths['passwordStrength']['requireLowercase'] = $enabled;

        $project = $authorization->skip(fn () => $dbForPlatform->updateDocument('projects', $project->getId(), new Document([
            'auths' => $auths,
        ])));

        $queueForEvents
            ->setParam('projectId', $project->getId())
            ->setParam('policy', 'password-strength.require-lowercase');

        $response->dynamic($project, Response::MODEL_PROJECT);
    }
}

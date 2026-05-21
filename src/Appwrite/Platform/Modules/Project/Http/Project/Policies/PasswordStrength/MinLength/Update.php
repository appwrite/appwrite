<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\Policies\PasswordStrength\MinLength;

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
use Utopia\Validator\Range;

class Update extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'updateProjectPasswordStrengthMinLength';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/project/policies/password-strength/min-length')
            ->desc('Update password strength minimum length')
            ->groups(['api', 'project'])
            ->label('scope', ['policies.write', 'project.policies.write'])
            ->label('event', 'projects.[projectId].policies.[policy].update')
            ->label('audits.event', 'projects.[projectId].policies.[policy].update')
            ->label('audits.resource', 'project/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'project',
                group: 'policies',
                name: 'updatePasswordStrengthMinLength',
                description: <<<'EOT'
                Update the minimum password length required for users in the project.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_PROJECT,
                    ),
                ],
            ))
            ->param('minLength', null, new Range(8, 256), 'Minimum password length. Value must be between 8 and 256. Default is 8.')
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('project')
            ->inject('authorization')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    public function action(
        int $minLength,
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
        $auths['passwordStrength']['minLength'] = $minLength;

        $project = $authorization->skip(fn () => $dbForPlatform->updateDocument('projects', $project->getId(), new Document([
            'auths' => $auths,
        ])));

        $queueForEvents
            ->setParam('projectId', $project->getId())
            ->setParam('policy', 'password-strength.min-length');

        $response->dynamic($project, Response::MODEL_PROJECT);
    }
}

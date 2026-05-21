<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\Policies\PasswordStrength;

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

    public static function getName()
    {
        return 'updateProjectPasswordStrength';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/project/policies/password-strength')
            ->desc('Update password strength policy')
            ->groups(['api', 'project'])
            ->label('scope', ['policies.write', 'project.policies.write'])
            ->label('event', 'projects.[projectId].policies.[policy].update')
            ->label('audits.event', 'projects.[projectId].policies.[policy].update')
            ->label('audits.resource', 'project/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'project',
                group: 'policies',
                name: 'updatePasswordStrength',
                description: <<<EOT
                Update password complexity requirements for users in the project.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_PROJECT,
                    )
                ],
            ))
            ->param('minLength', null, new Range(8, 256), 'Minimum password length. Value must be between 8 and 256. Default is 8.', optional: true)
            ->param('requireUppercase', null, new Boolean(), 'Whether passwords must include at least one uppercase letter.', optional: true)
            ->param('requireLowercase', null, new Boolean(), 'Whether passwords must include at least one lowercase letter.', optional: true)
            ->param('requireNumber', null, new Boolean(), 'Whether passwords must include at least one number.', optional: true)
            ->param('requireSpecialChar', null, new Boolean(), 'Whether passwords must include at least one special character.', optional: true)
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('project')
            ->inject('authorization')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    public function action(
        ?int $minLength,
        ?bool $requireUppercase,
        ?bool $requireLowercase,
        ?bool $requireNumber,
        ?bool $requireSpecialChar,
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

        if ($minLength !== null) {
            $auths['passwordStrength']['minLength'] = $minLength;
        }
        if ($requireUppercase !== null) {
            $auths['passwordStrength']['requireUppercase'] = $requireUppercase;
        }
        if ($requireLowercase !== null) {
            $auths['passwordStrength']['requireLowercase'] = $requireLowercase;
        }
        if ($requireNumber !== null) {
            $auths['passwordStrength']['requireNumber'] = $requireNumber;
        }
        if ($requireSpecialChar !== null) {
            $auths['passwordStrength']['requireSpecialChar'] = $requireSpecialChar;
        }

        $updates = new Document([
            'auths' => $auths,
        ]);

        $project = $authorization->skip(fn () => $dbForPlatform->updateDocument('projects', $project->getId(), $updates));

        $queueForEvents
            ->setParam('projectId', $project->getId())
            ->setParam('policy', 'password-strength');

        $response->dynamic($project, Response::MODEL_PROJECT);
    }
}

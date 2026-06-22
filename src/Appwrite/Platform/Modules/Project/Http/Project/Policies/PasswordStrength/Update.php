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
        return 'updateProjectPasswordStrengthPolicy';
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
                name: 'updatePasswordStrengthPolicy',
                description: <<<'EOT'
                Update the password strength requirements for users in the project.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_POLICY_PASSWORD_STRENGTH,
                    ),
                ],
            ))
            ->param('min', null, new Range(8, 256), 'Minimum password length. Value must be between 8 and 256. Default is 8.', optional: true)
            ->param('uppercase', null, new Boolean(), 'Whether passwords must include at least one uppercase letter.', optional: true)
            ->param('lowercase', null, new Boolean(), 'Whether passwords must include at least one lowercase letter.', optional: true)
            ->param('number', null, new Boolean(), 'Whether passwords must include at least one number.', optional: true)
            ->param('symbols', null, new Boolean(), 'Whether passwords must include at least one symbol.', optional: true)
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('project')
            ->inject('authorization')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    public function action(
        ?int $min,
        ?bool $uppercase,
        ?bool $lowercase,
        ?bool $number,
        ?bool $symbols,
        Response $response,
        Database $dbForPlatform,
        Document $project,
        Authorization $authorization,
        Event $queueForEvents,
    ): void {
        $passwordStrength = [];

        $project = $authorization->skip(fn () => $dbForPlatform->withTransaction(function () use ($dbForPlatform, $project, $min, $uppercase, $lowercase, $number, $symbols, &$passwordStrength) {
            $current = $dbForPlatform->getDocument('projects', $project->getId(), forUpdate: true);

            $auths = $current->getAttribute('auths', []);
            $auths['passwordStrength'] = \array_merge([
                'min' => 8,
                'uppercase' => false,
                'lowercase' => false,
                'number' => false,
                'symbols' => false,
            ], $auths['passwordStrength'] ?? []);

            if ($min !== null) {
                $auths['passwordStrength']['min'] = $min;
            }
            if ($uppercase !== null) {
                $auths['passwordStrength']['uppercase'] = $uppercase;
            }
            if ($lowercase !== null) {
                $auths['passwordStrength']['lowercase'] = $lowercase;
            }
            if ($number !== null) {
                $auths['passwordStrength']['number'] = $number;
            }
            if ($symbols !== null) {
                $auths['passwordStrength']['symbols'] = $symbols;
            }

            $passwordStrength = $auths['passwordStrength'];

            return $dbForPlatform->updateDocument('projects', $current->getId(), new Document([
                'auths' => $auths,
            ]));
        }));

        $queueForEvents
            ->setParam('projectId', $project->getId())
            ->setParam('policy', 'password-strength');

        $response->dynamic(new Document(\array_merge($passwordStrength, [
            '$id' => 'password-strength',
        ])), Response::MODEL_POLICY_PASSWORD_STRENGTH);
    }
}

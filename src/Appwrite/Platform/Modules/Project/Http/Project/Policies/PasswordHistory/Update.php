<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\Policies\PasswordHistory;

use Appwrite\Event\Event;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Logger\Log;
use Utopia\Logger\Logger;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Nullable;
use Utopia\Validator\Range;

class Update extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'updateProjectPasswordHistoryPolicy';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/project/policies/password-history')
            ->httpAlias('/v1/projects/:projectId/auth/password-history')
            ->desc('Update password history policy')
            ->groups(['api', 'project'])
            ->label('scope', 'policies.write')
            ->label('event', 'projects.[projectId].policies.[policy].update')
            ->label('audits.event', 'projects.[projectId].policies.[policy].update')
            ->label('audits.resource', 'project/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'project',
                group: 'policies',
                name: 'updatePasswordHistoryPolicy',
                description: <<<EOT
                Updates one of password strength policies. Based on total length configured, previous password hashes are stored, and users cannot choose a new password that is already stored in the passwird history list, when updating an user password, or setting new one through password recovery.
                
                Keep in mind, while password history policy is disabled, the history is not being stored. Enabling the policy will not have any history on existing users, and it will only start to collect and enforce the policy on password changes since the policy is enabled.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_PROJECT,
                    )
                ],
            ))
            ->param('total', null, new Nullable(new Range(1, APP_LIMIT_COUNT)), 'Set the password history length per user. Value can be between 1 and ' . APP_LIMIT_COUNT . ', or null to disable the limit.')
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('project')
            ->inject('authorization')
            ->inject('queueForEvents')
            ->inject('distributedLockOrFail')
            ->inject('log')
            ->inject('logger')
            ->callback($this->action(...));
    }

    public function action(
        ?int $total,
        Response $response,
        Database $dbForPlatform,
        Document $project,
        Authorization $authorization,
        Event $queueForEvents,
        callable $distributedLockOrFail,
        Log $log,
        ?Logger $logger,
    ): void {
        $project = $distributedLockOrFail("lock:platform:projects:{$project->getId()}", function () use ($project, $total, $dbForPlatform, $authorization) {
            $project = $authorization->skip(fn () => $dbForPlatform->getDocument('projects', $project->getId()));

            $auths = $project->getAttribute('auths', []);
            $auths['passwordHistory'] = \is_null($total) ? 0 : $total;

            return $authorization->skip(fn () => $dbForPlatform->updateDocument('projects', $project->getId(), new Document([
                'auths' => $auths,
            ])));
        }, log: $log, logger: $logger);

        $queueForEvents
            ->setParam('projectId', $project->getId())
            ->setParam('policy', 'password-history');

        $response->dynamic($project, Response::MODEL_PROJECT);
    }
}

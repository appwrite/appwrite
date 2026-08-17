<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\Policies\DenyCorporateEmail;

use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
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

    public static function getName(): string
    {
        return 'updateProjectDenyCorporateEmailPolicy';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/project/policies/deny-corporate-email')
            ->httpAlias('/v1/project/auth/corporate-emails')
            ->desc('Update deny non-corporate email policy')
            ->groups(['api', 'project'])
            ->label('scope', ['policies.write', 'project.policies.write'])
            ->label('event', 'projects.[projectId].policies.[policy].update')
            ->label('audits.event', 'projects.[projectId].policies.[policy].update')
            ->label('audits.resource', 'project/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'project',
                group: 'policies',
                name: 'updateDenyCorporateEmailPolicy',
                description: <<<EOT
                Configures if only corporate email addresses (non-free and non-disposable domains) are allowed during new user sign-ups and email updates.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_PROJECT,
                    ),
                ],
            ))
            ->param('enabled', false, new Boolean(false), 'Set whether to restrict sign-ups and email updates to corporate email addresses only.')
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('plan')
            ->inject('project')
            ->inject('authorization')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    /**
     * @param array<string, mixed> $plan
     */
    public function action(
        bool $enabled,
        Response $response,
        Database $dbForPlatform,
        array $plan,
        Document $project,
        Authorization $authorization,
        Event $queueForEvents,
    ): void {
        if ($enabled && !empty($plan) && !($plan['supportsCorporateEmailValidation'] ?? false)) {
            throw new Exception(Exception::GENERAL_ACCESS_FORBIDDEN, 'Your plan does not support corporate email validation.');
        }

        $project = $dbForPlatform->withTransaction(function () use ($dbForPlatform, $authorization, $project, $enabled): Document {
            $project = $authorization->skip(fn () => $dbForPlatform->getDocument('projects', $project->getId(), forUpdate: true));

            $auths = $project->getAttribute('auths', []);
            $auths['corporateEmails'] = $enabled;

            return $authorization->skip(fn () => $dbForPlatform->updateDocument('projects', $project->getId(), new Document([
                'auths' => $auths,
            ])));
        });
        $authorization->skip(fn () => $dbForPlatform->purgeCachedDocument('projects', $project->getId()));

        $queueForEvents
            ->setParam('projectId', $project->getId())
            ->setParam('policy', 'deny-corporate-email');

        $response->dynamic($project, Response::MODEL_PROJECT);
    }
}

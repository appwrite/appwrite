<?php

namespace Appwrite\Platform\Modules\Functions\Http\Functions\Deployment;

use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Compute\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;

class Update extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'updateFunctionDeployment';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/functions/:functionId/deployment')
            ->httpAlias('/v1/functions/:functionId/deployments/:deploymentId')
            ->desc('Update function\'s deployment')
            ->groups(['api', 'functions'])
            ->label('scope', 'functions.write')
            ->label('resourceType', RESOURCE_TYPE_FUNCTIONS)
            ->label('event', 'functions.[functionId].deployments.[deploymentId].update')
            ->label('audits.event', 'deployment.update')
            ->label('audits.resource', 'function/{request.functionId}')
            ->label('sdk', new Method(
                namespace: 'functions',
                group: 'functions',
                name: 'updateFunctionDeployment',
                description: <<<EOT
                Update the function active deployment. Use this endpoint to switch the code deployment that should be used when visitor opens your function.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_FUNCTION,
                    )
                ]
            ))
            ->param('functionId', '', new UID(), 'Function ID.')
            ->param('deploymentId', '', new UID(), 'Deployment ID.')
            ->inject('project')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->inject('dbForPlatform')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(
        string $functionId,
        string $deploymentId,
        Document $project,
        Response $response,
        Database $dbForProject,
        Event $queueForEvents,
        Database $dbForPlatform,
        Authorization $authorization
    ) {
        $function = $dbForProject->getDocument('functions', $functionId);
        $deployment = $dbForProject->getDocument('deployments', $deploymentId);

        if ($function->isEmpty()) {
            throw new Exception(Exception::FUNCTION_NOT_FOUND);
        }

        if ($deployment->isEmpty()) {
            throw new Exception(Exception::DEPLOYMENT_NOT_FOUND);
        }

        if ($deployment->getAttribute('status') !== 'ready') {
            throw new Exception(Exception::BUILD_NOT_READY);
        }

        $function = $dbForProject->updateDocument('functions', $function->getId(), new Document(array_merge($function->getArrayCopy(), [
            'deploymentInternalId' => $deployment->getSequence(),
            'deploymentId' => $deployment->getId(),
            'deploymentCreatedAt' => $deployment->getCreatedAt(),
        ])));

        // Inform scheduler if function is still active
        $schedule = $dbForPlatform->getDocument('schedules', $function->getAttribute('scheduleId'));
        $schedule
            ->setAttribute('resourceUpdatedAt', DateTime::now())
            ->setAttribute('schedule', $function->getAttribute('schedule'))
            ->setAttribute('active', !empty($function->getAttribute('schedule')) && !empty($function->getAttribute('deploymentId')));
        $authorization->skip(fn () => $dbForPlatform->updateDocument('schedules', $schedule->getId(), $schedule));

        $queries = [
            Query::equal('trigger', ['manual']),
            Query::equal('type', ['deployment']),
            Query::equal('deploymentResourceType', ['function']),
            Query::equal('deploymentResourceInternalId', [$function->getSequence()]),
            Query::equal('deploymentVcsProviderBranch', ['']),
            Query::equal('projectInternalId', [$project->getSequence()])
        ];

        $authorization->skip(fn () => $dbForPlatform->foreach('rules', function (Document $rule) use ($dbForPlatform, $deployment, $authorization) {
            $rule = $rule
                ->setAttribute('deploymentId', $deployment->getId())
                ->setAttribute('deploymentInternalId', $deployment->getSequence());

            $authorization->skip(fn () => $dbForPlatform->updateDocument('rules', $rule->getId(), $rule));
        }, $queries));

        $queueForEvents
            ->setParam('functionId', $function->getId())
            ->setParam('deploymentId', $deployment->getId());

        $response->dynamic($function, Response::MODEL_FUNCTION);
    }
}

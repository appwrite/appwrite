<?php

namespace Appwrite\Platform\Modules\Functions\Http\Functions;

use Appwrite\Event\Build;
use Appwrite\Event\Event;
use Appwrite\Event\Validator\FunctionEvent;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Compute\Base;
use Appwrite\Platform\Modules\Compute\Validator\Specification;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Task\Validator\Cron;
use Appwrite\Utopia\Response;
use Executor\Executor;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Roles;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Swoole\Request;
use Utopia\System\System;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Boolean;
use Utopia\Validator\Nullable;
use Utopia\Validator\Range;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;
use Utopia\VCS\Adapter\Git\GitHub;

class Update extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'updateFunction';
    }

    public function __construct()
    {
        $this->setHttpMethod(Action::HTTP_REQUEST_METHOD_PUT)
            ->setHttpPath('/v1/functions/:functionId')
            ->desc('Update function')
            ->groups(['api', 'functions'])
            ->label('scope', 'functions.write')
            ->label('event', 'functions.[functionId].update')
            ->label('resourceType', RESOURCE_TYPE_FUNCTIONS)
            ->label('audits.event', 'function.update')
            ->label('audits.resource', 'function/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'functions',
                group: 'functions',
                name: 'update',
                description: <<<EOT
                Update function by its unique ID.
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
            ->param('name', '', new Text(128), 'Function name. Max length: 128 chars.')
            ->param('runtime', '', new WhiteList(array_keys(Config::getParam('runtimes')), true), 'Execution runtime.', true)
            ->param('execute', [], new Roles(APP_LIMIT_ARRAY_PARAMS_SIZE), 'An array of role strings with execution permissions. By default no user is granted with any execute permissions. [learn more about roles](https://appwrite.io/docs/permissions#permission-roles). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' roles are allowed, each 64 characters long.', true)
            ->param('events', [], new ArrayList(new FunctionEvent(), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Events list. Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' events are allowed.', true)
            ->param('schedule', '', new Cron(), 'Schedule CRON syntax.', true)
            ->param('timeout', 15, new Range(1, (int) System::getEnv('_APP_FUNCTIONS_TIMEOUT', 900)), 'Maximum execution time in seconds.', true)
            ->param('enabled', true, new Boolean(), 'Is function enabled? When set to \'disabled\', users cannot access the function but Server SDKs with and API key can still access the function. No data is lost when this is toggled.', true)
            ->param('logging', true, new Boolean(), 'When disabled, executions will exclude logs and errors, and will be slightly faster.', true)
            ->param('entrypoint', '', new Text(1028, 0), 'Entrypoint File. This path is relative to the "providerRootDirectory".', true)
            ->param('commands', '', new Text(8192, 0), 'Build Commands.', true)
            ->param('scopes', [], new ArrayList(new WhiteList(array_keys(Config::getParam('scopes')), true), APP_LIMIT_ARRAY_PARAMS_SIZE), 'List of scopes allowed for API Key auto-generated for every execution. Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' scopes are allowed.', true)
            ->param('installationId', '', new Text(128, 0), 'Appwrite Installation ID for VCS (Version Controle System) deployment.', true)
            ->param('providerRepositoryId', null, new Nullable(new Text(128, 0)), 'Repository ID of the repo linked to the function', true)
            ->param('providerBranch', '', new Text(128, 0), 'Production branch for the repo linked to the function', true)
            ->param('providerSilentMode', false, new Boolean(), 'Is the VCS (Version Control System) connection in silent mode for the repo linked to the function? In silent mode, comments will not be made on commits and pull requests.', true)
            ->param('providerRootDirectory', '', new Text(128, 0), 'Path to function code in the linked repo.', true)
            ->param('specification', fn (array $plan) => $this->getDefaultSpecification($plan), fn (array $plan) => new Specification(
                $plan,
                Config::getParam('specifications', []),
                System::getEnv('_APP_COMPUTE_CPUS', 0),
                System::getEnv('_APP_COMPUTE_MEMORY', 0)
            ), 'Runtime specification for the function and builds.', true, ['plan'])
            ->inject('request')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('project')
            ->inject('queueForEvents')
            ->inject('queueForBuilds')
            ->inject('dbForPlatform')
            ->inject('gitHub')
            ->inject('executor')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(
        string $functionId,
        string $name,
        string $runtime,
        array $execute,
        array $events,
        string $schedule,
        int $timeout,
        bool $enabled,
        bool $logging,
        string $entrypoint,
        string $commands,
        array $scopes,
        string $installationId,
        ?string $providerRepositoryId,
        string $providerBranch,
        bool $providerSilentMode,
        string $providerRootDirectory,
        string $specification,
        Request $request,
        Response $response,
        Database $dbForProject,
        Document $project,
        Event $queueForEvents,
        Build $queueForBuilds,
        Database $dbForPlatform,
        GitHub $github,
        Executor $executor,
        Authorization $authorization
    ) {
        // TODO: If only branch changes, re-deploy
        $function = $dbForProject->getDocument('functions', $functionId);

        if ($function->isEmpty()) {
            throw new Exception(Exception::FUNCTION_NOT_FOUND);
        }

        $installation = $dbForPlatform->getDocument('installations', $installationId);

        if (!empty($installationId) && $installation->isEmpty()) {
            throw new Exception(Exception::INSTALLATION_NOT_FOUND);
        }

        if (!empty($providerRepositoryId) && (empty($installationId) || empty($providerBranch))) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'When connecting to VCS (Version Control System), you need to provide "installationId" and "providerBranch".');
        }

        if ($function->isEmpty()) {
            throw new Exception(Exception::FUNCTION_NOT_FOUND);
        }

        if (empty($runtime)) {
            $runtime = $function->getAttribute('runtime');
        }

        $enabled ??= $function->getAttribute('enabled', true);

        $repositoryId = $function->getAttribute('repositoryId', '');
        $repositoryInternalId = $function->getAttribute('repositoryInternalId', '');

        if (empty($entrypoint)) {
            $entrypoint = $function->getAttribute('entrypoint', '');
        }

        $isConnected = !empty($function->getAttribute('providerRepositoryId', ''));

        // Git disconnect logic. Disconnecting only when providerRepositoryId is empty, allowing for continue updates without disconnecting git
        if ($isConnected && ($providerRepositoryId !== null && empty($providerRepositoryId))) {
            $repositories = $dbForPlatform->find('repositories', [
                Query::equal('projectInternalId', [$project->getSequence()]),
                Query::equal('resourceInternalId', [$function->getSequence()]),
                Query::equal('resourceType', ['function']),
                Query::limit(100),
            ]);

            foreach ($repositories as $repository) {
                $dbForPlatform->deleteDocument('repositories', $repository->getId());
            }

            $providerRepositoryId = '';
            $installationId = '';
            $providerBranch = '';
            $providerRootDirectory = '';
            $providerSilentMode = true;
            $repositoryId = '';
            $repositoryInternalId = '';
        }

        // Git connect logic
        if (!$isConnected && !empty($providerRepositoryId)) {
            $teamId = $project->getAttribute('teamId', '');

            $repository = $dbForPlatform->createDocument('repositories', new Document([
                '$id' => ID::unique(),
                '$permissions' => [
                    Permission::read(Role::team(ID::custom($teamId))),
                    Permission::update(Role::team(ID::custom($teamId), 'owner')),
                    Permission::update(Role::team(ID::custom($teamId), 'developer')),
                    Permission::delete(Role::team(ID::custom($teamId), 'owner')),
                    Permission::delete(Role::team(ID::custom($teamId), 'developer')),
                ],
                'installationId' => $installation->getId(),
                'installationInternalId' => $installation->getSequence(),
                'projectId' => $project->getId(),
                'projectInternalId' => $project->getSequence(),
                'providerRepositoryId' => $providerRepositoryId,
                'resourceId' => $function->getId(),
                'resourceInternalId' => $function->getSequence(),
                'resourceType' => 'function',
                'providerPullRequestIds' => []
            ]));

            $repositoryId = $repository->getId();
            $repositoryInternalId = $repository->getSequence();
        }

        $live = true;

        if (
            $function->getAttribute('name') !== $name ||
            $function->getAttribute('entrypoint') !== $entrypoint ||
            $function->getAttribute('commands') !== $commands ||
            $function->getAttribute('providerRootDirectory') !== $providerRootDirectory ||
            $function->getAttribute('runtime') !== $runtime
        ) {
            $live = false;
        }

        // Enforce Cold Start if spec limits change.
        if ($function->getAttribute('specification') !== $specification && !empty($function->getAttribute('deploymentId'))) {
            try {
                $executor->deleteRuntime($project->getId(), $function->getAttribute('deploymentId'));
            } catch (\Throwable $th) {
                // Don't throw if the deployment doesn't exist
                if ($th->getCode() !== 404) {
                    throw $th;
                }
            }
        }

        $function = $dbForProject->updateDocument('functions', $function->getId(), new Document(array_merge($function->getArrayCopy(), [
            'execute' => $execute,
            'name' => $name,
            'runtime' => $runtime,
            'events' => $events,
            'schedule' => $schedule,
            'timeout' => $timeout,
            'enabled' => $enabled,
            'live' => $live,
            'logging' => $logging,
            'entrypoint' => $entrypoint,
            'commands' => $commands,
            'scopes' => $scopes,
            'installationId' => $installation->getId(),
            'installationInternalId' => $installation->getSequence(),
            'providerRepositoryId' => $providerRepositoryId,
            'repositoryId' => $repositoryId,
            'repositoryInternalId' => $repositoryInternalId,
            'providerBranch' => $providerBranch,
            'providerRootDirectory' => $providerRootDirectory,
            'providerSilentMode' => $providerSilentMode,
            'specification' => $specification,
            'search' => implode(' ', [$functionId, $name, $runtime]),
        ])));

        // Redeploy logic
        if (!$isConnected && !empty($providerRepositoryId)) {
            $this->redeployVcsFunction($request, $function, $project, $installation, $dbForProject, $queueForBuilds, new Document(), $github, true);
        }

        // Inform scheduler if function is still active
        $schedule = $dbForPlatform->getDocument('schedules', $function->getAttribute('scheduleId'));
        $schedule
            ->setAttribute('resourceUpdatedAt', DateTime::now())
            ->setAttribute('schedule', $function->getAttribute('schedule'))
            ->setAttribute('active', !empty($function->getAttribute('schedule')) && !empty($function->getAttribute('deploymentId')));
        $authorization->skip(fn () => $dbForPlatform->updateDocument('schedules', $schedule->getId(), $schedule));

        $queueForEvents->setParam('functionId', $function->getId());

        $response->dynamic($function, Response::MODEL_FUNCTION);
    }
}

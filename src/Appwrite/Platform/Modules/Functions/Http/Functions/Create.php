<?php

namespace Appwrite\Platform\Modules\Functions\Http\Functions;

use Appwrite\Event\Build;
use Appwrite\Event\Event;
use Appwrite\Event\Func;
use Appwrite\Event\Realtime;
use Appwrite\Event\Validator\FunctionEvent;
use Appwrite\Event\Webhook;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Compute\Base;
use Appwrite\Platform\Modules\Compute\Validator\Specification;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Task\Validator\Cron;
use Appwrite\Utopia\Database\Validator\CustomId;
use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model\Rule;
use Utopia\Abuse\Abuse;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Roles;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Request;
use Utopia\System\System;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Boolean;
use Utopia\Validator\Range;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;
use Utopia\VCS\Adapter\Git\GitHub;

class Create extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'createFunction';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/functions')
            ->desc('Create function')
            ->groups(['api', 'functions'])
            ->label('scope', 'functions.write')
            ->label('event', 'functions.[functionId].create')
            ->label('resourceType', RESOURCE_TYPE_FUNCTIONS)
            ->label('audits.event', 'function.create')
            ->label('audits.resource', 'function/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'functions',
                group: 'functions',
                name: 'create',
                description: <<<EOT
                Create a new function. You can pass a list of [permissions](https://appwrite.io/docs/permissions) to allow different project users or team with access to execute the function using the client API.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_CREATED,
                        model: Response::MODEL_FUNCTION,
                    )
                ],
            ))
            ->param('functionId', '', new CustomId(), 'Function ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
            ->param('name', '', new Text(128), 'Function name. Max length: 128 chars.')
            ->param('runtime', '', new WhiteList(array_keys(Config::getParam('runtimes')), true), 'Execution runtime.')
            ->param('execute', [], new Roles(APP_LIMIT_ARRAY_PARAMS_SIZE), 'An array of role strings with execution permissions. By default no user is granted with any execute permissions. [learn more about roles](https://appwrite.io/docs/permissions#permission-roles). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' roles are allowed, each 64 characters long.', true)
            ->param('events', [], new ArrayList(new FunctionEvent(), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Events list. Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' events are allowed.', true)
            ->param('schedule', '', new Cron(), 'Schedule CRON syntax.', true)
            ->param('timeout', 15, new Range(1, (int) System::getEnv('_APP_FUNCTIONS_TIMEOUT', 900)), 'Function maximum execution time in seconds.', true)
            ->param('enabled', true, new Boolean(), 'Is function enabled? When set to \'disabled\', users cannot access the function but Server SDKs with and API key can still access the function. No data is lost when this is toggled.', true)
            ->param('logging', true, new Boolean(), 'When disabled, executions will exclude logs and errors, and will be slightly faster.', true)
            ->param('entrypoint', '', new Text(1028, 0), 'Entrypoint File. This path is relative to the "providerRootDirectory".', true)
            ->param('commands', '', new Text(8192, 0), 'Build Commands.', true)
            ->param('scopes', [], new ArrayList(new WhiteList(array_keys(Config::getParam('scopes')), true), APP_LIMIT_ARRAY_PARAMS_SIZE), 'List of scopes allowed for API key auto-generated for every execution. Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' scopes are allowed.', true)
            ->param('installationId', '', new Text(128, 0), 'Appwrite Installation ID for VCS (Version Control System) deployment.', true)
            ->param('providerRepositoryId', '', new Text(128, 0), 'Repository ID of the repo linked to the function.', true)
            ->param('providerBranch', '', new Text(128, 0), 'Production branch for the repo linked to the function.', true)
            ->param('providerSilentMode', false, new Boolean(), 'Is the VCS (Version Control System) connection in silent mode for the repo linked to the function? In silent mode, comments will not be made on commits and pull requests.', true)
            ->param('providerRootDirectory', '', new Text(128, 0), 'Path to function code in the linked repo.', true)
            ->param('specification', fn (array $plan) => $this->getDefaultSpecification($plan), fn (array $plan) => new Specification(
                $plan,
                Config::getParam('specifications', []),
                System::getEnv('_APP_COMPUTE_CPUS', 0),
                System::getEnv('_APP_COMPUTE_MEMORY', 0)
            ), 'Runtime specification for the function and builds.', true, ['plan'])
            ->param('templateRepository', '', new Text(128, 0), 'Repository name of the template.', true, deprecated: true)
            ->param('templateOwner', '', new Text(128, 0), 'The name of the owner of the template.', true, deprecated: true)
            ->param('templateRootDirectory', '', new Text(128, 0), 'Path to function code in the template repo.', true, deprecated: true)
            ->param('templateVersion', '', new Text(128, 0), 'Version (tag) for the repo linked to the function template.', true, deprecated: true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('timelimit')
            ->inject('project')
            ->inject('queueForEvents')
            ->inject('queueForBuilds')
            ->inject('queueForRealtime')
            ->inject('queueForWebhooks')
            ->inject('queueForFunctions')
            ->inject('dbForPlatform')
            ->inject('request')
            ->inject('gitHub')
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
        string $providerRepositoryId,
        string $providerBranch,
        bool $providerSilentMode,
        string $providerRootDirectory,
        string $specification,
        string $templateRepository,
        string $templateOwner,
        string $templateRootDirectory,
        string $templateVersion,
        Response $response,
        Database $dbForProject,
        callable $timelimit,
        Document $project,
        Event $queueForEvents,
        Build $queueForBuilds,
        Realtime $queueForRealtime,
        Webhook $queueForWebhooks,
        Func $queueForFunctions,
        Database $dbForPlatform,
        Request $request,
        GitHub $github,
        Authorization $authorization
    ) {

        // Temporary abuse check
        $abuseCheck = function () use ($project, $timelimit, $response) {
            $abuseKey = "projectId:{projectId},url:{url}";
            $abuseLimit = System::getEnv('_APP_FUNCTIONS_CREATION_ABUSE_LIMIT', 50);
            $abuseTime = 86400; // 1 day

            $timeLimit = $timelimit($abuseKey, $abuseLimit, $abuseTime);
            $timeLimit
                ->setParam('{projectId}', $project->getId())
                ->setParam('{url}', '/v1/functions');

            $abuse = new Abuse($timeLimit);
            $remaining = $timeLimit->remaining();
            $limit = $timeLimit->limit();
            $time = $timeLimit->time() + $abuseTime;

            $response
                ->addHeader('X-RateLimit-Limit', $limit)
                ->addHeader('X-RateLimit-Remaining', $remaining)
                ->addHeader('X-RateLimit-Reset', $time);

            $enabled = System::getEnv('_APP_OPTIONS_ABUSE', 'enabled') !== 'disabled';
            if ($enabled && $abuse->check()) {
                throw new Exception(Exception::GENERAL_RATE_LIMIT_EXCEEDED);
            }
        };

        $abuseCheck();

        $functionId = ($functionId == 'unique()') ? ID::unique() : $functionId;

        $allowList = \array_filter(\explode(',', System::getEnv('_APP_FUNCTIONS_RUNTIMES', '')));

        if (!empty($allowList) && !\in_array($runtime, $allowList)) {
            throw new Exception(Exception::FUNCTION_RUNTIME_UNSUPPORTED, 'Runtime "' . $runtime . '" is not supported');
        }

        $installation = $dbForPlatform->getDocument('installations', $installationId);

        if (!empty($installationId) && $installation->isEmpty()) {
            throw new Exception(Exception::INSTALLATION_NOT_FOUND);
        }

        if (!empty($providerRepositoryId) && (empty($installationId) || empty($providerBranch))) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'When connecting to VCS (Version Control System), you need to provide "installationId" and "providerBranch".');
        }

        try {
            $function = $dbForProject->createDocument('functions', new Document([
                '$id' => $functionId,
                'execute' => $execute,
                'enabled' => $enabled,
                'live' => true,
                'logging' => $logging,
                'name' => $name,
                'runtime' => $runtime,
                'deploymentInternalId' => '',
                'deploymentId' => '',
                'events' => $events,
                'schedule' => $schedule,
                'scheduleInternalId' => '',
                'scheduleId' => '',
                'timeout' => $timeout,
                'entrypoint' => $entrypoint,
                'commands' => $commands,
                'scopes' => $scopes,
                'search' => implode(' ', [$functionId, $name, $runtime]),
                'version' => 'v5',
                'installationId' => $installation->getId(),
                'installationInternalId' => $installation->getSequence(),
                'providerRepositoryId' => $providerRepositoryId,
                'repositoryId' => '',
                'repositoryInternalId' => '',
                'providerBranch' => $providerBranch,
                'providerRootDirectory' => $providerRootDirectory,
                'providerSilentMode' => $providerSilentMode,
                'specification' => $specification
            ]));
        } catch (DuplicateException) {
            throw new Exception(Exception::FUNCTION_ALREADY_EXISTS);
        }

        $schedule = $authorization->skip(
            fn () => $dbForPlatform->createDocument('schedules', new Document([
                'region' => $project->getAttribute('region'),
                'resourceType' => SCHEDULE_RESOURCE_TYPE_FUNCTION,
                'resourceId' => $function->getId(),
                'resourceInternalId' => $function->getSequence(),
                'resourceUpdatedAt' => DateTime::now(),
                'projectId' => $project->getId(),
                'schedule'  => $function->getAttribute('schedule'),
                'active' => false,
            ]))
        );

        $function->setAttribute('scheduleId', $schedule->getId());
        $function->setAttribute('scheduleInternalId', $schedule->getSequence());

        // Git connect logic
        if (!empty($providerRepositoryId)) {
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

            $function->setAttribute('repositoryId', $repository->getId());
            $function->setAttribute('repositoryInternalId', $repository->getSequence());
        }

        $function = $dbForProject->updateDocument('functions', $function->getId(), $function);

        // Backwards compatibility with 1.6 behaviour
        $requestFormat = $request->getHeader('x-appwrite-response-format', System::getEnv('_APP_SYSTEM_RESPONSE_FORMAT', ''));
        if ($requestFormat && version_compare($requestFormat, '1.7.0', '<')) {
            // build from template
            $template = new Document([]);
            if (
                !empty($templateRepository)
                && !empty($templateOwner)
                && !empty($templateRootDirectory)
                && !empty($templateVersion)
            ) {
                $template->setAttribute('repositoryName', $templateRepository)
                    ->setAttribute('ownerName', $templateOwner)
                    ->setAttribute('rootDirectory', $templateRootDirectory)
                    ->setAttribute('version', $templateVersion);
            }

            if (!empty($providerRepositoryId)) {
                // Deploy VCS
                $template = new Document();

                $installation = $dbForPlatform->getDocument('installations', $function->getAttribute('installationId'));
                $deployment = $this->redeployVcsFunction(
                    request: $request,
                    function: $function,
                    project: $project,
                    installation: $installation,
                    dbForProject: $dbForProject,
                    queueForBuilds: $queueForBuilds,
                    template: $template,
                    github: $github,
                    activate: true,
                    authorization: $authorization,
                    reference: $providerBranch,
                    referenceType: 'branch'
                );

                $function = $function
                    ->setAttribute('latestDeploymentId', $deployment->getId())
                    ->setAttribute('latestDeploymentInternalId', $deployment->getSequence())
                    ->setAttribute('latestDeploymentCreatedAt', $deployment->getCreatedAt())
                    ->setAttribute('latestDeploymentStatus', $deployment->getAttribute('status', ''));
                $dbForProject->updateDocument('functions', $function->getId(), $function);
            } elseif (!$template->isEmpty()) {
                // Deploy non-VCS from template
                $deploymentId = ID::unique();
                $deployment = $dbForProject->createDocument('deployments', new Document([
                    '$id' => $deploymentId,
                    '$permissions' => [
                        Permission::read(Role::any()),
                        Permission::update(Role::any()),
                        Permission::delete(Role::any()),
                    ],
                    'resourceId' => $function->getId(),
                    'resourceInternalId' => $function->getSequence(),
                    'resourceType' => 'functions',
                    'entrypoint' => $function->getAttribute('entrypoint', ''),
                    'buildCommands' => $function->getAttribute('commands', ''),
                    'type' => 'manual',
                    'activate' => true,
                ]));

                $function = $function
                    ->setAttribute('latestDeploymentId', $deployment->getId())
                    ->setAttribute('latestDeploymentInternalId', $deployment->getSequence())
                    ->setAttribute('latestDeploymentCreatedAt', $deployment->getCreatedAt())
                    ->setAttribute('latestDeploymentStatus', $deployment->getAttribute('status', ''));
                $dbForProject->updateDocument('functions', $function->getId(), $function);

                $queueForBuilds
                    ->setType(BUILD_TYPE_DEPLOYMENT)
                    ->setResource($function)
                    ->setDeployment($deployment)
                    ->setTemplate($template);
            }

            $functionsDomain = System::getEnv('_APP_DOMAIN_FUNCTIONS', '');
            if (!empty($functionsDomain)) {
                $routeSubdomain = ID::unique();
                $domain = "{$routeSubdomain}.{$functionsDomain}";
                // TODO: (@Meldiron) Remove after 1.7.x migration
                $isMd5 = System::getEnv('_APP_RULES_FORMAT') === 'md5';
                $ruleId = $isMd5 ? md5($domain) : ID::unique();

                $rule = $authorization->skip(
                    fn () => $dbForPlatform->createDocument('rules', new Document([
                        '$id' => $ruleId,
                        'projectId' => $project->getId(),
                        'projectInternalId' => $project->getSequence(),
                        'domain' => $domain,
                        'status' => 'verified',
                        'type' => 'deployment',
                        'trigger' => 'manual',
                        'deploymentId' => !isset($deployment) || $deployment->isEmpty() ? '' : $deployment->getId(),
                        'deploymentInternalId' => !isset($deployment) || $deployment->isEmpty() ? '' : $deployment->getSequence(),
                        'deploymentResourceType' => 'function',
                        'deploymentResourceId' => $function->getId(),
                        'deploymentResourceInternalId' => $function->getSequence(),
                        'deploymentVcsProviderBranch' => '',
                        'certificateId' => '',
                        'search' => implode(' ', [$ruleId, $domain]),
                        'owner' => 'Appwrite',
                        'region' => $project->getAttribute('region')
                    ]))
                );

                $ruleModel = new Rule();
                $ruleCreate =
                    $queueForEvents
                        ->setProject($project)
                        ->setEvent('rules.[ruleId].create')
                        ->setParam('ruleId', $rule->getId())
                        ->setPayload($rule->getArrayCopy(array_keys($ruleModel->getRules())));

                /** Trigger Webhook */
                $queueForWebhooks
                    ->from($ruleCreate)
                    ->trigger();

                /** Trigger Functions */
                $queueForFunctions
                    ->from($ruleCreate)
                    ->trigger();

                /** Trigger Realtime Events */
                $queueForRealtime
                    ->from($ruleCreate)
                    ->setSubscribers(['console', $project->getId()])
                    ->trigger();
            }
        }

        $queueForEvents->setParam('functionId', $function->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($function, Response::MODEL_FUNCTION);
    }
}

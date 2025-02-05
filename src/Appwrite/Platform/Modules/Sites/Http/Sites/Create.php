<?php

namespace Appwrite\Platform\Modules\Sites\Http\Sites;

use Appwrite\Event\Build;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Messaging\Adapter\Realtime;
use Appwrite\Platform\Modules\Compute\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Sites\Validator\FrameworkSpecification;
use Appwrite\Utopia\Database\Validator\CustomId;
use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model\Rule;
use Utopia\App;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Validator\Authorization;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Swoole\Request;
use Utopia\System\System;
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
        return 'createSite';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/sites')
            ->desc('Create site')
            ->groups(['api', 'sites'])
            ->label('scope', 'sites.write')
            ->label('event', 'sites.[siteId].create')
            ->label('audits.event', 'site.create')
            ->label('audits.resource', 'site/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'sites',
                name: 'create',
                description: <<<EOT
                Create a new site.
                EOT,
                auth: [AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_CREATED,
                        model: Response::MODEL_SITE,
                    )
                ],
            ))
            ->param('siteId', '', new CustomId(), 'Site ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
            ->param('name', '', new Text(128), 'Site name. Max length: 128 chars.')
            ->param('framework', '', new WhiteList(array_keys(Config::getParam('frameworks')), true), 'Sites framework.')
            ->param('enabled', true, new Boolean(), 'Is site enabled? When set to \'disabled\', users cannot access the site but Server SDKs with and API key can still access the site. No data is lost when this is toggled.', true) // TODO: Add logging param later
            ->param('timeout', 15, new Range(1, (int) System::getEnv('_APP_COMPUTE_TIMEOUT', 900)), 'Maximum request time in seconds.', true)
            ->param('installCommand', '', new Text(8192, 0), 'Install Command.', true)
            ->param('buildCommand', '', new Text(8192, 0), 'Build Command.', true)
            ->param('outputDirectory', '', new Text(8192, 0), 'Output Directory for site.', true)
            ->param('subdomain', '', new CustomId(), 'Unique custom sub-domain. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.', true)
            ->param('buildRuntime', '', new WhiteList(array_keys(Config::getParam('runtimes')), true), 'Runtime to use during build step.')
            ->param('adapter', '', new Text(8192, 0), 'Framework adapter. Allows: static, ssr', true)
            ->param('installationId', '', new Text(128, 0), 'Appwrite Installation ID for VCS (Version Control System) deployment.', true)
            ->param('fallbackFile', '', new Text(255, 0), 'Fallback file for single page application sites.', true)
            ->param('providerRepositoryId', '', new Text(128, 0), 'Repository ID of the repo linked to the site.', true)
            ->param('providerBranch', '', new Text(128, 0), 'Production branch for the repo linked to the site.', true)
            ->param('providerSilentMode', false, new Boolean(), 'Is the VCS (Version Control System) connection in silent mode for the repo linked to the site? In silent mode, comments will not be made on commits and pull requests.', true)
            ->param('providerRootDirectory', '', new Text(128, 0), 'Path to site code in the linked repo.', true)
            ->param('templateRepository', '', new Text(128, 0), 'Repository name of the template.', true)
            ->param('templateOwner', '', new Text(128, 0), 'The name of the owner of the template.', true)
            ->param('templateRootDirectory', '', new Text(128, 0), 'Path to site code in the template repo.', true)
            ->param('templateVersion', '', new Text(128, 0), 'Version (tag) for the repo linked to the site template.', true)
            ->param('specification', APP_COMPUTE_SPECIFICATION_DEFAULT, fn (array $plan) => new FrameworkSpecification(
                $plan,
                Config::getParam('framework-specifications', []),
                App::getEnv('_APP_COMPUTE_CPUS', APP_COMPUTE_CPUS_DEFAULT),
                App::getEnv('_APP_COMPUTE_MEMORY', APP_COMPUTE_MEMORY_DEFAULT)
            ), 'Framework specification for the site and builds.', true, ['plan'])
            ->inject('request')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('project')
            ->inject('user')
            ->inject('queueForEvents')
            ->inject('queueForBuilds')
            ->inject('dbForPlatform')
            ->inject('gitHub')
            ->callback([$this, 'action']);
    }

    public function action(string $siteId, string $name, string $framework, bool $enabled, int $timeout, string $installCommand, string $buildCommand, string $outputDirectory, string $subdomain, string $buildRuntime, string $adapter, string $installationId, ?string $fallbackFile, string $providerRepositoryId, string $providerBranch, bool $providerSilentMode, string $providerRootDirectory, string $templateRepository, string $templateOwner, string $templateRootDirectory, string $templateVersion, string $specification, Request $request, Response $response, Database $dbForProject, Document $project, Document $user, Event $queueForEvents, Build $queueForBuilds, Database $dbForPlatform, GitHub $github)
    {
        if (!empty($adapter)) {
            $configFramework = Config::getParam('frameworks')[$framework] ?? [];
            $adapters = \array_keys($configFramework['adapters'] ?? []);
            $validator = new WhiteList($adapters, true);
            if (!$validator->isValid($adapter)) {
                throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Adapter not supported for the selected framework.');
            }
        }

        $sitesDomain = System::getEnv('_APP_DOMAIN_SITES', '');
        $routeSubdomain = '';
        $domain = '';

        if (!empty($sitesDomain)) {
            $routeSubdomain = $subdomain ?: ID::unique();
            $domain = "{$routeSubdomain}.{$sitesDomain}";

            $subdomain = Authorization::skip(fn () => $dbForPlatform->getDocument('rules', \md5($domain)));

            if ($subdomain && !$subdomain->isEmpty()) {
                throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Subdomain already exists. Please choose a different subdomain.');
            }
        }

        $siteId = ($siteId == 'unique()') ? ID::unique() : $siteId;

        $allowList = \array_filter(\explode(',', System::getEnv('_APP_SITES_FRAMEWORKS', '')));

        if (!empty($allowList) && !\in_array($framework, $allowList)) {
            throw new Exception(Exception::SITE_FRAMEWORK_UNSUPPORTED, 'Framework "' . $framework . '" is not supported');
        }

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

        $installation = $dbForPlatform->getDocument('installations', $installationId);

        if (!empty($installationId) && $installation->isEmpty()) {
            throw new Exception(Exception::INSTALLATION_NOT_FOUND);
        }

        if (!empty($providerRepositoryId) && (empty($installationId) || empty($providerBranch))) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'When connecting to VCS (Version Control System), you need to provide "installationId" and "providerBranch".');
        }

        $site = $dbForProject->createDocument('sites', new Document([
            '$id' => $siteId,
            'enabled' => $enabled,
            'live' => true,
            'name' => $name,
            'framework' => $framework,
            'deploymentInternalId' => '',
            'deploymentId' => '',
            'timeout' => $timeout,
            'installCommand' => $installCommand,
            'buildCommand' => $buildCommand,
            'outputDirectory' => $outputDirectory,
            'search' => implode(' ', [$siteId, $name, $framework]),
            'fallbackFile' => $fallbackFile,
            'installationId' => $installation->getId(),
            'installationInternalId' => $installation->getInternalId(),
            'providerRepositoryId' => $providerRepositoryId,
            'repositoryId' => '',
            'repositoryInternalId' => '',
            'providerBranch' => $providerBranch,
            'providerRootDirectory' => $providerRootDirectory,
            'providerSilentMode' => $providerSilentMode,
            'specification' => $specification,
            'buildRuntime' => $buildRuntime,
            'adapter' => $adapter,
        ]));

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
                'installationInternalId' => $installation->getInternalId(),
                'projectId' => $project->getId(),
                'projectInternalId' => $project->getInternalId(),
                'providerRepositoryId' => $providerRepositoryId,
                'resourceId' => $site->getId(),
                'resourceInternalId' => $site->getInternalId(),
                'resourceType' => 'site',
                'providerPullRequestIds' => []
            ]));

            $site->setAttribute('repositoryId', $repository->getId());
            $site->setAttribute('repositoryInternalId', $repository->getInternalId());
        }

        $site = $dbForProject->updateDocument('sites', $site->getId(), $site);

        if (!empty($providerRepositoryId)) {
            // Deploy VCS
            $this->redeployVcsSite($request, $site, $project, $installation, $dbForProject, $dbForPlatform, $queueForBuilds, $template, $github);
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
                'resourceId' => $site->getId(),
                'resourceInternalId' => $site->getInternalId(),
                'resourceType' => 'sites',
                'installCommand' => $site->getAttribute('installCommand', ''),
                'buildCommand' => $site->getAttribute('buildCommand', ''),
                'outputDirectory' => $site->getAttribute('outputDirectory', ''),
                'type' => 'manual',
                'search' => implode(' ', [$deploymentId]),
                'activate' => true,
            ]));

            // Preview deployments url
            $projectId = $project->getId();

            $sitesDomain = System::getEnv('_APP_DOMAIN_SITES', '');
            $previewDomain = "{$deploymentId}-{$projectId}.{$sitesDomain}";

            $rule = Authorization::skip(
                fn () => $dbForPlatform->createDocument('rules', new Document([
                    '$id' => \md5($previewDomain),
                    'projectId' => $project->getId(),
                    'projectInternalId' => $project->getInternalId(),
                    'domain' => $previewDomain,
                    'resourceType' => 'deployment',
                    'resourceId' => $deploymentId,
                    'resourceInternalId' => $deployment->getInternalId(),
                    'status' => 'verified',
                    'certificateId' => '',
                ]))
            );

            $queueForBuilds
                ->setType(BUILD_TYPE_DEPLOYMENT)
                ->setResource($site)
                ->setDeployment($deployment)
                ->setTemplate($template);
        }

        if (!empty($sitesDomain)) {
            $rule = Authorization::skip(
                fn () => $dbForPlatform->createDocument('rules', new Document([
                    '$id' => \md5($domain),
                    'projectId' => $project->getId(),
                    'projectInternalId' => $project->getInternalId(),
                    'domain' => $domain,
                    'resourceType' => 'site',
                    'resourceId' => $site->getId(),
                    'resourceInternalId' => $site->getInternalId(),
                    'status' => 'verified',
                    'certificateId' => '',
                ]))
            );

            /** Trigger Webhook */
            $ruleModel = new Rule();
            $ruleCreate =
                $queueForEvents
                ->setClass(Event::WEBHOOK_CLASS_NAME)
                ->setQueue(Event::WEBHOOK_QUEUE_NAME);

            $ruleCreate
                ->setProject($project)
                ->setEvent('rules.[ruleId].create')
                ->setParam('ruleId', $rule->getId())
                ->setPayload($rule->getArrayCopy(array_keys($ruleModel->getRules())))
                ->trigger();

            /** Trigger Sites */
            $ruleCreate
                ->setClass(Event::SITES_CLASS_NAME)
                ->setQueue(Event::SITES_QUEUE_NAME)
                ->trigger();

            /** Trigger realtime event */
            $allEvents = Event::generateEvents('rules.[ruleId].create', [
                'ruleId' => $rule->getId(),
            ]);
            $target = Realtime::fromPayload(
                // Pass first, most verbose event pattern
                event: $allEvents[0],
                payload: $rule,
                project: $project
            );
            Realtime::send(
                projectId: 'console',
                payload: $rule->getArrayCopy(),
                events: $allEvents,
                channels: $target['channels'],
                roles: $target['roles']
            );
            Realtime::send(
                projectId: $project->getId(),
                payload: $rule->getArrayCopy(),
                events: $allEvents,
                channels: $target['channels'],
                roles: $target['roles']
            );
        }

        $queueForEvents->setParam('siteId', $site->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($site, Response::MODEL_SITE);
    }
}

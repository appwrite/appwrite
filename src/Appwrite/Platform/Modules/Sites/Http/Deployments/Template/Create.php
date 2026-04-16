<?php

namespace Appwrite\Platform\Modules\Sites\Http\Deployments\Template;

use Appwrite\Event\Build;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Compute\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Swoole\Request;
use Utopia\System\System;
use Utopia\Validator\Boolean;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;
use Utopia\VCS\Adapter\Git\GitHub;

class Create extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'createTemplateDeployment';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/sites/:siteId/deployments/template')
            ->desc('Create template deployment')
            ->groups(['api', 'sites'])
            ->label('scope', 'sites.write')
            ->label('resourceType', RESOURCE_TYPE_SITES)
            ->label('event', 'sites.[siteId].deployments.[deploymentId].create')
            ->label('audits.event', 'deployment.create')
            ->label('audits.resource', 'site/{request.siteId}')
            ->label('sdk', new Method(
                namespace: 'sites',
                group: 'deployments',
                name: 'createTemplateDeployment',
                description: <<<EOT
                Create a deployment based on a template.

                Use this endpoint with combination of [listTemplates](https://appwrite.io/docs/products/sites/templates) to find the template details.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_ACCEPTED,
                        model: Response::MODEL_DEPLOYMENT,
                    )
                ],
            ))
            ->param('siteId', '', new UID(), 'Site ID.')
            ->param('repository', '', new Text(128, 0), 'Repository name of the template.')
            ->param('owner', '', new Text(128, 0), 'The name of the owner of the template.')
            ->param('rootDirectory', '', new Text(128, 0), 'Path to site code in the template repo.')
            ->param('type', '', new WhiteList(['branch', 'commit', 'tag']), 'Type for the reference provided. Can be commit, branch, or tag')
            ->param('reference', '', new Text(128, 0), 'Reference value, can be a commit hash, branch name, or release tag')
            ->param('activate', false, new Boolean(), 'Automatically activate the deployment when it is finished building.', true)
            ->inject('request')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('dbForPlatform')
            ->inject('project')
            ->inject('queueForEvents')
            ->inject('queueForBuilds')
            ->inject('gitHub')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(
        string $siteId,
        string $repository,
        string $owner,
        string $rootDirectory,
        string $type,
        string $reference,
        bool $activate,
        Request $request,
        Response $response,
        Database $dbForProject,
        Database $dbForPlatform,
        Document $project,
        Event $queueForEvents,
        Build $queueForBuilds,
        GitHub $github,
        Authorization $authorization
    ) {
        $site = $dbForProject->getDocument('sites', $siteId);

        if ($site->isEmpty()) {
            throw new Exception(Exception::SITE_NOT_FOUND);
        }

        $branchUrl = "https://github.com/$owner/$repository/blob/$reference";
        $repositoryUrl = "https://github.com/$owner/$repository";

        $template = new Document([
            'repositoryName' => $repository,
            'ownerName' => $owner,
            'rootDirectory' => $rootDirectory,
            'referenceType' => $type,
            'referenceValue' => $reference
        ]);

        if (!empty($site->getAttribute('providerRepositoryId'))) {
            $installation = $dbForPlatform->getDocument('installations', $site->getAttribute('installationId'));

            $deployment = $this->redeployVcsSite(
                request: $request,
                site: $site,
                project: $project,
                installation: $installation,
                dbForProject: $dbForProject,
                dbForPlatform: $dbForPlatform,
                queueForBuilds: $queueForBuilds,
                template: $template,
                github: $github,
                activate: $activate,
                authorization: $authorization,
            );

            $queueForEvents
                ->setParam('siteId', $site->getId())
                ->setParam('deploymentId', $deployment->getId());

            $response
                ->setStatusCode(Response::STATUS_CODE_ACCEPTED)
                ->dynamic($deployment, Response::MODEL_DEPLOYMENT);

            return;
        }

        $commands = [];
        if (!empty($site->getAttribute('installCommand', ''))) {
            $commands[] = $site->getAttribute('installCommand', '');
        }
        if (!empty($site->getAttribute('buildCommand', ''))) {
            $commands[] = $site->getAttribute('buildCommand', '');
        }

        $deploymentId = ID::unique();
        $deployment = $dbForProject->createDocument('deployments', new Document([
            '$id' => $deploymentId,
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'resourceId' => $site->getId(),
            'resourceInternalId' => $site->getSequence(),
            'resourceType' => 'sites',
            'buildCommands' => \implode(' && ', $commands),
            'buildOutput' => $site->getAttribute('outputDirectory', ''),
            'providerRepositoryName' => $repository,
            'providerRepositoryOwner' => $owner,
            'providerRepositoryUrl' => $repositoryUrl,
            'providerBranchUrl' => $branchUrl,
            'providerBranch' => $type == GitHub::CLONE_TYPE_BRANCH ? $reference : '',
            'adapter' => $site->getAttribute('adapter', ''),
            'fallbackFile' => $site->getAttribute('fallbackFile', ''),
            'type' => 'vcs',
            'activate' => $activate,
        ]));

        $site = $site
            ->setAttribute('latestDeploymentId', $deployment->getId())
            ->setAttribute('latestDeploymentInternalId', $deployment->getSequence())
            ->setAttribute('latestDeploymentCreatedAt', $deployment->getCreatedAt())
            ->setAttribute('latestDeploymentStatus', $deployment->getAttribute('status', ''));
        $dbForProject->updateDocument('sites', $site->getId(), $site);

        $sitesDomain = System::getEnv('_APP_DOMAIN_SITES', '');
        $domain = ID::unique() . "." . $sitesDomain;

        // TODO: (@Meldiron) Remove after 1.7.x migration
        $isMd5 = System::getEnv('_APP_RULES_FORMAT') === 'md5';
        $ruleId = $isMd5 ? md5($domain) : ID::unique();

        $authorization->skip(
            fn () => $dbForPlatform->createDocument('rules', new Document([
                '$id' => $ruleId,
                'projectId' => $project->getId(),
                'projectInternalId' => $project->getSequence(),
                'domain' => $domain,
                'type' => 'deployment',
                'trigger' => 'deployment',
                'deploymentId' => $deployment->isEmpty() ? '' : $deployment->getId(),
                'deploymentInternalId' => $deployment->isEmpty() ? '' : $deployment->getSequence(),
                'deploymentResourceType' => 'site',
                'deploymentResourceId' => $site->getId(),
                'deploymentResourceInternalId' => $site->getSequence(),
                'status' => 'verified',
                'certificateId' => '',
                'owner' => 'Appwrite',
                'region' => $project->getAttribute('region')
            ]))
        );

        $this->updateEmptyManualRule($project, $site, $deployment, $dbForPlatform, $authorization);

        $queueForBuilds
            ->setType(BUILD_TYPE_DEPLOYMENT)
            ->setResource($site)
            ->setDeployment($deployment)
            ->setTemplate($template);

        $queueForEvents
            ->setParam('siteId', $site->getId())
            ->setParam('deploymentId', $deployment->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_ACCEPTED)
            ->dynamic($deployment, Response::MODEL_DEPLOYMENT);
    }
}

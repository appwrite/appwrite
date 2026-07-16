<?php

namespace Appwrite\Platform\Modules\Sites\Http\Deployments\Duplicate;

use Appwrite\Deployment\Backend;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Appwrite\Vcs\Factory as VcsFactory;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Http\Adapter\Swoole\Request;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Storage\Device;
use Utopia\System\System;
use Utopia\VCS\Adapter\Git\GitHub;

class Create extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'createDuplicateDeployment';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/sites/:siteId/deployments/duplicate')
            ->desc('Create duplicate deployment')
            ->groups(['api', 'sites'])
            ->label('scope', 'sites.write')
            ->label('event', 'sites.[siteId].deployments.[deploymentId].update')
            ->label('audits.event', 'deployment.update')
            ->label('audits.resource', 'site/{request.siteId}')
            ->label('usage.resource', 'site/{request.siteId}')
            ->label('sdk', new Method(
                namespace: 'sites',
                group: 'deployments',
                name: 'createDuplicateDeployment',
                description: <<<EOT
                Create a new build for an existing site deployment. This endpoint allows you to rebuild a deployment with the updated site configuration, including its commands and output directory if they have been modified. The build process will be queued and executed asynchronously. The original deployment's code will be preserved and used for the new build.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_ACCEPTED,
                        model: Response::MODEL_DEPLOYMENT,
                    )
                ]
            ))
            ->param('siteId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Site ID.', false, ['dbForProject'])
            ->param('deploymentId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Deployment ID.', false, ['dbForProject'])
            ->inject('request')
            ->inject('response')
            ->inject('project')
            ->inject('dbForProject')
            ->inject('dbForPlatform')
            ->inject('queueForEvents')
            ->inject('deployments')
            ->inject('deviceForSites')
            ->inject('vcsFactory')
            ->inject('authorization')
            ->inject('platform')
            ->callback($this->action(...));
    }

    public function action(
        string $siteId,
        string $deploymentId,
        Request $request,
        Response $response,
        Document $project,
        Database $dbForProject,
        Database $dbForPlatform,
        Event $queueForEvents,
        Backend $deployments,
        Device $deviceForSites,
        VcsFactory $vcsFactory,
        Authorization $authorization,
        array $platform
    ) {
        $site = $dbForProject->getDocument('sites', $siteId);

        if ($site->isEmpty()) {
            throw new Exception(Exception::SITE_NOT_FOUND);
        }
        $deployment = $dbForProject->getDocument('deployments', $deploymentId);

        if ($deployment->isEmpty()) {
            throw new Exception(Exception::DEPLOYMENT_NOT_FOUND);
        }

        // Remote-source deployments (templates / VCS) on the jobs-service
        // backend never store a source tarball — the build sidecar fetches
        // it — so a duplicate re-fetches the same source from the
        // coordinates persisted on the deployment.
        $path = $deployment->getAttribute('sourcePath');
        $hasSource = !empty($path) && $deviceForSites->exists($path);
        $installationId = $deployment->getAttribute('installationId', '');
        $owner = $deployment->getAttribute('providerRepositoryOwner', '');
        $repository = $deployment->getAttribute('providerRepositoryName', '');

        if (!$hasSource && ($owner === '' || $repository === '')) {
            throw new Exception(Exception::DEPLOYMENT_NOT_FOUND);
        }

        $deploymentId = ID::unique();

        $destination = '';
        if ($hasSource) {
            $destination = $deviceForSites->getPath($deploymentId . '.' . \pathinfo('code.tar.gz', PATHINFO_EXTENSION));
            $deviceForSites->transfer($path, $destination, $deviceForSites);
        }

        $commands = [];

        if (!empty($site->getAttribute('installCommand', ''))) {
            $commands[] = $site->getAttribute('installCommand', '');
        }
        if (!empty($site->getAttribute('buildCommand', ''))) {
            $commands[] = $site->getAttribute('buildCommand', '');
        }

        // Cloning the source deployment's attributes onto the new one, with
        // its own $id and no $sequence, tells the service to create it fresh
        // rather than update the deployment being duplicated. A re-fetched
        // source starts unsized; its stat artifact reports the size.
        $deployment->removeAttribute('$sequence');
        $deployment->setAttributes([
            '$id' => $deploymentId,
            'sourcePath' => $destination,
            'sourceSize' => $hasSource ? $deployment->getAttribute('sourceSize', 0) : 0,
            'totalSize' => $hasSource ? $deployment->getAttribute('sourceSize', 0) : 0,
            'buildCommands' => \implode(' && ', $commands),
            'startCommand' => $site->getAttribute('startCommand', ''),
            'buildOutput' => $site->getAttribute('outputDirectory', ''),
            'adapter' => $site->getAttribute('adapter', ''),
            'fallbackFile' => $site->getAttribute('fallbackFile', ''),
            'screenshotLight' => '',
            'screenshotDark' => '',
            'buildStartedAt' => null,
            'buildEndedAt' => null,
            'buildDuration' => 0,
            'buildSize' => 0,
            'buildPath' => '',
            'buildLogs' => '',
            'type' => $request->getHeaderLine('x-sdk-language') === 'cli' ? 'cli' : 'manual',
            // Not inherited: a redeploy always goes live, and the source's own
            // flag is unset by deactivateOthers() once anything newer builds.
            'activate' => true,
        ]);

        if ($hasSource) {
            $deployment = $deployments->createFromUpload($site, $deployment);
        } elseif ($installationId !== '') {
            $installation = $dbForPlatform->getDocument('installations', $installationId);
            if ($installation->isEmpty()) {
                throw new Exception(Exception::INSTALLATION_NOT_FOUND);
            }

            $vcs = $vcsFactory->fromInstallation($installation);

            $ref = $deployment->getAttribute('providerCommitHash') ?: $deployment->getAttribute('providerBranch');
            $deployment = $deployments->createFromUrl(
                $site,
                $deployment,
                $vcs->getRepositoryPresignedUrl($owner, $repository, $ref),
                $deployment->getAttribute('providerRootDirectory', ''),
            );
        } else {
            // Public template repo: providerBranch holds the resolved ref
            // (branch, tag, or commit — see Deployments/Template/Create).
            $deployment = $deployments->createFromRef(
                $site,
                $deployment,
                $owner,
                $repository,
                GitHub::CLONE_TYPE_BRANCH,
                $deployment->getAttribute('providerBranch', ''),
                $deployment->getAttribute('providerRootDirectory', ''),
            );
        }

        // Preview deployments for sites
        $sitesDomain = $platform['sitesDomain'];
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
                'deploymentId' => $deployment->getId(),
                'deploymentInternalId' => $deployment->getSequence(),
                'deploymentResourceType' => 'site',
                'deploymentResourceId' => $site->getId(),
                'deploymentResourceInternalId' => $site->getSequence(),
                'status' => 'verified',
                'certificateId' => '',
                'owner' => 'Appwrite',
                'region' => $project->getAttribute('region')
            ]))
        );

        $queueForEvents
            ->setParam('siteId', $site->getId())
            ->setParam('deploymentId', $deployment->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_ACCEPTED)
            ->dynamic($deployment, Response::MODEL_DEPLOYMENT);
    }
}

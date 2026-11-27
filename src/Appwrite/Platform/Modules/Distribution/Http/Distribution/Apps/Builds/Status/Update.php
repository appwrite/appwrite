<?php

namespace Appwrite\Platform\Modules\Distribution\Http\Distribution\Apps\Builds\Status;

use Appwrite\Distribution\Build\Status;
use Appwrite\Distribution\Builds;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Appwrite\Vcs\Factory as VcsFactory;
use Appwrite\Vcs\InstallationTokens;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\UID;
use Utopia\Lock\Exception\Contention;
use Utopia\Platform\Enum;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;
use Utopia\Validator\WhiteList;

class Update extends Action
{
    use HTTP;

    private const int LOCK_TTL = 60;

    private const float LOCK_TIMEOUT = 10.0;

    private const array REQUESTABLE = [
        Status::Canceled,
    ];

    public static function getName(): string
    {
        return 'updateBuildStatus';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/distribution/apps/:appId/builds/:buildId/status')
            ->desc('Update build status')
            ->groups(['api', 'distribution'])
            ->label('scope', 'distribution.write')
            ->label('resourceType', RESOURCE_TYPE_DISTRIBUTION_APPS)
            ->label('event', 'distributionApps.[appId].builds.[buildId].update')
            ->label('audits.event', 'distribution.build.update')
            ->label('audits.resource', 'distributionApp/{request.appId}')
            ->label('sdk', new Method(
                namespace: 'distribution',
                group: 'builds',
                name: 'updateBuildStatus',
                description: <<<EOT
                Request a status change for a build. Pass `canceled` to stop a build that is still waiting, building or signing. A build that is already ready, failed or canceled cannot change status. To retry a build, create a new one.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_DISTRIBUTION_BUILD,
                    )
                ],
                // Hidden until Distribution is generally available; preview SDK builds show it.
                hide: System::getEnv('_APP_SDK_PREVIEW', 'disabled') !== 'enabled',
            ))
            ->param('appId', '', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'App ID.', false, ['dbForProject'])
            ->param('buildId', '', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'Build ID.', false, ['dbForProject'])
            ->param('status', '', new WhiteList(\array_map(static fn (Status $status): string => $status->value, self::REQUESTABLE), true), 'Requested status: canceled.', enum: new Enum(name: 'DistributionBuildTransition'))
            ->inject('response')
            ->inject('project')
            ->inject('dbForProject')
            ->inject('dbForPlatform')
            ->inject('queueForEvents')
            ->inject('distributionBuilds')
            ->inject('vcsFactory')
            ->inject('installationTokens')
            ->inject('locks')
            ->callback($this->action(...));
    }

    public function action(
        string $appId,
        string $buildId,
        string $status,
        Response $response,
        Document $project,
        Database $dbForProject,
        Database $dbForPlatform,
        Event $queueForEvents,
        Builds $distributionBuilds,
        VcsFactory $vcsFactory,
        InstallationTokens $installationTokens,
        callable $locks,
    ): void {
        $app = $dbForProject->getDocument('distributionApps', $appId);

        if ($app->isEmpty()) {
            throw new Exception(Exception::DISTRIBUTION_APP_NOT_FOUND);
        }

        try {
            $build = $locks(Builds::lock((string) $project->getSequence(), $buildId), self::LOCK_TTL, function () use ($app, $buildId, $status, $dbForProject, $distributionBuilds): Document {
                $build = $dbForProject->getDocument('distributionBuilds', $buildId);

                if ($build->isEmpty() || $build->getAttribute('appInternalId') !== $app->getSequence()) {
                    throw new Exception(Exception::DISTRIBUTION_BUILD_NOT_FOUND);
                }

                $current = Status::from((string) $build->getAttribute('status'));
                $requested = Status::from($status);

                if (!$current->canTransition($requested)) {
                    throw new Exception(Exception::DISTRIBUTION_BUILD_TRANSITION_INVALID, "Build '{$build->getId()}' cannot change from '{$current->value}' to '{$requested->value}'.");
                }

                return $distributionBuilds->abort($build);
            }, self::LOCK_TIMEOUT);
        } catch (Contention) {
            $response->addHeader('Retry-After', '5');
            throw new Exception(Exception::GENERAL_RATE_LIMIT_EXCEEDED, 'The build is busy. Try again.');
        }

        $this->report($app, $build, $project, $dbForPlatform, $distributionBuilds, $vcsFactory, $installationTokens);

        $queueForEvents
            ->setParam('appId', $app->getId())
            ->setParam('buildId', $build->getId());

        $response->dynamic($build, Response::MODEL_DISTRIBUTION_BUILD);
    }

    /**
     * Best effort: the cancel stands whether or not the provider accepts the commit check.
     */
    private function report(Document $app, Document $build, Document $project, Database $dbForPlatform, Builds $distributionBuilds, VcsFactory $vcsFactory, InstallationTokens $installationTokens): void
    {
        try {
            $installation = $dbForPlatform->getDocument('installations', (string) $app->getAttribute('installationId', ''));

            if ($installation->isEmpty() || $installation->getAttribute('projectId') !== $project->getId()) {
                return;
            }

            $installation = $installationTokens->refreshForInstallation($installation, $dbForPlatform, $vcsFactory);
            $distributionBuilds->report($app, $build, $vcsFactory->fromInstallation($installation));
        } catch (\Throwable) {
            return;
        }
    }
}

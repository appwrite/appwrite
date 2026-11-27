<?php

namespace Appwrite\Platform\Modules\Distribution\Http\Distribution\Apps\Builds;

use Appwrite\Distribution\Builds;
use Appwrite\Event\Event;
use Appwrite\Event\Message\Delete as DeleteMessage;
use Appwrite\Event\Publisher\Delete as DeletePublisher;
use Appwrite\Event\Publisher\Usage as UsagePublisher;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Usage\Context;
use Appwrite\Usage\Distribution as DistributionUsage;
use Appwrite\Utopia\Response;
use Appwrite\Vcs\Factory as VcsFactory;
use Appwrite\Vcs\InstallationTokens;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\UID;
use Utopia\Lock\Exception\Contention;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;

class Delete extends Action
{
    use HTTP;

    private const int LOCK_TTL = 60;

    private const float LOCK_TIMEOUT = 10.0;

    public static function getName(): string
    {
        return 'deleteBuild';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/distribution/apps/:appId/builds/:buildId')
            ->desc('Delete build')
            ->groups(['api', 'distribution'])
            ->label('scope', 'distribution.write')
            ->label('resourceType', RESOURCE_TYPE_DISTRIBUTION_APPS)
            ->label('event', 'distributionApps.[appId].builds.[buildId].delete')
            ->label('audits.event', 'distribution.build.delete')
            ->label('audits.resource', 'distributionApp/{request.appId}')
            ->label('sdk', new Method(
                namespace: 'distribution',
                group: 'builds',
                name: 'deleteBuild',
                description: <<<EOT
                Delete a build of an app by its unique ID. A build still in progress is canceled first. Its logs and build outputs are removed with it, and so is the artifact it produced unless a submission references that artifact.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_NOCONTENT,
                        model: Response::MODEL_NONE,
                    )
                ],
                contentType: ContentType::NONE,
                // Hidden until Distribution is generally available; preview SDK builds show it.
                hide: System::getEnv('_APP_SDK_PREVIEW', 'disabled') !== 'enabled',
            ))
            ->param('appId', '', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'App ID.', false, ['dbForProject'])
            ->param('buildId', '', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'Build ID.', false, ['dbForProject'])
            ->inject('response')
            ->inject('dbForProject')
            ->inject('dbForPlatform')
            ->inject('project')
            ->inject('queueForEvents')
            ->inject('publisherForDeletes')
            ->inject('usage')
            ->inject('publisherForUsage')
            ->inject('distributionBuilds')
            ->inject('vcsFactory')
            ->inject('installationTokens')
            ->inject('locks')
            ->callback($this->action(...));
    }

    public function action(
        string $appId,
        string $buildId,
        Response $response,
        Database $dbForProject,
        Database $dbForPlatform,
        Document $project,
        Event $queueForEvents,
        DeletePublisher $publisherForDeletes,
        Context $usage,
        UsagePublisher $publisherForUsage,
        Builds $distributionBuilds,
        VcsFactory $vcsFactory,
        InstallationTokens $installationTokens,
        callable $locks,
    ): void {
        $app = $dbForProject->getDocument('distributionApps', $appId);

        if ($app->isEmpty()) {
            throw new Exception(Exception::DISTRIBUTION_APP_NOT_FOUND);
        }

        $settled = false;

        try {
            $build = $locks(Builds::lock((string) $project->getSequence(), $buildId), self::LOCK_TTL, function () use ($app, $buildId, $project, $dbForProject, $distributionBuilds, $usage, $publisherForUsage, &$settled): Document {
                $build = $dbForProject->getDocument('distributionBuilds', $buildId);

                if ($build->isEmpty() || $build->getAttribute('appInternalId') !== $app->getSequence()) {
                    throw new Exception(Exception::DISTRIBUTION_BUILD_NOT_FOUND);
                }

                if (!\in_array($build->getAttribute('status', ''), Builds::TERMINAL, true)) {
                    $build = $distributionBuilds->abort($build);
                }

                $settled = $this->settle($dbForProject, $project, $app, $build, $usage, $publisherForUsage);

                if (!$dbForProject->deleteDocument('distributionBuilds', $build->getId())) {
                    throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to remove build from DB');
                }

                return $build;
            }, self::LOCK_TIMEOUT);
        } catch (Contention) {
            $response->addHeader('Retry-After', '5');
            throw new Exception(Exception::GENERAL_RATE_LIMIT_EXCEEDED, 'The build is busy. Try again.');
        }

        if ($settled) {
            $this->report($app, $build, $project, $dbForPlatform, $distributionBuilds, $vcsFactory, $installationTokens);
        }

        $publisherForDeletes->enqueue(new DeleteMessage(
            project: $project,
            type: DELETE_TYPE_DOCUMENT,
            document: $build,
        ));

        $artifact = $this->deleteArtifact($dbForProject, $project, $build, $locks);

        if (!$artifact->isEmpty()) {
            $publisherForDeletes->enqueue(new DeleteMessage(
                project: $project,
                type: DELETE_TYPE_DOCUMENT,
                document: $artifact,
            ));
        }

        $queueForEvents
            ->setParam('appId', $app->getId())
            ->setParam('buildId', $build->getId())
            ->setPayload($response->output($build, Response::MODEL_DISTRIBUTION_BUILD));

        $response->noContent();
    }

    private function settle(Database $dbForProject, Document $project, Document $app, Document $build, Context $usage, UsagePublisher $publisherForUsage): bool
    {
        $metered = $dbForProject->updateDocuments('distributionBuilds', new Document([
            'meteredAt' => DateTime::now(),
        ]), [
            Query::equal('$id', [$build->getId()]),
            Query::equal('status', Builds::TERMINAL),
            Query::isNull('meteredAt'),
        ]);

        if ($metered === 0) {
            return false;
        }

        DistributionUsage::publishBuild($usage, $app, $build, $project, $publisherForUsage);

        $dbForProject->updateDocuments('distributionApps', new Document([
            'latestBuildStatus' => (string) $build->getAttribute('status', ''),
        ]), [
            Query::equal('$id', [$app->getId()]),
            Query::equal('latestBuildId', [$build->getId()]),
        ]);

        return true;
    }

    /**
     * Best effort: the delete stands whether or not the provider accepts the commit check.
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

    /**
     * The build's artifact, once deleted. It is kept when any submission, whatever its status,
     * references it, and while a sign or inspect job holds it.
     */
    private function deleteArtifact(Database $dbForProject, Document $project, Document $build, callable $locks): Document
    {
        $artifactId = (string) $build->getAttribute('artifactId', '');

        if ($artifactId === '') {
            return new Document();
        }

        try {
            return $locks(Builds::lock((string) $project->getSequence(), $artifactId), self::LOCK_TTL, function () use ($dbForProject, $artifactId): Document {
                $artifact = $dbForProject->getDocument('distributionArtifacts', $artifactId);

                if ($artifact->isEmpty() || $dbForProject->count('distributionSubmissions', [Query::equal('artifactInternalId', [$artifact->getSequence()])], 1) > 0) {
                    return new Document();
                }

                return $dbForProject->deleteDocument('distributionArtifacts', $artifact->getId()) ? $artifact : new Document();
            }, self::LOCK_TIMEOUT);
        } catch (Contention) {
            return new Document();
        }
    }
}

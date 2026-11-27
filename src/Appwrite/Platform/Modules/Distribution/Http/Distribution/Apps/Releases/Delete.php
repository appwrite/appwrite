<?php

namespace Appwrite\Platform\Modules\Distribution\Http\Distribution\Apps\Releases;

use Appwrite\Distribution\Submission\Status as SubmissionStatus;
use Appwrite\Distribution\Submissions;
use Appwrite\Event\Event;
use Appwrite\Event\Message\Delete as DeleteMessage;
use Appwrite\Event\Publisher\Delete as DeletePublisher;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;

class Delete extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'deleteRelease';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/distribution/apps/:appId/releases/:releaseId')
            ->desc('Delete release')
            ->groups(['api', 'distribution'])
            ->label('scope', 'distribution.write')
            ->label('resourceType', RESOURCE_TYPE_DISTRIBUTION_APPS)
            ->label('event', 'distributionApps.[appId].releases.[releaseId].delete')
            ->label('audits.event', 'distribution.release.delete')
            ->label('audits.resource', 'distributionApp/{request.appId}')
            ->label('sdk', new Method(
                namespace: 'distribution',
                group: 'releases',
                name: 'deleteRelease',
                description: <<<EOT
                Delete a release of an app by its unique ID, together with the records of its finished submissions. A release cannot be deleted while one of its submissions is still in progress, in review or rolling out: cancel it or wait for it to finish first.
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
            ->param('releaseId', '', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'Release ID.', false, ['dbForProject'])
            ->inject('response')
            ->inject('dbForProject')
            ->inject('project')
            ->inject('queueForEvents')
            ->inject('publisherForDeletes')
            ->callback($this->action(...));
    }

    public function action(
        string $appId,
        string $releaseId,
        Response $response,
        Database $dbForProject,
        Document $project,
        Event $queueForEvents,
        DeletePublisher $publisherForDeletes
    ): void {
        $app = $dbForProject->getDocument('distributionApps', $appId);

        if ($app->isEmpty()) {
            throw new Exception(Exception::DISTRIBUTION_APP_NOT_FOUND);
        }

        $release = $dbForProject->getDocument('distributionReleases', $releaseId);

        if ($release->isEmpty() || $release->getAttribute('appInternalId') !== $app->getSequence()) {
            throw new Exception(Exception::DISTRIBUTION_RELEASE_NOT_FOUND);
        }

        if ($this->isInUse($dbForProject, $release)) {
            throw new Exception(Exception::DISTRIBUTION_RELEASE_IN_USE);
        }

        if (!$dbForProject->deleteDocument('distributionReleases', $release->getId())) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to remove release from DB');
        }

        $publisherForDeletes->enqueue(new DeleteMessage(
            project: $project,
            type: DELETE_TYPE_DOCUMENT,
            document: $release,
        ));

        $queueForEvents
            ->setParam('appId', $app->getId())
            ->setParam('releaseId', $release->getId())
            ->setPayload($response->output($release, Response::MODEL_DISTRIBUTION_RELEASE));

        $response->noContent();
    }

    private function isInUse(Database $dbForProject, Document $release): bool
    {
        $reference = Query::equal('releaseInternalId', [$release->getSequence()]);

        return $dbForProject->count('distributionSubmissions', [$reference, Query::equal('status', Submissions::ACTIVE)], 1) > 0
            || $dbForProject->count('distributionSubmissions', [$reference, Query::equal('status', [SubmissionStatus::Published->value]), Query::lessThan('rollout', 1.0)], 1) > 0;
    }
}

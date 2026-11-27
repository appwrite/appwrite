<?php

namespace Appwrite\Platform\Modules\Distribution\Http\Distribution\Apps\Artifacts;

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
use Utopia\Database\Validator\UID;
use Utopia\Lock\Exception\Contention;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;

class Delete extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'deleteArtifact';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/distribution/apps/:appId/artifacts/:artifactId')
            ->desc('Delete artifact')
            ->groups(['api', 'distribution'])
            ->label('scope', 'distribution.write')
            ->label('resourceType', RESOURCE_TYPE_DISTRIBUTION_APPS)
            ->label('event', 'distributionApps.[appId].artifacts.[artifactId].delete')
            ->label('audits.event', 'distribution.artifact.delete')
            ->label('audits.resource', 'distributionApp/{request.appId}')
            ->label('sdk', new Method(
                namespace: 'distribution',
                group: 'artifacts',
                name: 'deleteArtifact',
                description: <<<EOT
                Delete an artifact of an app by its unique ID. The binary is removed from storage with it.
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
            ->param('artifactId', '', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'Artifact ID.', false, ['dbForProject'])
            ->inject('response')
            ->inject('dbForProject')
            ->inject('project')
            ->inject('queueForEvents')
            ->inject('publisherForDeletes')
            ->inject('locks')
            ->callback($this->action(...));
    }

    public function action(
        string $appId,
        string $artifactId,
        Response $response,
        Database $dbForProject,
        Document $project,
        Event $queueForEvents,
        DeletePublisher $publisherForDeletes,
        callable $locks,
    ): void {
        $app = $dbForProject->getDocument('distributionApps', $appId);

        if ($app->isEmpty()) {
            throw new Exception(Exception::DISTRIBUTION_APP_NOT_FOUND);
        }

        $lockKey = 'distribution:artifact:' . $project->getId() . ':' . $app->getId() . ':' . $artifactId;

        try {
            // Shares the upload lock so no chunk lands on storage after the row is gone.
            $artifact = $locks($lockKey, 600, function () use ($app, $artifactId, $dbForProject): Document {
                $artifact = $dbForProject->getDocument('distributionArtifacts', $artifactId);

                if ($artifact->isEmpty() || $artifact->getAttribute('appInternalId') !== $app->getSequence()) {
                    throw new Exception(Exception::DISTRIBUTION_ARTIFACT_NOT_FOUND);
                }

                if (!$dbForProject->deleteDocument('distributionArtifacts', $artifact->getId())) {
                    throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to remove artifact from DB');
                }

                return $artifact;
            }, timeout: 120.0);
        } catch (Contention) {
            $response->addHeader('Retry-After', '5');
            throw new Exception(Exception::GENERAL_RATE_LIMIT_EXCEEDED, 'Artifact upload is busy. Try again.');
        }

        $publisherForDeletes->enqueue(new DeleteMessage(
            project: $project,
            type: DELETE_TYPE_DOCUMENT,
            document: $artifact,
        ));

        $queueForEvents
            ->setParam('appId', $app->getId())
            ->setParam('artifactId', $artifact->getId())
            ->setPayload($response->output($artifact, Response::MODEL_DISTRIBUTION_ARTIFACT));

        $response->noContent();
    }
}

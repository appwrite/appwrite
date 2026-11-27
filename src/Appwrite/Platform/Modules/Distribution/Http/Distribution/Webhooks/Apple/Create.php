<?php

namespace Appwrite\Platform\Modules\Distribution\Http\Distribution\Webhooks\Apple;

use Appwrite\Abuse\Limit;
use Appwrite\Distribution\Platform;
use Appwrite\Distribution\Provider;
use Appwrite\Distribution\Submission\Status;
use Appwrite\Distribution\Submissions;
use Appwrite\Distribution\Webhooks;
use Appwrite\Event\Message\DistributionSubmission as DistributionSubmissionMessage;
use Appwrite\Event\Message\DistributionSubmissionAction;
use Appwrite\Event\Publisher\DistributionSubmission as DistributionSubmissionPublisher;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Cache\Cache;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Distribution\Store\Apple\Webhook;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;

/**
 * An App Store Connect event only triggers a reconcile of the target's live
 * submissions, which re-read the store. Once the signature holds, an event that
 * cannot be applied is a 204, so Apple's delivery log shows it as delivered.
 */
class Create extends Action
{
    use HTTP;

    private const string PING = 'webhookPingCreated';

    public static function getName(): string
    {
        return 'createAppleWebhook';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/distribution/webhooks/apple/:connectionId/:targetId')
            ->desc('Create Apple webhook')
            ->groups(['api'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('abuse', [Limit::failures(['projectId', 'url', 'ip'], 100)])
            ->param('connectionId', '', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'Connection ID.', false, ['dbForProject'])
            ->param('targetId', '', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'Target ID.', false, ['dbForProject'])
            ->inject('request')
            ->inject('response')
            ->inject('project')
            ->inject('dbForProject')
            ->inject('authorization')
            ->inject('cache')
            ->inject('publisherForDistributionSubmissions')
            ->callback($this->action(...));
    }

    public function action(
        string $connectionId,
        string $targetId,
        Request $request,
        Response $response,
        Document $project,
        Database $dbForProject,
        Authorization $authorization,
        Cache $cache,
        DistributionSubmissionPublisher $publisherForDistributionSubmissions,
    ): void {
        // Operators switch Distribution on with _APP_DISTRIBUTION. The api hook enforces it by SDK namespace, which internal routes do not carry.
        if (System::getEnv('_APP_DISTRIBUTION', 'disabled') !== 'enabled') {
            throw new Exception(Exception::GENERAL_SERVICE_DISABLED);
        }

        if ($project->isEmpty() || $project->getId() === 'console') {
            throw new Exception(Exception::USER_UNAUTHORIZED, 'Invalid Apple webhook signature.');
        }

        $connection = $authorization->skip(fn () => $dbForProject->getDocument('distributionConnections', $connectionId));
        if ($connection->isEmpty() || Provider::tryFrom((string) $connection->getAttribute('provider', '')) !== Provider::Apple) {
            throw new Exception(Exception::USER_UNAUTHORIZED, 'Invalid Apple webhook signature.');
        }

        $body = $request->getRawPayload();
        if (!Webhooks::verify($connection, $targetId, $body, $request->getHeaderLine(Webhook::HEADER))) {
            throw new Exception(Exception::USER_UNAUTHORIZED, 'Invalid Apple webhook signature.');
        }

        $data = $this->data($body);
        $eventId = \is_string($data['id'] ?? null) && $data['id'] !== '' ? $data['id'] : null;
        if ($eventId === null || ($data['type'] ?? null) === self::PING || Webhooks::isDuplicate($cache, $project, $connectionId, $targetId, $eventId)) {
            $response->noContent();
            return;
        }

        $target = $authorization->skip(fn () => $dbForProject->getDocument('distributionTargets', $targetId));
        if (
            $target->isEmpty()
            || Platform::tryFrom((string) $target->getAttribute('platform', '')) !== Platform::Ios
            || $target->getAttribute('connectionId') !== $connectionId
        ) {
            $response->noContent();
            return;
        }

        $submissions = $authorization->skip(fn () => $dbForProject->find('distributionSubmissions', [
            Query::equal('targetInternalId', [$target->getSequence()]),
            Query::equal('status', [...Submissions::ACTIVE, Status::Published->value]),
            Query::orderDesc('$sequence'),
            Query::limit(APP_LIMIT_SUBQUERY),
        ]));

        $failed = false;
        foreach ($submissions as $submission) {
            if (!Submissions::isActive((string) $submission->getAttribute('status', ''), (float) $submission->getAttribute('rollout', 1.0))) {
                continue;
            }

            $queued = $publisherForDistributionSubmissions->enqueue(new DistributionSubmissionMessage(
                project: $project,
                action: DistributionSubmissionAction::Reconcile,
                id: $submission->getId(),
            ));
            $failed = $failed || $queued === false;
        }

        if ($failed) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to queue the Apple webhook event.');
        }

        Webhooks::remember($cache, $project, $connectionId, $targetId, $eventId);

        $response->noContent();
    }

    /**
     * @return array<array-key, mixed>
     */
    private function data(string $body): array
    {
        $payload = \json_decode($body, true);

        return \is_array($payload) && \is_array($payload['data'] ?? null) ? $payload['data'] : [];
    }
}

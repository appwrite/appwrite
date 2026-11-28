<?php

namespace Appwrite\Platform\Modules\Distribution\Http\Distribution\SigningIdentities;

use Appwrite\Distribution\Platform;
use Appwrite\Distribution\Provisioning;
use Appwrite\Distribution\Schedules;
use Appwrite\Distribution\Signing\Mode;
use Appwrite\Distribution\Stores;
use Appwrite\Event\Event;
use Appwrite\Event\Message\Func as FunctionMessage;
use Appwrite\Event\Publisher\Func as FunctionPublisher;
use Appwrite\Event\Realtime;
use Appwrite\Event\Webhook;
use Appwrite\Extend\Exception;
use Appwrite\Functions\EventProcessor;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\UID;
use Utopia\Distribution\Store;
use Utopia\Lock\Distributed;
use Utopia\Lock\Exception\Contention;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;
use Utopia\Validator\Boolean;
use Utopia\Validator\Nullable;
use Utopia\Validator\Text;

class Update extends Action
{
    use HTTP;

    private const int LOCK_TTL = 120;

    private const float LOCK_TIMEOUT = 5.0;

    private const string RETRY_AFTER = '5';

    public static function getName(): string
    {
        return 'updateSigningIdentity';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/distribution/signing-identities/:signingIdentityId')
            ->desc('Update signing identity')
            ->groups(['api', 'distribution'])
            ->label('scope', 'distribution.credentials.write')
            ->label('event', 'distributionSigningIdentities.[signingIdentityId].update')
            ->label('audits.event', 'distribution.signingIdentity.update')
            ->label('audits.resource', 'distributionSigningIdentity/{request.signingIdentityId}')
            ->label('sdk', new Method(
                namespace: 'distribution',
                group: 'signingIdentities',
                name: 'updateSigningIdentity',
                description: <<<EOT
                Update a signing identity's name. Set rotate to true to replace the certificate of a managed iOS identity: the team's certificate is re-issued for every managed iOS identity of the same Apple team, each with new App Store profiles, and the old certificate and profiles are removed once all of them hold the new ones. Builds already running with the old certificate fail once it is removed; start them again. Imported identities and managed Android identities cannot be rotated.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_DISTRIBUTION_SIGNING_IDENTITY,
                    )
                ],
                // Hidden until Distribution is generally available; preview SDK builds show it.
                hide: System::getEnv('_APP_SDK_PREVIEW', 'disabled') !== 'enabled',
            ))
            ->param('signingIdentityId', '', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'Signing identity ID.', false, ['dbForProject'])
            ->param('name', null, new Nullable(new Text(128)), 'Signing identity name. Max length: 128 chars.', true)
            ->param('rotate', false, new Boolean(), 'Replace the certificate of a managed iOS identity, and of every managed iOS identity of its Apple team.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('dbForPlatform')
            ->inject('project')
            ->inject('queueForEvents')
            ->inject('queueForRealtime')
            ->inject('publisherForFunctions')
            ->inject('queueForWebhooks')
            ->inject('eventProcessor')
            ->inject('distributionStores')
            ->inject('locks')
            ->callback($this->action(...));
    }

    public function action(
        string $signingIdentityId,
        ?string $name,
        bool $rotate,
        Response $response,
        Database $dbForProject,
        Database $dbForPlatform,
        Document $project,
        Event $queueForEvents,
        Realtime $queueForRealtime,
        FunctionPublisher $publisherForFunctions,
        Webhook $queueForWebhooks,
        EventProcessor $eventProcessor,
        Stores $distributionStores,
        callable $locks,
    ): void {
        $signingIdentity = $dbForProject->getDocument('distributionSigningIdentities', $signingIdentityId);

        if ($signingIdentity->isEmpty()) {
            throw new Exception(Exception::DISTRIBUTION_SIGNING_IDENTITY_NOT_FOUND);
        }

        if ($name === null && !$rotate) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'No attributes to update.');
        }

        if ($rotate) {
            $siblings = [];

            foreach ($this->rotate($dbForProject, $dbForPlatform, $project, $distributionStores, $locks, $response, $signingIdentity) as $identity) {
                if ($identity->getId() === $signingIdentity->getId()) {
                    $signingIdentity = $identity;
                } else {
                    $siblings[] = $identity;
                }
            }

            $this->announce($siblings, $response, $project, $dbForProject, $queueForEvents, $queueForRealtime, $publisherForFunctions, $queueForWebhooks, $eventProcessor);
        }

        if ($name !== null) {
            $signingIdentity = $dbForProject->updateDocument('distributionSigningIdentities', $signingIdentity->getId(), new Document([
                'name' => $name,
            ]));
        }

        $queueForEvents->setParam('signingIdentityId', $signingIdentity->getId());

        $response->dynamic($signingIdentity, Response::MODEL_DISTRIBUTION_SIGNING_IDENTITY);
    }

    /**
     * @return list<Document>
     */
    private function rotate(
        Database $dbForProject,
        Database $dbForPlatform,
        Document $project,
        Stores $distributionStores,
        callable $locks,
        Response $response,
        Document $signingIdentity,
    ): array {
        if ($signingIdentity->getAttribute('platform') !== Platform::Ios->value || $signingIdentity->getAttribute('mode') !== Mode::Managed->value) {
            throw new Exception(Exception::DISTRIBUTION_SIGNING_IDENTITY_INVALID, 'Only a managed iOS signing identity can be rotated.');
        }

        $connection = $dbForProject->getDocument('distributionConnections', (string) $signingIdentity->getAttribute('connectionId', ''));

        if ($connection->isEmpty() || (string) $connection->getSequence() !== (string) $signingIdentity->getAttribute('connectionInternalId', '')) {
            throw new Exception(Exception::DISTRIBUTION_CONNECTION_NOT_FOUND);
        }

        $store = $distributionStores->connection($connection);

        if (!$store instanceof Store\Apple) {
            throw new Exception(Exception::DISTRIBUTION_CONNECTION_INVALID, 'A managed iOS signing identity needs an Apple connection.');
        }

        try {
            $rotated = $locks(
                Provisioning::lock((string) $signingIdentity->getAttribute('teamId', '')),
                self::LOCK_TTL,
                fn (Distributed $lock): array => (new Provisioning($dbForProject))->rotate($store, $lock, $signingIdentity),
                timeout: self::LOCK_TIMEOUT,
            );
        } catch (Contention) {
            $response->addHeader('Retry-After', self::RETRY_AFTER);
            throw new Exception(Exception::GENERAL_RATE_LIMIT_EXCEEDED, 'Another signing change for this Apple team is in progress. Try again.');
        }

        $schedules = new Schedules($dbForPlatform);
        $now = new \DateTimeImmutable();

        foreach ($rotated as $identity) {
            $interval = Provisioning::interval($identity, $now);

            if ($interval > 0) {
                $schedules->write($project, SCHEDULE_RESOURCE_TYPE_DISTRIBUTION_SIGNING_IDENTITY, $identity, $interval);
            }
        }

        return $rotated;
    }

    /**
     * Emits the update event of every other identity the rotation moved to
     * the new certificate. The route's own event covers the requested one,
     * so the payload is cleared for it to take the response.
     *
     * @param list<Document> $identities
     */
    private function announce(
        array $identities,
        Response $response,
        Document $project,
        Database $dbForProject,
        Event $queueForEvents,
        Realtime $queueForRealtime,
        FunctionPublisher $publisherForFunctions,
        Webhook $queueForWebhooks,
        EventProcessor $eventProcessor,
    ): void {
        if ($identities === []) {
            return;
        }

        $functionsEvents = $eventProcessor->getFunctionsEvents($project, $dbForProject);
        $webhooksEvents = $eventProcessor->getWebhooksEvents($project);

        foreach ($identities as $identity) {
            $queueForEvents
                ->setParam('signingIdentityId', $identity->getId())
                ->setPayload($response->output($identity, Response::MODEL_DISTRIBUTION_SIGNING_IDENTITY));

            $queueForRealtime
                ->from($queueForEvents)
                ->trigger();

            $generated = Event::generateEvents($queueForEvents->getEvent(), $queueForEvents->getParams());

            if (\array_any($generated, static fn (string $event): bool => isset($functionsEvents[$event]))) {
                $publisherForFunctions->enqueue(FunctionMessage::fromEvent(
                    event: $queueForEvents->getEvent(),
                    params: $queueForEvents->getParams(),
                    project: $queueForEvents->getProject(),
                    user: $queueForEvents->getUser(),
                    userId: $queueForEvents->getUserId(),
                    payload: $queueForEvents->getPayload(),
                    platform: $queueForEvents->getPlatform(),
                ));
            }

            if (\array_any($generated, static fn (string $event): bool => isset($webhooksEvents[$event]))) {
                $queueForWebhooks
                    ->from($queueForEvents)
                    ->trigger();
            }
        }

        $queueForEvents->setPayload([]);
        $queueForRealtime->reset();
        $queueForWebhooks->reset();
    }
}

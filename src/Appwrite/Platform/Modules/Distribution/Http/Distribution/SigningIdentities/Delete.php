<?php

namespace Appwrite\Platform\Modules\Distribution\Http\Distribution\SigningIdentities;

use Appwrite\Distribution\Platform;
use Appwrite\Distribution\Provisioning;
use Appwrite\Distribution\Schedules;
use Appwrite\Distribution\Signing\Mode;
use Appwrite\Distribution\Stores;
use Appwrite\Event\Event;
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
use Utopia\Distribution\Store;
use Utopia\Lock\Distributed;
use Utopia\Lock\Exception\Contention;
use Utopia\Platform\Scope\HTTP;
use Utopia\Span\Span;
use Utopia\System\System;

class Delete extends Action
{
    use HTTP;

    private const int LOCK_TTL = 120;

    private const float LOCK_TIMEOUT = 5.0;

    private const string RETRY_AFTER = '5';

    public static function getName(): string
    {
        return 'deleteSigningIdentity';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_DELETE)
            ->setHttpPath('/v1/distribution/signing-identities/:signingIdentityId')
            ->desc('Delete signing identity')
            ->groups(['api', 'distribution'])
            ->label('scope', 'distribution.credentials.write')
            ->label('event', 'distributionSigningIdentities.[signingIdentityId].delete')
            ->label('audits.event', 'distribution.signingIdentity.delete')
            ->label('audits.resource', 'distributionSigningIdentity/{request.signingIdentityId}')
            ->label('sdk', new Method(
                namespace: 'distribution',
                group: 'signingIdentities',
                name: 'deleteSigningIdentity',
                description: <<<EOT
                Delete a signing identity. An identity that a target still uses cannot be deleted; remove it from those targets first.

                Deleting a managed iOS identity also deletes its App Store profiles in the Apple Developer account and, when no other managed iOS identity of the same Apple team uses it, the team's distribution certificate. This is best effort: when the identity's Apple connection was removed or App Store Connect refuses, the identity is still deleted and what remains stays in your Apple Developer account.
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
            ->param('signingIdentityId', '', fn (Database $dbForProject) => new UID($dbForProject->getMaxUidLength()), 'Signing identity ID.', false, ['dbForProject'])
            ->inject('response')
            ->inject('project')
            ->inject('dbForProject')
            ->inject('dbForPlatform')
            ->inject('queueForEvents')
            ->inject('distributionStores')
            ->inject('locks')
            ->callback($this->action(...));
    }

    public function action(
        string $signingIdentityId,
        Response $response,
        Document $project,
        Database $dbForProject,
        Database $dbForPlatform,
        Event $queueForEvents,
        Stores $distributionStores,
        callable $locks,
    ): void {
        $signingIdentity = $dbForProject->getDocument('distributionSigningIdentities', $signingIdentityId);

        if ($signingIdentity->isEmpty()) {
            throw new Exception(Exception::DISTRIBUTION_SIGNING_IDENTITY_NOT_FOUND);
        }

        $targets = $dbForProject->count('distributionTargets', [
            Query::equal('signingIdentityInternalId', [$signingIdentity->getSequence()]),
        ], 1);

        if ($targets > 0) {
            throw new Exception(Exception::DISTRIBUTION_SIGNING_IDENTITY_IN_USE);
        }

        if ($signingIdentity->getAttribute('platform') === Platform::Ios->value && $signingIdentity->getAttribute('mode') === Mode::Managed->value) {
            $this->retire($dbForProject, $distributionStores, $locks, $response, $signingIdentity);
        } else {
            $this->remove($dbForProject, $signingIdentity);
        }

        (new Schedules($dbForPlatform))->stop($project, SCHEDULE_RESOURCE_TYPE_DISTRIBUTION_SIGNING_IDENTITY, $signingIdentity->getId());

        $queueForEvents
            ->setParam('signingIdentityId', $signingIdentity->getId())
            ->setPayload($response->output($signingIdentity, Response::MODEL_DISTRIBUTION_SIGNING_IDENTITY));

        $response->noContent();
    }

    /**
     * Deletes a managed iOS identity under its team's lock, so no create or
     * rotation picks up the certificate while it is being removed at Apple.
     */
    private function retire(Database $dbForProject, Stores $distributionStores, callable $locks, Response $response, Document $signingIdentity): void
    {
        $store = $this->store($dbForProject, $distributionStores, $signingIdentity);

        try {
            $removed = $locks(
                Provisioning::lock((string) $signingIdentity->getAttribute('teamId', '')),
                self::LOCK_TTL,
                function (Distributed $lock) use ($dbForProject, $store, $signingIdentity): bool {
                    $this->remove($dbForProject, $signingIdentity);

                    return $store !== null && (new Provisioning($dbForProject))->delete($store, $lock, $signingIdentity);
                },
                timeout: self::LOCK_TIMEOUT,
            );
        } catch (Contention) {
            $response->addHeader('Retry-After', self::RETRY_AFTER);
            throw new Exception(Exception::GENERAL_RATE_LIMIT_EXCEEDED, 'Another signing change for this Apple team is in progress. Try again.');
        }

        Span::add('signing_identity.retired', $removed);
    }

    private function remove(Database $dbForProject, Document $signingIdentity): void
    {
        if (!$dbForProject->deleteDocument('distributionSigningIdentities', $signingIdentity->getId())) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to remove signing identity from DB');
        }
    }

    /**
     * The Apple store of the identity's connection, or null when the
     * connection was removed or replaced.
     */
    private function store(Database $dbForProject, Stores $distributionStores, Document $signingIdentity): ?Store\Apple
    {
        $connection = $dbForProject->getDocument('distributionConnections', (string) $signingIdentity->getAttribute('connectionId', ''));

        if ($connection->isEmpty() || (string) $connection->getSequence() !== (string) $signingIdentity->getAttribute('connectionInternalId', '')) {
            return null;
        }

        try {
            $store = $distributionStores->connection($connection);
        } catch (Exception) {
            return null;
        }

        return $store instanceof Store\Apple ? $store : null;
    }
}

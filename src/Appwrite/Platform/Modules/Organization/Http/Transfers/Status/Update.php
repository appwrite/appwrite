<?php

namespace Appwrite\Platform\Modules\Organization\Http\Transfers\Status;

use Appwrite\Abuse\Limit;
use Appwrite\Event\Context\Audit as AuditContext;
use Appwrite\Event\Realtime;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Organization\Http\Transfers\Action;
use Appwrite\Platform\Modules\Projects\Move;
use Appwrite\Screenshots\Access;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;
use Utopia\Span\Span;
use Utopia\Validator\Text;

class Update extends Action
{
    use HTTP;
    use Move;

    public static function getName()
    {
        return 'updateProjectTransferStatus';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/organization/transfers/:transferId/status')
            ->desc('Update project transfer status')
            ->groups(['api', 'organization'])
            ->label('scope', 'projects.write')
            ->label('audits.event', 'transfer.update')
            ->label('audits.resource', 'project/{response.$id}')
            ->label('abuse', [Limit::attempts(['ip', 'url'], 10)])
            ->label('sdk', new Method(
                namespace: 'organization',
                group: 'transfers',
                name: 'updateProjectTransferStatus',
                description: <<<EOT
                Claim a project with the transfer ID and secret its owner shared with you. The project moves to your organization, and the transfer can't be used again.

                The project's API keys are deleted, because the previous owner may still hold them. Create new keys for the project once you have claimed it.
                EOT,
                auth: [AuthType::ADMIN, AuthType::ORGANIZATION],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_PROJECT,
                    )
                ]
            ))
            ->param('transferId', '', new UID(), 'Transfer ID.')
            ->param('secret', '', new Text(256), 'Transfer secret.')
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('team')
            ->inject('targetUser')
            ->inject('authorization')
            ->inject('auditContext')
            ->inject('queueForRealtime')
            ->inject('getProjectDB')
            ->callback($this->action(...));
    }

    public function action(
        string $transferId,
        string $secret,
        Response $response,
        Database $dbForPlatform,
        Document $team,
        User $targetUser,
        Authorization $authorization,
        AuditContext $auditContext,
        Realtime $queueForRealtime,
        callable $getProjectDB,
    ) {
        [$transfer, $project] = $this->getClaimableTransfer($transferId, $secret, $team, $targetUser, $dbForPlatform, $authorization);

        $auditContext->project = $project;

        $permissions = $this->getPermissions($team->getId(), $project->getId());

        // All or nothing: a failed claim leaves the project where it was and the transfer usable
        $project = $authorization->skip(fn () => $dbForPlatform->withTransaction(function () use ($dbForPlatform, $transfer, $project, $team, $permissions) {
            $this->claim($dbForPlatform, $transfer, $project);

            return $this->moveProject($dbForPlatform, $project, $team, $permissions);
        }));

        $authorization->skip(fn () => $this->announceMove($dbForPlatform, $project, $team, $queueForRealtime));

        // The claim has committed, so a screenshot failure is recorded rather than reported as a failed claim
        try {
            (new Access($dbForPlatform))->grant($getProjectDB($project), $project);
        } catch (\Throwable $th) {
            Span::add('screenshots.error', $th->getMessage());
        }

        $response->dynamic($project, Response::MODEL_PROJECT);
    }

    /**
     * Find the transfer and the project it moves, and check the organization may claim it now.
     *
     * @return array{0: Document, 1: Document} the transfer and its project
     */
    protected function getClaimableTransfer(string $transferId, string $secret, Document $team, User $targetUser, Database $dbForPlatform, Authorization $authorization): array
    {
        if ($team->isEmpty()) {
            throw new Exception(Exception::TEAM_NOT_FOUND);
        }

        // Taking a project in is the same move as giving one away, so the claiming
        // organization answers for it with an owner too. An organization key holds no membership, so it is refused
        if (!$targetUser->isOwner($dbForPlatform, $team->getSequence() ?? '')) {
            throw new Exception(Exception::USER_UNAUTHORIZED, 'Claiming a project requires the owner role on the organization claiming it.');
        }

        $transfer = $authorization->skip(fn () => $dbForPlatform->getDocument('transfers', $transferId));

        if ($transfer->isEmpty() || $transfer->getAttribute('resourceType') !== 'projects') {
            throw new Exception(Exception::TRANSFER_NOT_FOUND);
        }

        if (!\hash_equals($transfer->getAttribute('secret'), $secret)) {
            throw new Exception(Exception::TRANSFER_INVALID_SECRET);
        }

        if (DateTime::formatTz($transfer->getAttribute('expire')) < DateTime::formatTz(DateTime::now())) {
            throw new Exception(Exception::TRANSFER_EXPIRED);
        }

        $recipientInternalId = $transfer->getAttribute('recipientInternalId', '');
        if ($recipientInternalId !== '' && $recipientInternalId !== $team->getSequence()) {
            throw new Exception(Exception::TRANSFER_RECIPIENT_MISMATCH);
        }

        $project = $authorization->skip(fn () => $dbForPlatform->getDocument('projects', $transfer->getAttribute('resourceId')));

        // The project was deleted or moved some other way after the transfer was created
        if ($project->isEmpty() || $project->getAttribute('teamInternalId') !== $transfer->getAttribute('senderInternalId')) {
            throw new Exception(Exception::TRANSFER_NOT_FOUND);
        }

        if ($project->getAttribute('teamInternalId') === $team->getSequence()) {
            throw new Exception(Exception::TRANSFER_ALREADY_OWNED);
        }

        return [$transfer, $project];
    }

    /**
     * The claim's part of the move, run first in its transaction: take the transfer, and revoke what the
     * previous owner issued for the project, since they may still hold it.
     *
     * @param array<string> $keepKeys IDs of the project's keys that go with it to the new owner
     * @param array<string> $keepTransfers IDs of the project's transfers to leave for their own cleanup
     */
    protected function claim(Database $dbForPlatform, Document $transfer, Document $project, array $keepKeys = [], array $keepTransfers = []): void
    {
        // Locked, so a concurrent claim waits here and then finds the transfer gone or the project moved,
        // and a key created for the project waits for the claim and then finds it gone (see Keys\Create)
        $locked = $dbForPlatform->getDocument('transfers', $transfer->getId(), forUpdate: true);
        $current = $dbForPlatform->getDocument('projects', $project->getId(), forUpdate: true);
        if ($locked->isEmpty() || $current->getAttribute('teamInternalId') !== $locked->getAttribute('senderInternalId')) {
            throw new Exception(Exception::TRANSFER_NOT_FOUND);
        }

        $dbForPlatform->deleteDocuments('keys', [
            Query::equal('resourceType', ['projects']),
            Query::equal('resourceInternalId', [$project->getSequence()]),
            ...\array_map(fn (string $id) => Query::notEqual('$id', $id), $keepKeys),
        ]);

        // This transfer and every other link for the project, so none can be used again
        $dbForPlatform->deleteDocuments('transfers', [
            Query::equal('resourceType', ['projects']),
            Query::equal('resourceInternalId', [$project->getSequence()]),
            ...\array_map(fn (string $id) => Query::notEqual('$id', $id), $keepTransfers),
        ]);
    }
}

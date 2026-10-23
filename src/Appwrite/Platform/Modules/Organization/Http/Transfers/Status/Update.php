<?php

namespace Appwrite\Platform\Modules\Organization\Http\Transfers\Status;

use Appwrite\Event\Context\Audit as AuditContext;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Organization\Http\Transfers\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Text;

class Update extends Action
{
    use HTTP;

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
            ->label('abuse-limit', 10)
            ->label('abuse-key', 'ip:{ip},url:{url}')
            ->label('sdk', new Method(
                namespace: 'organization',
                group: 'transfers',
                name: 'updateProjectTransferStatus',
                description: <<<EOT
                Claim a project with the transfer ID and secret its owner shared with you. The project moves to your organization, and the transfer can't be used again.
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
            ->inject('authorization')
            ->inject('auditContext')
            ->callback($this->action(...));
    }

    public function action(
        string $transferId,
        string $secret,
        Response $response,
        Database $dbForPlatform,
        Document $team,
        Authorization $authorization,
        AuditContext $auditContext,
    ) {
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

        $auditContext->project = $project;

        $permissions = $this->getPermissions($team->getId(), $project->getId());

        // All or nothing: a failed claim leaves the project where it was and the transfer usable
        $project = $authorization->skip(fn () => $dbForPlatform->withTransaction(function () use ($dbForPlatform, $transfer, $project, $team, $permissions) {
            // Locked, so a concurrent claim waits here and then finds the transfer gone
            $locked = $dbForPlatform->getDocument('transfers', $transfer->getId(), forUpdate: true);
            if ($locked->isEmpty()) {
                throw new Exception(Exception::TRANSFER_NOT_FOUND);
            }
            $dbForPlatform->deleteDocument('transfers', $locked->getId());

            $project = $dbForPlatform->updateDocument('projects', $project->getId(), new Document([
                'teamId' => $team->getId(),
                'teamInternalId' => $team->getSequence(),
                '$permissions' => $permissions,
            ]));

            foreach (['installations', 'repositories', 'vcsComments'] as $collection) {
                $this->updatePermissions($collection, $project, $permissions, $dbForPlatform);
            }

            // Other links for this project were issued by the previous owner, so they stop working
            $dbForPlatform->deleteDocuments('transfers', [
                Query::equal('resourceType', ['projects']),
                Query::equal('resourceInternalId', [$project->getSequence()]),
            ]);

            return $project;
        }));

        $authorization->skip(fn () => $dbForPlatform->purgeCachedDocument('projects', $project->getId()));

        $response->dynamic($project, Response::MODEL_PROJECT);
    }

    /**
     * Give every document of the project in the collection the new organization's permissions.
     *
     * Pages through all of them, since a project can have more than one page of VCS comments.
     *
     * @param array<string> $permissions
     */
    private function updatePermissions(string $collection, Document $project, array $permissions, Database $dbForPlatform): void
    {
        $limit = 100;
        $cursor = null;

        do {
            $queries = [
                Query::equal('projectInternalId', [$project->getSequence()]),
                Query::limit($limit),
            ];
            if ($cursor !== null) {
                $queries[] = Query::cursorAfter($cursor);
            }

            $documents = $dbForPlatform->find($collection, $queries);

            foreach ($documents as $document) {
                $dbForPlatform->updateDocument($collection, $document->getId(), new Document(['$permissions' => $permissions]));
            }

            $cursor = \end($documents) ?: null;
        } while (\count($documents) === $limit);
    }
}

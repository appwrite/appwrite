<?php

namespace Appwrite\Platform\Modules\Organization\Http\Transfers\Status;

use Appwrite\Event\Context\Audit as AuditContext;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Organization\Http\Transfers\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Auth\Proofs\Token;
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
            ->label('audits.event', 'project.transfer.update')
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
            ->inject('proofForToken')
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
        Token $proofForToken,
        AuditContext $auditContext,
    ) {
        $transfer = $authorization->skip(fn () => $dbForPlatform->getDocument('transfers', $transferId));

        if ($transfer->isEmpty() || $transfer->getAttribute('resourceType') !== 'projects') {
            throw new Exception(Exception::PROJECT_TRANSFER_NOT_FOUND);
        }

        if (!$proofForToken->verify($secret, $transfer->getAttribute('secret'))) {
            throw new Exception(Exception::PROJECT_TRANSFER_INVALID_SECRET);
        }

        if (DateTime::formatTz($transfer->getAttribute('expire')) < DateTime::formatTz(DateTime::now())) {
            throw new Exception(Exception::PROJECT_TRANSFER_EXPIRED);
        }

        $targetTeamInternalId = $transfer->getAttribute('targetTeamInternalId', '');
        if ($targetTeamInternalId !== '' && $targetTeamInternalId !== $team->getSequence()) {
            throw new Exception(Exception::PROJECT_TRANSFER_TEAM_MISMATCH);
        }

        $project = $authorization->skip(fn () => $dbForPlatform->getDocument('projects', $transfer->getAttribute('resourceId')));

        // The project was deleted or moved some other way after the transfer was created
        if ($project->isEmpty() || $project->getAttribute('teamInternalId') !== $transfer->getAttribute('teamInternalId')) {
            throw new Exception(Exception::PROJECT_TRANSFER_NOT_FOUND);
        }

        if ($project->getAttribute('teamInternalId') === $team->getSequence()) {
            throw new Exception(Exception::PROJECT_TRANSFER_SAME_TEAM);
        }

        // Consume the transfer before moving the project, so only one of two concurrent claims can win
        if (!$authorization->skip(fn () => $dbForPlatform->deleteDocument('transfers', $transfer->getId()))) {
            throw new Exception(Exception::PROJECT_TRANSFER_NOT_FOUND);
        }

        $auditContext->project = $project;

        $permissions = $this->getPermissions($team->getId(), $project->getId());

        $project = $authorization->skip(fn () => $dbForPlatform->updateDocument('projects', $project->getId(), new Document([
            'teamId' => $team->getId(),
            'teamInternalId' => $team->getSequence(),
            '$permissions' => $permissions,
        ])));
        $authorization->skip(fn () => $dbForPlatform->purgeCachedDocument('projects', $project->getId()));

        foreach (['installations', 'repositories', 'vcsComments'] as $collection) {
            $this->updatePermissions($collection, $project, $permissions, $dbForPlatform, $authorization);
        }

        // Other links for this project were issued by the previous owner, so they stop working
        $authorization->skip(fn () => $dbForPlatform->deleteDocuments('transfers', [
            Query::equal('resourceType', ['projects']),
            Query::equal('resourceInternalId', [$project->getSequence()]),
        ]));

        $response->dynamic($project, Response::MODEL_PROJECT);
    }

    /**
     * Give every document of the project in the collection the new organization's permissions.
     *
     * Pages through all of them, since a project can have more than one page of VCS comments.
     *
     * @param array<string> $permissions
     */
    private function updatePermissions(string $collection, Document $project, array $permissions, Database $dbForPlatform, Authorization $authorization): void
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

            $documents = $authorization->skip(fn () => $dbForPlatform->find($collection, $queries));

            foreach ($documents as $document) {
                $authorization->skip(fn () => $dbForPlatform->updateDocument($collection, $document->getId(), new Document(['$permissions' => $permissions])));
            }

            $cursor = \end($documents) ?: null;
        } while (\count($documents) === $limit);
    }
}

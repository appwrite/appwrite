<?php

namespace Appwrite\Platform\Modules\Organization\Http\Transfers;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action as AppwriteAction;
use Appwrite\Platform\Permission as AppwritePermission;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;

class Action extends AppwriteAction
{
    use AppwritePermission;

    /**
     * Find a project transfer created by the organization.
     *
     * Transfers of other organizations look missing, so their IDs can't be probed.
     */
    protected function getOwnTransfer(string $transferId, Document $team, Database $dbForPlatform, Authorization $authorization): Document
    {
        $transfer = $authorization->skip(fn () => $dbForPlatform->getDocument('transfers', $transferId));

        if (
            $transfer->isEmpty()
            || $transfer->getAttribute('resourceType') !== 'projects'
            || $transfer->getAttribute('senderType') !== 'teams'
            || $transfer->getAttribute('senderInternalId') !== $team->getSequence()
        ) {
            throw new Exception(Exception::TRANSFER_NOT_FOUND);
        }

        return $transfer;
    }

    /**
     * Load the sender and recipient organizations of the transfers in one query.
     *
     * @param array<Document> $transfers
     * @return array<string, Document> organizations by internal ID
     */
    protected function getOrganizations(array $transfers, Database $dbForPlatform, Authorization $authorization): array
    {
        $sequences = [];
        foreach ($transfers as $transfer) {
            $sequences[] = $transfer->getAttribute('senderInternalId');
            $sequences[] = $transfer->getAttribute('recipientInternalId', '');
        }
        $sequences = \array_values(\array_unique(\array_filter($sequences, fn ($sequence) => $sequence !== '')));

        if ($sequences === []) {
            return [];
        }

        $teams = $authorization->skip(fn () => $dbForPlatform->find('teams', [
            Query::equal('$sequence', $sequences),
            Query::limit(\count($sequences)),
        ]));

        $organizations = [];
        foreach ($teams as $team) {
            $organizations[$team->getSequence()] = $team;
        }

        return $organizations;
    }

    /**
     * Add the resource and organization names, so the response describes what is being transferred and between whom.
     *
     * @param array<string, Document> $organizations by internal ID
     */
    protected function present(Document $transfer, Document $project, array $organizations): Document
    {
        $sender = $organizations[$transfer->getAttribute('senderInternalId')] ?? new Document();
        $recipient = $organizations[$transfer->getAttribute('recipientInternalId', '')] ?? new Document();

        return $transfer
            ->setAttribute('resourceName', $project->getAttribute('name', ''))
            ->setAttribute('senderName', $sender->getAttribute('name', ''))
            ->setAttribute('recipientName', $recipient->getAttribute('name', ''));
    }
}

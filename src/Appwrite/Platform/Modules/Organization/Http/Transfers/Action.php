<?php

namespace Appwrite\Platform\Modules\Organization\Http\Transfers;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action as AppwriteAction;
use Appwrite\Platform\Permission as AppwritePermission;
use Utopia\Database\Database;
use Utopia\Database\Document;
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
     * Add the resource name and hide the hashed secret, so the response describes what is being transferred.
     */
    protected function present(Document $transfer, Document $project): Document
    {
        return $transfer
            ->setAttribute('resourceName', $project->getAttribute('name', ''))
            ->setAttribute('secret', '');
    }
}

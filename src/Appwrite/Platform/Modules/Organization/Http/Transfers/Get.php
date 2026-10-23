<?php

namespace Appwrite\Platform\Modules\Organization\Http\Transfers;

use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Text;

class Get extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'getProjectTransfer';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/organization/transfers/:transferId')
            ->desc('Get project transfer')
            ->groups(['api', 'organization'])
            ->label('scope', 'projects.read')
            ->label('abuse-limit', 10)
            ->label('abuse-key', 'ip:{ip},url:{url}')
            ->label('sdk', new Method(
                namespace: 'organization',
                group: 'transfers',
                name: 'getProjectTransfer',
                description: <<<EOT
                Get a project transfer by its unique ID. The organization that owns the project can get it without a secret. Any other organization needs the transfer secret, so it can see which project it is about to claim and which organizations are involved.
                EOT,
                auth: [AuthType::ADMIN, AuthType::ORGANIZATION],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_TRANSFER,
                    )
                ]
            ))
            ->param('transferId', '', new UID(), 'Transfer ID.')
            ->param('secret', '', new Text(256), 'Transfer secret. Required unless the project belongs to your organization.', true)
            ->inject('response')
            ->inject('dbForPlatform')
            ->inject('team')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(
        string $transferId,
        string $secret,
        Response $response,
        Database $dbForPlatform,
        Document $team,
        Authorization $authorization,
    ) {
        $transfer = $authorization->skip(fn () => $dbForPlatform->getDocument('transfers', $transferId));

        if ($transfer->isEmpty() || $transfer->getAttribute('resourceType') !== 'projects') {
            throw new Exception(Exception::TRANSFER_NOT_FOUND);
        }

        if ($transfer->getAttribute('senderInternalId') !== $team->getSequence()) {
            if ($secret === '') {
                throw new Exception(Exception::TRANSFER_NOT_FOUND);
            }

            if (!\hash_equals($transfer->getAttribute('secret'), $secret)) {
                throw new Exception(Exception::TRANSFER_INVALID_SECRET);
            }

            if (DateTime::formatTz($transfer->getAttribute('expire')) < DateTime::formatTz(DateTime::now())) {
                throw new Exception(Exception::TRANSFER_EXPIRED);
            }

            // Any organization with the secret may see who can claim it; only claiming checks the recipient.
            // Only the sender may read the secret back
            $transfer->setAttribute('secret', '');
        }

        $project = $authorization->skip(fn () => $dbForPlatform->getDocument('projects', $transfer->getAttribute('resourceId')));

        // The project was deleted or moved some other way after the transfer was created
        if ($project->isEmpty() || $project->getAttribute('teamInternalId') !== $transfer->getAttribute('senderInternalId')) {
            throw new Exception(Exception::TRANSFER_NOT_FOUND);
        }

        $organizations = $this->getOrganizations([$transfer], $dbForPlatform, $authorization);

        $response->dynamic($this->present($transfer, $project, $organizations), Response::MODEL_TRANSFER);
    }
}

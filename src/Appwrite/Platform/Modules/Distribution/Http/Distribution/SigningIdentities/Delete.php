<?php

namespace Appwrite\Platform\Modules\Distribution\Http\Distribution\SigningIdentities;

use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Query;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;

class Delete extends Action
{
    use HTTP;

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
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    public function action(
        string $signingIdentityId,
        Response $response,
        Database $dbForProject,
        Event $queueForEvents,
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

        if (!$dbForProject->deleteDocument('distributionSigningIdentities', $signingIdentity->getId())) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to remove signing identity from DB');
        }

        $queueForEvents
            ->setParam('signingIdentityId', $signingIdentity->getId())
            ->setPayload($response->output($signingIdentity, Response::MODEL_DISTRIBUTION_SIGNING_IDENTITY));

        $response->noContent();
    }
}

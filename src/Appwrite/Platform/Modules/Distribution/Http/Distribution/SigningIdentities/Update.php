<?php

namespace Appwrite\Platform\Modules\Distribution\Http\Distribution\SigningIdentities;

use Appwrite\Distribution\Signing\Mode;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;
use Utopia\Validator\Boolean;
use Utopia\Validator\Nullable;
use Utopia\Validator\Text;

class Update extends Action
{
    use HTTP;

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
                Update a signing identity's name. Set rotate to true to replace the certificate of a managed identity; imported identities cannot be rotated.
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
            ->param('rotate', false, new Boolean(), 'Replace the certificate of a managed identity.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->callback($this->action(...));
    }

    public function action(
        string $signingIdentityId,
        ?string $name,
        bool $rotate,
        Response $response,
        Database $dbForProject,
        Event $queueForEvents,
    ): void {
        $signingIdentity = $dbForProject->getDocument('distributionSigningIdentities', $signingIdentityId);

        if ($signingIdentity->isEmpty()) {
            throw new Exception(Exception::DISTRIBUTION_SIGNING_IDENTITY_NOT_FOUND);
        }

        if ($rotate && $signingIdentity->getAttribute('mode') !== Mode::Managed->value) {
            throw new Exception(Exception::DISTRIBUTION_SIGNING_IDENTITY_INVALID, 'Only managed signing identities can be rotated.');
        }

        if ($name === null) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'No attributes to update.');
        }

        $signingIdentity = $dbForProject->updateDocument('distributionSigningIdentities', $signingIdentity->getId(), new Document([
            'name' => $name,
        ]));

        $queueForEvents->setParam('signingIdentityId', $signingIdentity->getId());

        $response->dynamic($signingIdentity, Response::MODEL_DISTRIBUTION_SIGNING_IDENTITY);
    }
}

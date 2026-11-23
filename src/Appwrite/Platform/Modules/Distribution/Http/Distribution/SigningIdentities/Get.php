<?php

namespace Appwrite\Platform\Modules\Distribution\Http\Distribution\SigningIdentities;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;

class Get extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'getSigningIdentity';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/distribution/signing-identities/:signingIdentityId')
            ->desc('Get signing identity')
            ->groups(['api', 'distribution'])
            ->label('scope', 'distribution.credentials.read')
            ->label('sdk', new Method(
                namespace: 'distribution',
                group: 'signingIdentities',
                name: 'getSigningIdentity',
                description: <<<EOT
                Get a signing identity by its unique ID. Secrets are never included.
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
            ->inject('response')
            ->inject('dbForProject')
            ->callback($this->action(...));
    }

    public function action(
        string $signingIdentityId,
        Response $response,
        Database $dbForProject,
    ): void {
        $signingIdentity = $dbForProject->getDocument('distributionSigningIdentities', $signingIdentityId);

        if ($signingIdentity->isEmpty()) {
            throw new Exception(Exception::DISTRIBUTION_SIGNING_IDENTITY_NOT_FOUND);
        }

        $response->dynamic($signingIdentity, Response::MODEL_DISTRIBUTION_SIGNING_IDENTITY);
    }
}

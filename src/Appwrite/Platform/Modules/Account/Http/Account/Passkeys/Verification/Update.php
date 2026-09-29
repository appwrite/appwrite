<?php

namespace Appwrite\Platform\Modules\Account\Http\Account\Passkeys\Verification;

use Appwrite\Auth\Passkey\Ceremony;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Assoc;
use Webauthn\Exception\WebauthnException;

class Update extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'updatePasskeyVerification';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PUT)
            ->setHttpPath('/v1/account/passkeys/:passkeyId/verification')
            ->desc('Update passkey verification')
            ->groups(['api', 'account'])
            ->label('scope', 'account')
            ->label('audits.event', 'passkey.create')
            ->label('audits.resource', 'user/{user.$id}')
            ->label('sdk', new Method(
                namespace: 'account',
                group: 'passkeys',
                name: 'updatePasskeyVerification',
                description: <<<EOT
                Complete a passkey registration started with [Create passkey](/docs/references/cloud/client-web/account#createPasskey). Pass the challenge ID and the JSON form of the credential returned by `navigator.credentials.create()`, for example `credential.toJSON()`. Each challenge can only be used once.
                EOT,
                auth: [AuthType::SESSION, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_PASSKEY,
                    )
                ],
                contentType: ContentType::JSON
            ))
            ->label('abuse-limit', 10)
            ->label('abuse-key', 'url:{url},userId:{userId}')
            ->param('passkeyId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Passkey ID.', false, ['dbForProject'])
            ->param('challengeId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Challenge ID returned when the passkey was created.', false, ['dbForProject'])
            ->param('credential', [], new Assoc(), 'Registration credential returned by the authenticator, in the JSON form produced by `PublicKeyCredential.toJSON()`.')
            ->inject('response')
            ->inject('user')
            ->inject('session')
            ->inject('project')
            ->inject('dbForProject')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    /**
     * @param array<string, mixed> $credential
     */
    public function action(
        string $passkeyId,
        string $challengeId,
        array $credential,
        Response $response,
        Document $user,
        Document $session,
        Document $project,
        Database $dbForProject,
        Authorization $authorization,
    ): void {
        $challenge = $authorization->skip(fn () => $dbForProject->getDocument('challenges', $challengeId));
        $data = $challenge->getAttribute('data', []);

        if (
            $challenge->isEmpty()
            || $challenge->getAttribute('type') !== Ceremony::TYPE_REGISTRATION
            || $challenge->getAttribute('userInternalId') !== $user->getSequence()
            || $challenge->getAttribute('expire') < DateTime::formatTz(DateTime::now())
            || ($data['passkeyId'] ?? '') !== $passkeyId
            || ($data['sessionId'] ?? '') !== $session->getId()
        ) {
            throw new Exception(Exception::USER_INVALID_TOKEN);
        }

        // Configuration changes invalidate outstanding challenges
        $ceremony = Ceremony::fromProject($project);
        if ($ceremony === null || $ceremony->relyingParty->getFingerprint() !== ($data['relyingParty'] ?? '')) {
            throw new Exception(Exception::USER_INVALID_TOKEN);
        }

        // Consume first: of two concurrent submissions only one wins the delete
        if (!$authorization->skip(fn () => $dbForProject->deleteDocument('challenges', $challengeId))) {
            throw new Exception(Exception::USER_INVALID_TOKEN);
        }

        $passkey = $dbForProject->getDocument('authenticators', $passkeyId);
        if (
            $passkey->isEmpty()
            || $passkey->getAttribute('type') !== Ceremony::TYPE
            || $passkey->getAttribute('userInternalId') !== $user->getSequence()
            || $passkey->getAttribute('verified')
        ) {
            throw new Exception(Exception::USER_PASSKEY_NOT_FOUND);
        }


        try {
            $record = $ceremony->verifyRegistration(
                $ceremony->decodeCredential($credential),
                $ceremony->decodeRegistration($data['options']),
            );
        } catch (WebauthnException $th) {
            throw new Exception(Exception::USER_PASSKEY_INVALID, previous: $th);
        }

        try {
            $passkey = $dbForProject->updateDocument('authenticators', $passkeyId, new Document([
                'verified' => true,
                'identifier' => Ceremony::getIdentifier($record->publicKeyCredentialId),
                'data' => \array_merge($passkey->getAttribute('data', []), [
                    'record' => $ceremony->encodeRecord($record),
                ]),
            ]));
        } catch (Duplicate) {
            throw new Exception(Exception::USER_PASSKEY_ALREADY_EXISTS);
        }

        $dbForProject->purgeCachedDocument('users', $user->getId());

        $response->dynamic($passkey, Response::MODEL_PASSKEY);
    }
}

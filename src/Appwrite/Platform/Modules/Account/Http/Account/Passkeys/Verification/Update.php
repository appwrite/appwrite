<?php

namespace Appwrite\Platform\Modules\Account\Http\Account\Passkeys\Verification;

use Appwrite\Auth\Passkey\Ceremony;
use Appwrite\Auth\Passkey\Challenges;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Auth\Passkeys\Exception as PasskeyException;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Assoc;

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
            ->groups(['api', 'account', 'recentSession', 'passkeys'])
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
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::JWT],
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
        // Configuration changes invalidate outstanding challenges
        $ceremony = Ceremony::fromProject($project) ?? throw new Exception(Exception::USER_INVALID_TOKEN);

        // The challenge is checked first, so a mismatched one is rejected the same way whether or not the passkey exists
        $passkey = $dbForProject->getDocument('authenticators', $passkeyId);
        $registration = $this->isPending($passkey, $user) ? ($passkey->getAttribute('data', [])['registration'] ?? '') : '';

        $state = (new Challenges($dbForProject, $authorization))->consume($challengeId, Ceremony::TYPE_REGISTRATION, $ceremony, $user, [
            'passkeyId' => $passkeyId,
            'sessionId' => $session->getId(),
            'registration' => $registration,
        ]);

        if (!$this->isPending($passkey, $user)) {
            throw new Exception(Exception::USER_PASSKEY_NOT_FOUND);
        }

        try {
            $verified = $ceremony->verifyRegistration($state, $credential);
        } catch (PasskeyException $th) {
            throw new Exception(Exception::USER_PASSKEY_INVALID, previous: $th);
        }

        try {
            // Locked so a restarted registration cannot replace the passkey while it is being verified
            $passkey = $dbForProject->withTransaction(function () use ($dbForProject, $passkeyId, $user, $registration, $verified) {
                $current = $dbForProject->getDocument('authenticators', $passkeyId, forUpdate: true);
                $data = $current->getAttribute('data', []);
                if (!$this->isPending($current, $user) || ($data['registration'] ?? '') !== $registration) {
                    throw new Exception(Exception::USER_PASSKEY_NOT_FOUND);
                }

                return $dbForProject->updateDocument('authenticators', $passkeyId, new Document([
                    'verified' => true,
                    'identifier' => $verified->identifier,
                    'data' => \array_merge($data, [
                        'record' => $verified->record,
                    ]),
                ]));
            });
        } catch (Duplicate) {
            throw new Exception(Exception::USER_PASSKEY_ALREADY_EXISTS);
        }

        $dbForProject->purgeCachedDocument('users', $user->getId());

        $response->dynamic($passkey, Response::MODEL_PASSKEY);
    }

    private function isPending(Document $passkey, Document $user): bool
    {
        return !$passkey->isEmpty()
            && $passkey->getAttribute('type') === Ceremony::TYPE
            && $passkey->getAttribute('userInternalId') === $user->getSequence()
            && !$passkey->getAttribute('verified');
    }
}

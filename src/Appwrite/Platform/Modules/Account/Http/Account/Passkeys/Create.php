<?php

namespace Appwrite\Platform\Modules\Account\Http\Account\Passkeys;

use Appwrite\Auth\Passkey\Ceremony;
use Appwrite\Auth\Passkey\RelyingParty;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Text;

class Create extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'createPasskey';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/account/passkeys')
            ->desc('Create passkey')
            ->groups(['api', 'account', 'auth', 'recentSession'])
            ->label('auth.type', 'passkey')
            ->label('scope', 'account')
            ->label('sdk', new Method(
                namespace: 'account',
                group: 'passkeys',
                name: 'createPasskey',
                description: <<<EOT
                Start registering a passkey for the currently logged in user. The account needs a verified email or phone number, and the session must have signed in or completed an MFA challenge within the last 10 minutes. Pass the returned `publicKey` options to `navigator.credentials.create()`, then complete the registration with [Update passkey verification](/docs/references/cloud/client-web/account#updatePasskeyVerification). The passkey stays pending until verified, and the challenge expires after 5 minutes.
                EOT,
                auth: [AuthType::SESSION, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_CREATED,
                        model: Response::MODEL_PASSKEY_CHALLENGE,
                    )
                ],
                contentType: ContentType::JSON
            ))
            ->label('abuse-limit', 10)
            ->label('abuse-key', 'url:{url},userId:{userId}')
            ->param('name', '', new Text(128, 0), 'Passkey name, shown when listing passkeys. Max length: 128 chars.', true)
            ->inject('response')
            ->inject('user')
            ->inject('session')
            ->inject('project')
            ->inject('dbForProject')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(
        string $name,
        Response $response,
        Document $user,
        Document $session,
        Document $project,
        Database $dbForProject,
        Authorization $authorization,
    ): void {
        $relyingParty = RelyingParty::fromProject($project);
        if ($relyingParty === null) {
            throw new Exception(Exception::USER_AUTH_METHOD_UNSUPPORTED, 'Passkeys are not configured for this project. Set a relying party ID and origins in the passkey policy.');
        }

        // A passkey must never be the only way into an account
        $email = $user->getAttribute('emailVerification') ? $user->getAttribute('email', '') : '';
        $phone = $user->getAttribute('phoneVerification') ? $user->getAttribute('phone', '') : '';
        $userName = $email ?: $phone;
        if ($userName === '') {
            throw new Exception(Exception::USER_PASSKEY_UNAVAILABLE);
        }

        $passkeys = $this->getPasskeys($user, $dbForProject);
        if (\count($passkeys) >= APP_LIMIT_USER_PASSKEYS) {
            throw new Exception(Exception::USER_PASSKEY_LIMIT_EXCEEDED);
        }

        $ceremony = new Ceremony($relyingParty);

        $userHandle = null;
        $exclude = [];
        foreach ($passkeys as $passkey) {
            $data = $passkey->getAttribute('data', []);
            $userHandle ??= $data['userHandle'] ?? null;
            if ($passkey->getAttribute('verified')) {
                $exclude[] = $ceremony->decodeRecord($data['record'])->publicKeyCredentialId;
            }
        }
        // One handle per user, so authenticators replace rather than duplicate a user's passkey
        $userHandle ??= Base64UrlSafe::encodeUnpadded(\random_bytes(32));

        $options = $ceremony->createRegistration(
            Base64UrlSafe::decodeNoPadding($userHandle),
            $userName,
            $user->getAttribute('name') ?: $userName,
            $exclude,
        );

        $passkey = $dbForProject->createDocument('authenticators', new Document([
            '$id' => ID::unique(),
            '$permissions' => [
                Permission::read(Role::user($user->getId())),
                Permission::update(Role::user($user->getId())),
                Permission::delete(Role::user($user->getId())),
            ],
            'userId' => $user->getId(),
            'userInternalId' => $user->getSequence(),
            'type' => Ceremony::TYPE,
            'verified' => false,
            'data' => [
                'name' => $name,
                'userHandle' => $userHandle,
            ],
        ]));

        // Re-count after writing so concurrent registrations cannot overshoot the cap
        if (\count($this->getPasskeys($user, $dbForProject)) > APP_LIMIT_USER_PASSKEYS) {
            $dbForProject->deleteDocument('authenticators', $passkey->getId());
            throw new Exception(Exception::USER_PASSKEY_LIMIT_EXCEEDED);
        }

        $encoded = $ceremony->encode($options);

        $challenge = $authorization->skip(fn () => $dbForProject->createDocument('challenges', new Document([
            '$id' => ID::unique(),
            'userId' => $user->getId(),
            'userInternalId' => $user->getSequence(),
            'type' => Ceremony::TYPE_REGISTRATION,
            'expire' => DateTime::addSeconds(new \DateTime(), Ceremony::TIMEOUT),
            'data' => [
                'version' => 1,
                'passkeyId' => $passkey->getId(),
                'sessionId' => $session->getId(),
                'relyingParty' => $relyingParty->getFingerprint(),
                'options' => $encoded,
            ],
        ])));

        $dbForProject->purgeCachedDocument('users', $user->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic(new Document([
                '$id' => $challenge->getId(),
                '$createdAt' => $challenge->getCreatedAt(),
                'passkeyId' => $passkey->getId(),
                'expire' => $challenge->getAttribute('expire'),
                'publicKey' => \json_decode($encoded, true),
            ]), Response::MODEL_PASSKEY_CHALLENGE);
    }

    /**
     * Verified passkeys plus pending registrations still inside their ceremony window.
     * Abandoned registrations are removed so they stop counting towards the cap.
     *
     * @return array<Document>
     */
    private function getPasskeys(Document $user, Database $dbForProject): array
    {
        $passkeys = $dbForProject->find('authenticators', [
            Query::equal('userInternalId', [$user->getSequence()]),
            Query::equal('type', [Ceremony::TYPE]),
            Query::limit(APP_LIMIT_SUBQUERY),
        ]);

        $abandoned = DateTime::formatTz(DateTime::addSeconds(new \DateTime(), -Ceremony::TIMEOUT));

        return \array_values(\array_filter($passkeys, function (Document $passkey) use ($abandoned, $dbForProject) {
            if ($passkey->getAttribute('verified') || $passkey->getCreatedAt() >= $abandoned) {
                return true;
            }
            $dbForProject->deleteDocument('authenticators', $passkey->getId());
            return false;
        }));
    }
}

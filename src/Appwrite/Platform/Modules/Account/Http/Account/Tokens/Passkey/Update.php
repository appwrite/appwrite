<?php

namespace Appwrite\Platform\Modules\Account\Http\Account\Tokens\Passkey;

use Appwrite\Auth\Passkey\Ceremony;
use Appwrite\Auth\Passkey\Challenges;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Auth\Passkeys\Exception as PasskeyException;
use Utopia\Auth\Proofs\Token as ProofsToken;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Assoc;

class Update extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'updatePasskeyToken';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PUT)
            ->setHttpPath('/v1/account/tokens/passkey')
            ->desc('Update passkey token')
            ->groups(['api', 'account'])
            ->label('scope', 'sessions.write')
            ->label('audits.event', 'passkey.token.create')
            ->label('audits.resource', 'user/{response.userId}')
            ->label('audits.userId', '{response.userId}')
            ->label('sdk', new Method(
                namespace: 'account',
                group: 'tokens',
                name: 'updatePasskeyToken',
                description: <<<EOT
                Complete a passkey sign-in started with [Create passkey token](/docs/references/cloud/client-web/account#createPasskeyToken). Pass the challenge ID and the JSON form of the credential returned by `navigator.credentials.get()`, for example `credential.toJSON()`. The returned token includes its secret and expires after 1 minute: exchange it for a session with [Create session](/docs/references/cloud/client-web/account#createSession). Passkeys require user verification, so the session also satisfies MFA. Each challenge can only be used once.
                EOT,
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_CREATED,
                        model: Response::MODEL_TOKEN,
                    )
                ],
                contentType: ContentType::JSON
            ))
            ->label('abuse-limit', 10)
            ->label('abuse-key', 'url:{url},ip:{ip}')
            ->param('challengeId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Challenge ID returned by createPasskeyToken.', false, ['dbForProject'])
            ->param('credential', [], new Assoc(), 'Authentication credential returned by the authenticator, in the JSON form produced by `PublicKeyCredential.toJSON()`.')
            ->inject('request')
            ->inject('response')
            ->inject('project')
            ->inject('dbForProject')
            ->inject('proofForToken')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    /**
     * @param array<string, mixed> $credential
     */
    public function action(
        string $challengeId,
        array $credential,
        Request $request,
        Response $response,
        Document $project,
        Database $dbForProject,
        ProofsToken $proofForToken,
        Authorization $authorization,
    ): void {
        // Configuration changes invalidate outstanding challenges
        $ceremony = Ceremony::fromProject($project) ?? throw new Exception(Exception::USER_INVALID_TOKEN);

        $state = (new Challenges($dbForProject, $authorization))->consume($challengeId, Ceremony::TYPE_AUTHENTICATION, $ceremony);

        try {
            $identifier = $ceremony->identify($credential);
        } catch (PasskeyException $th) {
            throw new Exception(Exception::USER_PASSKEY_INVALID, previous: $th);
        }

        $passkey = $authorization->skip(fn () => $dbForProject->findOne('authenticators', [
            Query::equal('identifier', [$identifier]),
            Query::equal('type', [Ceremony::TYPE]),
            Query::equal('verified', [true]),
        ]));
        if ($passkey->isEmpty()) {
            throw new Exception(Exception::USER_PASSKEY_INVALID);
        }

        // The internal ID guards against a deleted user whose public ID was reused
        $user = $authorization->skip(fn () => $dbForProject->getDocument('users', $passkey->getAttribute('userId')));
        if ($user->isEmpty() || $user->getSequence() !== $passkey->getAttribute('userInternalId')) {
            throw new Exception(Exception::USER_PASSKEY_INVALID);
        }

        $passkeyData = $passkey->getAttribute('data', []);

        try {
            $verified = $ceremony->verifyAuthentication($state, $credential, $passkeyData['record']);
        } catch (PasskeyException $th) {
            throw new Exception(Exception::USER_PASSKEY_INVALID, previous: $th);
        }

        // Only after verification, so the response never reveals who owns an unproven credential
        if ($user->getAttribute('status') === false) {
            throw new Exception(Exception::USER_BLOCKED);
        }

        $authorization->skip(fn () => $dbForProject->updateDocument('authenticators', $passkey->getId(), new Document([
            'data' => \array_merge($passkeyData, [
                'record' => $verified->record,
                'accessedAt' => DateTime::formatTz(DateTime::now()),
            ]),
        ])));

        $secret = $proofForToken->generate();
        $token = $authorization->skip(fn () => $dbForProject->createDocument('tokens', new Document([
            '$id' => ID::unique(),
            '$permissions' => [
                Permission::read(Role::user($user->getId())),
                Permission::update(Role::user($user->getId())),
                Permission::delete(Role::user($user->getId())),
            ],
            'userId' => $user->getId(),
            'userInternalId' => $user->getSequence(),
            'type' => TOKEN_TYPE_PASSKEY,
            'secret' => $proofForToken->hash($secret),
            'expire' => DateTime::addSeconds(new \DateTime(), PASSKEY_TOKEN_DURATION),
            'userAgent' => $request->getUserAgent('UNKNOWN'),
            'ip' => $request->getIP(),
        ])));

        $dbForProject->purgeCachedDocument('users', $user->getId());

        // Possession of the passkey is proven, so the caller gets the secret to exchange for a session
        $token->setAttribute('secret', $secret);
        $response->setStatusCode(Response::STATUS_CODE_CREATED);
        $response->showSensitive(function () use ($response, $token) {
            $response->dynamic($token, Response::MODEL_TOKEN);
            return [];
        });
    }
}

<?php

namespace Appwrite\Platform\Modules\Account\Http\Account\Tokens\Passkey;

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
use Utopia\Database\Helpers\ID;
use Utopia\Database\Validator\Authorization;
use Utopia\Platform\Scope\HTTP;

class Create extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'createPasskeyToken';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/account/tokens/passkey')
            ->desc('Create passkey token')
            ->groups(['api', 'account', 'auth'])
            ->label('auth.type', 'passkey')
            ->label('scope', 'sessions.write')
            ->label('sdk', new Method(
                namespace: 'account',
                group: 'tokens',
                name: 'createPasskeyToken',
                description: <<<EOT
                Start signing in with a passkey. No email or user ID is needed: pass the returned `publicKey` options to `navigator.credentials.get()` and the user picks one of their passkeys. Then call [Update passkey token](/docs/references/cloud/client-web/account#updatePasskeyToken) with the credential to receive a token, and exchange it with [Create session](/docs/references/cloud/client-web/account#createSession). The challenge expires after 5 minutes.
                EOT,
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_CREATED,
                        model: Response::MODEL_PASSKEY_CHALLENGE,
                    )
                ],
                contentType: ContentType::JSON
            ))
            ->label('abuse-limit', 10)
            ->label('abuse-key', 'url:{url},ip:{ip}')
            ->inject('response')
            ->inject('project')
            ->inject('dbForProject')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(
        Response $response,
        Document $project,
        Database $dbForProject,
        Authorization $authorization,
    ): void {
        $ceremony = Ceremony::fromProject($project);
        if ($ceremony === null) {
            throw new Exception(Exception::USER_AUTH_METHOD_UNSUPPORTED, 'Passkeys are not configured for this project. Set a relying party ID and origins in the passkey policy.');
        }

        $encoded = $ceremony->encode($ceremony->createAuthentication());

        // Sign-in challenges belong to no user until the assertion names one
        $challenge = $authorization->skip(fn () => $dbForProject->createDocument('challenges', new Document([
            '$id' => ID::unique(),
            'type' => Ceremony::TYPE_AUTHENTICATION,
            'expire' => DateTime::addSeconds(new \DateTime(), Ceremony::TIMEOUT),
            'data' => [
                'version' => 1,
                'relyingParty' => $ceremony->relyingParty->getFingerprint(),
                'options' => $encoded,
            ],
        ])));

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic(new Document([
                '$id' => $challenge->getId(),
                '$createdAt' => $challenge->getCreatedAt(),
                'expire' => $challenge->getAttribute('expire'),
                'publicKey' => \json_decode($encoded, true),
            ]), Response::MODEL_PASSKEY_CHALLENGE);
    }
}

<?php

namespace Appwrite\Platform\Modules\Account\Http\Account\Tokens\Phone;

use Appwrite\Auth\Key;
use Appwrite\Auth\Validator\Phone;
use Appwrite\Event\Event;
use Appwrite\Event\Message\Messaging as MessagingMessage;
use Appwrite\Event\Publisher\Messaging as MessagingPublisher;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Template\Template;
use Appwrite\Usage\Context;
use Appwrite\Utopia\Database\Validator\CustomId;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Auth\Proofs\Code as ProofsCode;
use Utopia\Auth\Store;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Id;
use Utopia\Database\Permission;
use Utopia\Database\Query;
use Utopia\Database\Role;
use Utopia\Database\Validator\Authorization;
use Utopia\Locale\Locale;
use Utopia\Messaging\Adapter\SMS\GEOSMS\CallingCode;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;

class Create extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'createPhoneToken';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/account/tokens/phone')
            ->httpAlias('/v1/account/sessions/phone')
            ->desc('Create phone token')
            ->groups(['api', 'account', 'auth'])
            ->label('scope', 'sessions.write')
            ->label('auth.type', 'phone')
            ->label('audits.event', 'session.create')
            ->label('audits.resource', 'user/{response.userId}')
            ->label('audits.userId', '{response.userId}')
            ->label('sdk', new Method(
                namespace: 'account',
                group: 'tokens',
                name: 'createPhoneToken',
                description: '/docs/references/account/create-token-phone.md',
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_CREATED,
                        model: Response::MODEL_TOKEN,
                    )
                ],
                contentType: ContentType::JSON,
            ))
            ->label('abuse-limit', 10)
            ->label('abuse-key', ['url:{url},phone:{param-phone}', 'url:{url},ip:{ip}'])
            ->param('userId', '', fn (Database $dbForProject) => new CustomId(false, $dbForProject->getMaxUidLength()), 'Unique Id. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars. If the phone number has never been used, a new account is created using the provided userId. Otherwise, if the phone number is already attached to an account, the user ID is ignored.', false, ['dbForProject'])
            ->param('phone', '', new Phone(), 'Phone number. Format this number with a leading \'+\' and a country code, e.g., +16175551212.')
            ->inject('request')
            ->inject('response')
            ->inject('project')
            ->inject('platform')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->inject('publisherForMessaging')
            ->inject('locale')
            ->inject('usage')
            ->inject('plan')
            ->inject('store')
            ->inject('proofForCode')
            ->inject('authorization')
            ->inject('apiKey')
            ->callback($this->action(...));
    }

    public function action(
        string $userId,
        string $phone,
        Request $request,
        Response $response,
        Document $project,
        array $platform,
        Database $dbForProject,
        Event $queueForEvents,
        MessagingPublisher $publisherForMessaging,
        Locale $locale,
        Context $usage,
        array $plan,
        Store $store,
        ProofsCode $proofForCode,
        Authorization $authorization,
        ?Key $apiKey,
    ): void {
        if (empty(System::getEnv('_APP_SMS_PROVIDER'))) {
            throw new Exception(Exception::GENERAL_PHONE_DISABLED, 'Phone provider not configured');
        }

        $result = $dbForProject->findOne('users', [Query::equal('phone', [$phone])]);
        if (!$result->isEmpty()) {
            $recipient = $result;
        } else {
            $limit = $project->getAttribute('auths', [])['limit'] ?? 0;

            if ($limit !== 0) {
                $total = $dbForProject->count('users', max: APP_LIMIT_USERS);

                if ($total >= $limit) {
                    throw new Exception(Exception::USER_COUNT_EXCEEDED);
                }
            }

            $userId = $userId == 'unique()' ? Id::unique() : $userId;
            $recipient = new Document([
                '$id' => $userId,
                '$permissions' => [
                    Permission::read(Role::any()),
                    Permission::update(Role::user($userId)),
                    Permission::delete(Role::user($userId)),
                ],
                'email' => null,
                'phone' => $phone,
                'emailVerification' => false,
                'phoneVerification' => false,
                'status' => true,
                'password' => null,
                'passwordUpdate' => null,
                'registration' => DateTime::now(),
                'reset' => false,
                'prefs' => new \stdClass(),
                'sessions' => null,
                'tokens' => null,
                'memberships' => null,
                'search' => implode(' ', [$userId, $phone]),
                'accessedAt' => DateTime::now(),
                'emailCanonical' => null,
                'emailIsCanonical' => null,
                'emailIsCorporate' => null,
                'emailIsDisposable' => null,
                'emailIsFree' => null,
            ]);

            try {
                $recipient = $authorization->skip(fn () => $dbForProject->createDocument('users', $recipient));
            } catch (Duplicate) {
                throw new Exception(Exception::USER_ALREADY_EXISTS);
            }
            try {
                $target = $authorization->skip(fn () => $dbForProject->createDocument('targets', new Document([
                    '$permissions' => [
                        Permission::read(Role::user($recipient->getId())),
                        Permission::update(Role::user($recipient->getId())),
                        Permission::delete(Role::user($recipient->getId())),
                    ],
                    'userId' => $recipient->getId(),
                    'userInternalId' => $recipient->getSequence(),
                    'providerType' => MESSAGE_TYPE_SMS,
                    'identifier' => $phone,
                ])));
                $recipient->setAttribute('targets', [...$recipient->getAttribute('targets', []), $target]);
            } catch (Duplicate) {
                $existingTarget = $dbForProject->findOne('targets', [
                    Query::equal('identifier', [$phone]),
                ]);
                $recipient->setAttribute('targets', [...$recipient->getAttribute('targets', []), $existingTarget->isEmpty() ? false : $existingTarget]);
            }
            $dbForProject->purgeCachedDocument('users', $recipient->getId());
        }

        $secret = null;
        $sendSMS = true;
        $mockNumbers = $project->getAttribute('auths', [])['mockNumbers'] ?? [];
        foreach ($mockNumbers as $mockNumber) {
            if ($mockNumber['phone'] === $phone) {
                $secret = $mockNumber['otp'];
                $sendSMS = false;
                break;
            }
        }

        $secret ??= $proofForCode->generate();
        $expire = DateTime::addSeconds(new \DateTime(), TOKEN_EXPIRATION_OTP);

        $token = new Document([
            '$id' => Id::unique(),
            'userId' => $recipient->getId(),
            'userInternalId' => $recipient->getSequence(),
            'type' => TOKEN_TYPE_PHONE,
            'secret' => $proofForCode->hash($secret),
            'expire' => $expire,
            'userAgent' => $request->getUserAgent('UNKNOWN'),
            'ip' => $request->getIP(),
        ]);

        $token->setAttribute('$permissions', [
            Permission::read(Role::user($recipient->getId())),
            Permission::update(Role::user($recipient->getId())),
            Permission::delete(Role::user($recipient->getId())),
        ]);

        $sms = null;
        if ($sendSMS) {
            $message = Template::fromFile(APP_CE_CONFIG_DIR . '/locale/templates/sms-base.tpl');

            $projectName = $project->getAttribute('name');
            if ($project->getId() === 'console') {
                $projectName = $platform['platformName'];
            }

            $messageContent = Template::fromString($locale->getText("sms.verification.body"));
            $messageContent
                ->setParam('{{project}}', $projectName)
                ->setParam('{{secret}}', $secret);
            $messageContent = \strip_tags($messageContent->render());
            $message = $message->setParam('{{token}}', $messageContent);

            $message = $message->render();

            $messageDoc = new Document([
                '$id' => $token->getId(),
                'data' => [
                    'content' => $message,
                ],
            ]);

            $sms = new MessagingMessage(
                type: MESSAGE_SEND_TYPE_INTERNAL,
                project: $project,
                message: $messageDoc,
                recipients: [$phone],
                providerType: MESSAGE_TYPE_SMS,
            );
        }

        // Store the token and queue its SMS under the user lock the phone update takes, so a code is never issued or sent for a number that just changed
        $token = $authorization->skip(fn () => $dbForProject->withTransaction(function () use ($dbForProject, $recipient, $phone, $token, $sms, $publisherForMessaging) {
            $current = $dbForProject->getDocument('users', $recipient->getId(), forUpdate: true);
            if ($current->getAttribute('phone') !== $phone) {
                throw new Exception(Exception::USER_PHONE_NOT_FOUND);
            }

            $token = $dbForProject->createDocument('tokens', $token);
            if ($sms !== null) {
                $publisherForMessaging->enqueue($sms);
            }

            return $token;
        }));

        $dbForProject->purgeCachedDocument('users', $recipient->getId());

        if ($sendSMS) {
            $countryCode = CallingCode::fromPhoneNumber($phone);
            if (!empty($countryCode)) {
                $usage->addMetric(str_replace('{countryCode}', $countryCode, METRIC_AUTH_METHOD_PHONE_COUNTRY_CODE), 1);
            }
            $usage->addMetric(METRIC_AUTH_METHOD_PHONE, 1);
        }

        $token->setAttribute('secret', $secret);

        $queueForEvents
            ->setPayload($response->output($token, Response::MODEL_TOKEN), sensitive: ['secret']);

        // Encode secret for clients
        $encoded = $store
            ->setProperty('id', $recipient->getId())
            ->setProperty('secret', $secret)
            ->encode();
        $token->setAttribute('secret', $encoded);

        // The secret proves control of the inbox or phone; only keys that can already mint tokens through users.write may read it back.
        if ($apiKey !== null && !\in_array('users.write', $apiKey->getScopes())) {
            $token->setAttribute('secret', '');
        }

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($token, Response::MODEL_TOKEN);
    }
}

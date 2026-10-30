<?php

namespace Appwrite\Platform\Modules\Account\Http\Account\Verifications\Phone;

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
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Auth\Proofs\Code as ProofsCode;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Id;
use Utopia\Database\Permission;
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
        return 'createPhoneVerification';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/account/verifications/phone')
            ->httpAlias('/v1/account/verification/phone')
            ->desc('Create phone verification')
            ->groups(['api', 'account', 'auth'])
            ->label('scope', 'account')
            ->label('auth.type', 'phone')
            ->label('event', 'users.[userId].verification.[tokenId].create')
            ->label('audits.event', 'verification.create')
            ->label('audits.resource', 'user/{response.userId}')
            ->label('sdk', new Method(
                namespace: 'account',
                group: 'verification',
                name: 'createPhoneVerification',
                description: '/docs/references/account/create-phone-verification.md',
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
            ->label('abuse-key', ['url:{url},userId:{userId}', 'url:{url},ip:{ip}'])
            ->inject('request')
            ->inject('response')
            ->inject('user')
            ->inject('dbForProject')
            ->inject('queueForEvents')
            ->inject('publisherForMessaging')
            ->inject('project')
            ->inject('locale')
            ->inject('usage')
            ->inject('plan')
            ->inject('proofForCode')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(
        Request $request,
        Response $response,
        User $user,
        Database $dbForProject,
        Event $queueForEvents,
        MessagingPublisher $publisherForMessaging,
        Document $project,
        Locale $locale,
        Context $usage,
        array $plan,
        ProofsCode $proofForCode,
        Authorization $authorization,
    ): void {
        if (empty(System::getEnv('_APP_SMS_PROVIDER'))) {
            throw new Exception(Exception::GENERAL_PHONE_DISABLED, 'Phone provider not configured');
        }

        $phone = $user->getAttribute('phone');
        if (empty($phone)) {
            throw new Exception(Exception::USER_PHONE_NOT_FOUND);
        }

        if ($user->getAttribute('phoneVerification')) {
            throw new Exception(Exception::USER_PHONE_ALREADY_VERIFIED);
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
        $expire = DateTime::addSeconds(new \DateTime(), TOKEN_EXPIRATION_CONFIRM);

        $verification = new Document([
            '$id' => Id::unique(),
            'userId' => $user->getId(),
            'userInternalId' => $user->getSequence(),
            'type' => TOKEN_TYPE_PHONE,
            'secret' => $proofForCode->hash($secret),
            'expire' => $expire,
            'userAgent' => $request->getUserAgent('UNKNOWN'),
            'ip' => $request->getIP(),
        ]);

        $authorization->addRole(Role::user($user->getId())->toString());

        $verification->setAttribute('$permissions', [
            Permission::read(Role::user($user->getId())),
            Permission::update(Role::user($user->getId())),
            Permission::delete(Role::user($user->getId())),
        ]);

        $sms = null;
        if ($sendSMS) {
            $message = Template::fromFile(APP_CE_CONFIG_DIR . '/locale/templates/sms-base.tpl');

            $messageContent = Template::fromString($locale->getText("sms.verification.body"));
            $messageContent
                ->setParam('{{project}}', $project->getAttribute('name'))
                ->setParam('{{secret}}', $secret);
            $messageContent = \strip_tags($messageContent->render());
            $message = $message->setParam('{{token}}', $messageContent);

            $message = $message->render();

            $messageDoc = new Document([
                '$id' => $verification->getId(),
                'data' => [
                    'content' => $message,
                ],
            ]);

            $sms = new MessagingMessage(
                type: MESSAGE_SEND_TYPE_INTERNAL,
                project: $project,
                message: $messageDoc,
                recipients: [$user->getAttribute('phone')],
                providerType: MESSAGE_TYPE_SMS,
            );
        }

        // Store the token and queue its SMS under the user lock the phone update takes, so a code is never issued or sent for a number that just changed
        $verification = $dbForProject->withTransaction(function () use ($dbForProject, $authorization, $user, $phone, $verification, $sms, $publisherForMessaging) {
            $current = $authorization->skip(fn () => $dbForProject->getDocument('users', $user->getId(), forUpdate: true));
            if ($current->getAttribute('phone') !== $phone) {
                throw new Exception(Exception::USER_PHONE_NOT_FOUND);
            }

            $verification = $dbForProject->createDocument('tokens', $verification);
            if ($sms !== null) {
                $publisherForMessaging->enqueue($sms);
            }

            return $verification;
        });

        $dbForProject->purgeCachedDocument('users', $user->getId());

        if ($sendSMS) {
            $countryCode = CallingCode::fromPhoneNumber($phone);
            if (!empty($countryCode)) {
                $usage->addMetric(str_replace('{countryCode}', $countryCode, METRIC_AUTH_METHOD_PHONE_COUNTRY_CODE), 1);
            }
            $usage->addMetric(METRIC_AUTH_METHOD_PHONE, 1);
        }

        $verification->setAttribute('secret', $secret);

        $queueForEvents
            ->setParam('userId', $user->getId())
            ->setParam('tokenId', $verification->getId())
            ->setPayload($response->output($verification, Response::MODEL_TOKEN), sensitive: ['secret']);

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($verification, Response::MODEL_TOKEN);
    }
}

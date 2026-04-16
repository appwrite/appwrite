<?php

namespace Appwrite\Platform\Modules\Account\Http\Account\MFA\Challenges;

use Appwrite\Auth\MFA\Type;
use Appwrite\Detector\Detector;
use Appwrite\Event\Event;
use Appwrite\Event\Mail;
use Appwrite\Event\Messaging;
use Appwrite\Event\StatsUsage;
use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Deprecated;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Template\Template;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use libphonenumber\PhoneNumberUtil;
use Utopia\Abuse\Abuse;
use Utopia\Auth\Proofs\Code as ProofsCode;
use Utopia\Auth\Proofs\Token as ProofsToken;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Locale\Locale;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Storage\Validator\FileName;
use Utopia\System\System;
use Utopia\Validator\WhiteList;

class Create extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'createMFAChallenge';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/account/mfa/challenges')
            ->httpAlias('/v1/account/mfa/challenge')
            ->desc('Create MFA challenge')
            ->groups(['api', 'account', 'mfa'])
            ->label('scope', 'account')
            ->label('event', 'users.[userId].challenges.[challengeId].create')
            ->label('audits.event', 'challenge.create')
            ->label('audits.resource', 'user/{response.userId}')
            ->label('audits.userId', '{response.userId}')
            ->label('sdk', [
                new Method(
                    namespace: 'account',
                    group: 'mfa',
                    name: 'createMfaChallenge',
                    description: '/docs/references/account/create-mfa-challenge.md',
                    auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::JWT],
                    responses: [
                        new SDKResponse(
                            code: Response::STATUS_CODE_CREATED,
                            model: Response::MODEL_MFA_CHALLENGE,
                        )
                    ],
                    contentType: ContentType::JSON,
                    deprecated: new Deprecated(
                        since: '1.8.0',
                        replaceWith: 'account.createMFAChallenge',
                    ),
                    public: false,
                ),
                new Method(
                    namespace: 'account',
                    group: 'mfa',
                    name: 'createMFAChallenge',
                    description: '/docs/references/account/create-mfa-challenge.md',
                    auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::JWT],
                    responses: [
                        new SDKResponse(
                            code: Response::STATUS_CODE_CREATED,
                            model: Response::MODEL_MFA_CHALLENGE,
                        )
                    ],
                    contentType: ContentType::JSON
                )
            ])
            ->label('abuse-limit', 10)
            ->label('abuse-key', 'url:{url},userId:{userId}')
            ->param('factor', '', new WhiteList([Type::EMAIL, Type::PHONE, Type::TOTP, Type::RECOVERY_CODE]), 'Factor used for verification. Must be one of following: `' . Type::EMAIL . '`, `' . Type::PHONE . '`, `' . Type::TOTP . '`, `' . Type::RECOVERY_CODE . '`.')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('user')
            ->inject('locale')
            ->inject('project')
            ->inject('platform')
            ->inject('request')
            ->inject('queueForEvents')
            ->inject('queueForMessaging')
            ->inject('queueForMails')
            ->inject('timelimit')
            ->inject('queueForStatsUsage')
            ->inject('plan')
            ->inject('proofForToken')
            ->inject('proofForCode')
            ->callback($this->action(...));
    }

    public function action(
        string $factor,
        Response $response,
        Database $dbForProject,
        Document $user,
        Locale $locale,
        Document $project,
        array $platform,
        Request $request,
        Event $queueForEvents,
        Messaging $queueForMessaging,
        Mail $queueForMails,
        callable $timelimit,
        StatsUsage $queueForStatsUsage,
        array $plan,
        ProofsToken $proofForToken,
        ProofsCode $proofForCode
    ): void {
        $expire = DateTime::formatTz(DateTime::addSeconds(new \DateTime(), TOKEN_EXPIRATION_CONFIRM));

        $code = $proofForCode->generate();
        $challenge = new Document([
            'userId' => $user->getId(),
            'userInternalId' => $user->getSequence(),
            'type' => $factor,
            'token' => $proofForToken->generate(),
            'code' => $code,
            'expire' => $expire,
            '$permissions' => [
                Permission::read(Role::user($user->getId())),
                Permission::update(Role::user($user->getId())),
                Permission::delete(Role::user($user->getId())),
            ],
        ]);

        $challenge = $dbForProject->createDocument('challenges', $challenge);

        $projectName = $project->getAttribute('name');
        if ($project->getId() === 'console') {
            $projectName = $platform['platformName'];
        }

        // 9 levels up to project root
        $templatesPath = \dirname(__DIR__, 9) . '/app/config/locale/templates';

        switch ($factor) {
            case Type::PHONE:
                if (empty(System::getEnv('_APP_SMS_PROVIDER'))) {
                    throw new Exception(Exception::GENERAL_PHONE_DISABLED, 'Phone provider not configured');
                }
                if (empty($user->getAttribute('phone'))) {
                    throw new Exception(Exception::USER_PHONE_NOT_FOUND);
                }
                if (!$user->getAttribute('phoneVerification')) {
                    throw new Exception(Exception::USER_PHONE_NOT_VERIFIED);
                }

                $message = Template::fromFile($templatesPath . '/sms-base.tpl');

                $customTemplate = $project->getAttribute('templates', [])['sms.mfaChallenge-' . $locale->default] ?? [];
                if (!empty($customTemplate)) {
                    $message = $customTemplate['message'] ?? $message;
                }

                $messageContent = Template::fromString($locale->getText("sms.verification.body"));
                $messageContent
                    ->setParam('{{project}}', $projectName)
                    ->setParam('{{secret}}', $code);
                $messageContent = \strip_tags($messageContent->render());
                $message = $message->setParam('{{token}}', $messageContent);

                $message = $message->render();

                $phone = $user->getAttribute('phone');
                $queueForMessaging
                    ->setType(MESSAGE_SEND_TYPE_INTERNAL)
                    ->setMessage(new Document([
                        '$id' => $challenge->getId(),
                        'data' => [
                            'content' => $code,
                        ],
                    ]))
                    ->setRecipients([$phone])
                    ->setProviderType(MESSAGE_TYPE_SMS);

                if (isset($plan['authPhone'])) {
                    $timelimit = $timelimit('organization:{organizationId}', $plan['authPhone'], 30 * 24 * 60 * 60); // 30 days
                    $timelimit
                        ->setParam('{organizationId}', $project->getAttribute('teamId'));

                    $abuse = new Abuse($timelimit);
                    if ($abuse->check() && System::getEnv('_APP_OPTIONS_ABUSE', 'enabled') === 'enabled') {
                        $helper = PhoneNumberUtil::getInstance();
                        $countryCode = $helper->parse($phone)->getCountryCode();

                        if (!empty($countryCode)) {
                            $queueForStatsUsage
                                ->addMetric(str_replace('{countryCode}', $countryCode, METRIC_AUTH_METHOD_PHONE_COUNTRY_CODE), 1);
                        }
                    }
                    $queueForStatsUsage
                        ->addMetric(METRIC_AUTH_METHOD_PHONE, 1)
                        ->setProject($project)
                        ->trigger();
                }
                break;
            case Type::EMAIL:
                if (empty(System::getEnv('_APP_SMTP_HOST'))) {
                    throw new Exception(Exception::GENERAL_SMTP_DISABLED, 'SMTP disabled');
                }
                if (empty($user->getAttribute('email'))) {
                    throw new Exception(Exception::USER_EMAIL_NOT_FOUND);
                }
                if (!$user->getAttribute('emailVerification')) {
                    throw new Exception(Exception::USER_EMAIL_NOT_VERIFIED);
                }

                $subject = $locale->getText("emails.mfaChallenge.subject");
                $preview = $locale->getText("emails.mfaChallenge.preview");
                $heading = $locale->getText("emails.mfaChallenge.heading");

                $customTemplate = $project->getAttribute('templates', [])['email.mfaChallenge-' . $locale->default] ?? [];
                $smtpBaseTemplate = $project->getAttribute('smtpBaseTemplate', 'email-base');

                $validator = new FileName();
                if (!$validator->isValid($smtpBaseTemplate)) {
                    throw new Exception(Exception::GENERAL_BAD_REQUEST, 'Invalid template path');
                }

                $bodyTemplate = $templatesPath . '/' . $smtpBaseTemplate . '.tpl';

                $detector = new Detector($request->getUserAgent('UNKNOWN'));
                $agentOs = $detector->getOS();
                $agentClient = $detector->getClient();
                $agentDevice = $detector->getDevice();

                $message = Template::fromFile($templatesPath . '/email-mfa-challenge.tpl');
                $message
                    ->setParam('{{hello}}', $locale->getText("emails.mfaChallenge.hello"))
                    ->setParam('{{description}}', $locale->getText("emails.mfaChallenge.description"))
                    ->setParam('{{clientInfo}}', $locale->getText("emails.mfaChallenge.clientInfo"))
                    ->setParam('{{thanks}}', $locale->getText("emails.mfaChallenge.thanks"))
                    ->setParam('{{signature}}', $locale->getText("emails.mfaChallenge.signature"));

                $body = $message->render();

                $smtp = $project->getAttribute('smtp', []);
                $smtpEnabled = $smtp['enabled'] ?? false;

                $senderEmail = System::getEnv('_APP_SYSTEM_EMAIL_ADDRESS', APP_EMAIL_TEAM);
                $senderName = System::getEnv('_APP_SYSTEM_EMAIL_NAME', APP_NAME . ' Server');
                $replyTo = "";

                if ($smtpEnabled) {
                    if (!empty($smtp['senderEmail'])) {
                        $senderEmail = $smtp['senderEmail'];
                    }
                    if (!empty($smtp['senderName'])) {
                        $senderName = $smtp['senderName'];
                    }
                    if (!empty($smtp['replyTo'])) {
                        $replyTo = $smtp['replyTo'];
                    }

                    $queueForMails
                        ->setSmtpHost($smtp['host'] ?? '')
                        ->setSmtpPort($smtp['port'] ?? '')
                        ->setSmtpUsername($smtp['username'] ?? '')
                        ->setSmtpPassword($smtp['password'] ?? '')
                        ->setSmtpSecure($smtp['secure'] ?? '');

                    if (!empty($customTemplate)) {
                        if (!empty($customTemplate['senderEmail'])) {
                            $senderEmail = $customTemplate['senderEmail'];
                        }
                        if (!empty($customTemplate['senderName'])) {
                            $senderName = $customTemplate['senderName'];
                        }
                        if (!empty($customTemplate['replyTo'])) {
                            $replyTo = $customTemplate['replyTo'];
                        }

                        $body = $customTemplate['message'] ?? '';
                        $subject = $customTemplate['subject'] ?? $subject;
                    }

                    $queueForMails
                        ->setSmtpReplyTo($replyTo)
                        ->setSmtpSenderEmail($senderEmail)
                        ->setSmtpSenderName($senderName);
                }

                $emailVariables = [
                    'heading' => $heading,
                    'direction' => $locale->getText('settings.direction'),
                    'user' => $user->getAttribute('name'),
                    'project' => $projectName,
                    'otp' => $code,
                    'agentDevice' => $agentDevice['deviceBrand'] ?? 'UNKNOWN',
                    'agentClient' => $agentClient['clientName'] ?? 'UNKNOWN',
                    'agentOs' => $agentOs['osName'] ?? 'UNKNOWN',
                ];

                if ($smtpBaseTemplate === APP_BRANDED_EMAIL_BASE_TEMPLATE) {
                    $emailVariables = array_merge($emailVariables, [
                        'accentColor' => $platform['accentColor'],
                        'logoUrl' => $platform['logoUrl'],
                        'twitter' => $platform['twitterUrl'],
                        'discord' => $platform['discordUrl'],
                        'github' => $platform['githubUrl'],
                        'terms' => $platform['termsUrl'],
                        'privacy' => $platform['privacyUrl'],
                        'platform' => $platform['platformName'],
                    ]);
                }

                $queueForMails
                    ->setSubject($subject)
                    ->setPreview($preview)
                    ->setBody($body)
                    ->setBodyTemplate($bodyTemplate)
                    ->setVariables($emailVariables)
                    ->setRecipient($user->getAttribute('email'));

                // since this is console project, set email sender name!
                if ($smtpBaseTemplate === APP_BRANDED_EMAIL_BASE_TEMPLATE) {
                    $queueForMails->setSenderName($platform['emailSenderName']);
                }

                $queueForMails->trigger();
                break;
        }

        $queueForEvents
            ->setParam('userId', $user->getId())
            ->setParam('challengeId', $challenge->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($challenge, Response::MODEL_MFA_CHALLENGE);
    }
}

<?php

namespace Appwrite\Platform\Modules\Account\Http\Account\Verifications\Email\OTP;

use Appwrite\Auth\OTPToken;
use Appwrite\Detector\Detector;
use Appwrite\Event\Event;
use Appwrite\Event\Message\Mail as MailMessage;
use Appwrite\Event\Publisher\Mail as MailPublisher;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Template\Template;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Auth\Proofs\Code as ProofsCode;
use Utopia\Auth\Proofs\Phrase;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Id;
use Utopia\Database\Permission;
use Utopia\Database\Role;
use Utopia\Database\Validator\Authorization;
use Utopia\Locale\Locale;
use Utopia\Platform\Scope\HTTP;
use Utopia\Storage\Validator\FileName;
use Utopia\System\System;
use Utopia\Validator\Boolean;

class Create extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'createEmailVerificationOTP';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/account/verifications/email/otp')
            ->desc('Create email verification (OTP)')
            ->groups(['api', 'account'])
            ->label('scope', 'account')
            ->label('event', 'users.[userId].verification.[tokenId].create')
            ->label('audits.event', 'verification.create')
            ->label('audits.resource', 'user/{response.userId}')
            ->label('sdk', new Method(
                namespace: 'account',
                group: 'verification',
                name: 'createEmailVerificationOTP',
                description: '/docs/references/account/create-email-verification-otp.md',
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
            ->label('abuse-key', 'url:{url},userId:{userId}')
            ->param('phrase', false, new Boolean(), 'Toggle for security phrase. If enabled, email will be sent with a randomly generated phrase and the phrase will also be included in the response. Confirming phrases match increases the security of your authentication flow.', true)
            ->inject('request')
            ->inject('response')
            ->inject('project')
            ->inject('platform')
            ->inject('user')
            ->inject('dbForProject')
            ->inject('locale')
            ->inject('queueForEvents')
            ->inject('publisherForMails')
            ->inject('proofForCode')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(
        bool $phrase,
        Request $request,
        Response $response,
        Document $project,
        array $platform,
        User $user,
        Database $dbForProject,
        Locale $locale,
        Event $queueForEvents,
        MailPublisher $publisherForMails,
        ProofsCode $proofForCode,
        Authorization $authorization,
    ): void {
        if (empty(System::getEnv('_APP_SMTP_HOST'))) {
            throw new Exception(Exception::GENERAL_SMTP_DISABLED, 'SMTP Disabled');
        }

        $email = \strtolower((string) $user->getAttribute('email'));
        if ($email === '') {
            throw new Exception(Exception::USER_EMAIL_NOT_FOUND);
        }

        if ($user->getAttribute('emailVerification')) {
            throw new Exception(Exception::USER_EMAIL_ALREADY_VERIFIED);
        }

        if ($phrase === true) {
            $phrase = (new Phrase())->generate();
        }

        $secret = $proofForCode->generate();
        $expire = DateTime::addSeconds(new \DateTime(), TOKEN_EXPIRATION_OTP);

        $verification = new Document([
            '$id' => Id::unique(),
            'userId' => $user->getId(),
            'userInternalId' => $user->getSequence(),
            'type' => TOKEN_TYPE_VERIFICATION_OTP,
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

        $verification = $authorization->skip(fn () => $dbForProject->withTransaction(function () use ($dbForProject, $user, $email, $verification) {
            $current = $dbForProject->getDocument('users', $user->getId(), forUpdate: true);
            if (
                $current->isEmpty()
                || $current->getAttribute('emailVerification')
                || \strtolower((string) $current->getAttribute('email')) !== $email
            ) {
                return new Document();
            }

            return $dbForProject->createDocument('tokens', $verification);
        }));

        if ($verification->isEmpty()) {
            $current = $authorization->skip(fn () => $dbForProject->getDocument('users', $user->getId()));
            if (!$current->isEmpty() && $current->getAttribute('emailVerification')) {
                throw new Exception(Exception::USER_EMAIL_ALREADY_VERIFIED);
            }
            throw new Exception(Exception::USER_EMAIL_NOT_FOUND);
        }

        $dbForProject->purgeCachedDocument('users', $user->getId());

        $subject = $locale->getText('emails.otpVerification.subject');
        $preview = $locale->getText('emails.otpVerification.preview');
        $heading = $locale->getText('emails.otpVerification.heading');

        $customTemplate =
            $project->getAttribute('templates', [])['email.otpVerification-' . $locale->default] ??
            $project->getAttribute('templates', [])['email.otpVerification-' . $locale->fallback] ?? [];
        $smtpBaseTemplate = $project->getAttribute('smtpBaseTemplate', 'email-base');

        $validator = new FileName();
        if (!$validator->isValid($smtpBaseTemplate)) {
            throw new Exception(Exception::GENERAL_BAD_REQUEST, 'Invalid template path');
        }

        $bodyTemplate = APP_CE_CONFIG_DIR . '/locale/templates/' . $smtpBaseTemplate . '.tpl';

        $detector = new Detector($request->getUserAgent('UNKNOWN'));
        $agentOs = $detector->getOS();
        $agentClient = $detector->getClient();
        $agentDevice = $detector->getDevice();

        $message = Template::fromFile(APP_CE_CONFIG_DIR . '/locale/templates/email-otp.tpl');
        $message
            ->setParam('{{hello}}', $locale->getText('emails.otpVerification.hello'))
            ->setParam('{{description}}', $locale->getText('emails.otpVerification.description'))
            ->setParam('{{clientInfo}}', $locale->getText('emails.otpVerification.clientInfo'))
            ->setParam('{{thanks}}', $locale->getText('emails.otpVerification.thanks'))
            ->setParam('{{signature}}', $locale->getText('emails.otpVerification.signature'));

        if (!empty($phrase)) {
            $message->setParam('{{securityPhrase}}', $locale->getText('emails.otpVerification.securityPhrase'));
        } else {
            $message->setParam('{{securityPhrase}}', '');
        }

        $body = $message->render();

        $smtp = $project->getAttribute('smtp', []);
        $smtpEnabled = $smtp['enabled'] ?? false;

        $senderEmail = System::getEnv('_APP_SYSTEM_EMAIL_ADDRESS', APP_EMAIL_TEAM);
        $senderName = System::getEnv('_APP_SYSTEM_EMAIL_NAME', APP_NAME . ' Server');
        $replyToEmail = '';
        $replyToName = '';
        $smtpConfig = [];

        if ($smtpEnabled) {
            if (!empty($smtp['senderEmail'])) {
                $senderEmail = $smtp['senderEmail'];
            }
            if (!empty($smtp['senderName'])) {
                $senderName = $smtp['senderName'];
            }
            $smtpReplyToEmail = $smtp['replyToEmail'] ?? $smtp['replyTo'] ?? '';
            if (!empty($smtpReplyToEmail)) {
                $replyToEmail = $smtpReplyToEmail;
            }
            if (!empty($smtp['replyToName'])) {
                $replyToName = $smtp['replyToName'];
            }

            if (!empty($customTemplate)) {
                if (!empty($customTemplate['senderEmail'])) {
                    $senderEmail = $customTemplate['senderEmail'];
                }
                if (!empty($customTemplate['senderName'])) {
                    $senderName = $customTemplate['senderName'];
                }
                $customReplyToEmail = $customTemplate['replyToEmail'] ?? $customTemplate['replyTo'] ?? '';
                if (!empty($customReplyToEmail)) {
                    $replyToEmail = $customReplyToEmail;
                }
                if (!empty($customTemplate['replyToName'])) {
                    $replyToName = $customTemplate['replyToName'];
                }

                $body = $customTemplate['message'] ?? '';
                $subject = $customTemplate['subject'] ?? $subject;
            }

            $smtpConfig = [
                'host' => $smtp['host'] ?? '',
                'port' => $smtp['port'] ?? '',
                'username' => $smtp['username'] ?? '',
                'password' => $smtp['password'] ?? '',
                'secure' => $smtp['secure'] ?? '',
                'replyToEmail' => $replyToEmail,
                'replyToName' => $replyToName,
                'senderEmail' => $senderEmail,
                'senderName' => $senderName,
            ];
        }

        $projectName = $project->getAttribute('name');
        if ($project->getId() === 'console') {
            $projectName = $platform['platformName'];
        }

        $emailVariables = [
            'heading' => $heading,
            'direction' => $locale->getText('settings.direction'),
            'user' => $user->getAttribute('name'),
            'project' => $projectName,
            'otp' => $secret,
            'agentDevice' => $agentDevice['deviceBrand'] ?? 'UNKNOWN',
            'agentClient' => $agentClient['clientName'] ?? 'UNKNOWN',
            'agentOs' => $agentOs['osName'] ?? 'UNKNOWN',
            'phrase' => !empty($phrase) ? $phrase : '',
            'team' => '',
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

        $publisherForMails->enqueue(new MailMessage(
            project: $project,
            recipient: $user->getAttribute('email'),
            name: $user->getAttribute('name') ?? '',
            subject: $subject,
            template: MAIL_TEMPLATE_OTP_VERIFICATION,
            bodyTemplate: $bodyTemplate,
            body: $body,
            preview: $preview,
            smtp: $smtpConfig,
            variables: $emailVariables,
            customMailOptions: $project->getId() === 'console' ? ['senderName' => $platform['emailSenderName']] : [],
            platform: $platform,
            headers: OTPToken::from($secret, $request->getOrigin())?->toHeaders() ?? [],
        ));

        $verification->setAttribute('secret', $secret);

        if (!empty($phrase)) {
            $verification->setAttribute('phrase', $phrase);
        }

        $queueForEvents
            ->setParam('userId', $user->getId())
            ->setParam('tokenId', $verification->getId())
            ->setPayload($response->showSensitive(fn () => $response->output($verification, Response::MODEL_TOKEN)), sensitive: ['secret']);

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($verification, Response::MODEL_TOKEN);
    }
}

<?php

namespace Appwrite\Platform\Modules\Account\Http\Account\Verifications\Email;

use Appwrite\Event\Event;
use Appwrite\Event\Message\Mail as MailMessage;
use Appwrite\Event\Publisher\Mail as MailPublisher;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Deprecated;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Template\Template;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Auth\Proofs\Token as ProofsToken;
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

class Create extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'createEmailVerification';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/account/verifications/email')
            ->httpAlias('/v1/account/verification')
            ->desc('Create email verification')
            ->groups(['api', 'account'])
            ->label('scope', 'account')
            ->label('event', 'users.[userId].verification.[tokenId].create')
            ->label('audits.event', 'verification.create')
            ->label('audits.resource', 'user/{response.userId}')
            ->label('sdk', [
                new Method(
                    namespace: 'account',
                    group: 'verification',
                    name: 'createEmailVerification',
                    description: '/docs/references/account/create-email-verification.md',
                    auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::JWT],
                    responses: [
                        new SDKResponse(
                            code: Response::STATUS_CODE_CREATED,
                            model: Response::MODEL_TOKEN,
                        )
                    ],
                    contentType: ContentType::JSON,
                ),
                new Method(
                    namespace: 'account',
                    group: 'verification',
                    name: 'createVerification',
                    description: '/docs/references/account/create-email-verification.md',
                    auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::JWT],
                    responses: [
                        new SDKResponse(
                            code: Response::STATUS_CODE_CREATED,
                            model: Response::MODEL_TOKEN,
                        )
                    ],
                    contentType: ContentType::JSON,
                    deprecated: new Deprecated(
                        since: '1.8.0',
                        replaceWith: 'account.createEmailVerification'
                    ),
                    public: false,
                )
            ])
            ->label('abuse-limit', 10)
            ->label('abuse-key', 'url:{url},userId:{userId}')
            ->param('url', '', fn ($redirectValidator) => $redirectValidator, 'URL to redirect the user back to your app from the verification email. Only URLs from hostnames in your project platform list are allowed. This requirement helps to prevent an [open redirect](https://cheatsheetseries.owasp.org/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.html) attack against your project API.', false, ['redirectValidator']) // TODO add built-in confirm page
            ->inject('request')
            ->inject('response')
            ->inject('project')
            ->inject('platform')
            ->inject('user')
            ->inject('dbForProject')
            ->inject('locale')
            ->inject('queueForEvents')
            ->inject('publisherForMails')
            ->inject('proofForToken')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(
        string $url,
        Request $request,
        Response $response,
        Document $project,
        array $platform,
        User $user,
        Database $dbForProject,
        Locale $locale,
        Event $queueForEvents,
        MailPublisher $publisherForMails,
        ProofsToken $proofForToken,
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

        $verificationSecret = $proofForToken->generate();
        $expire = DateTime::formatTz(DateTime::addSeconds(new \DateTime(), TOKEN_EXPIRATION_CONFIRM));

        $verification = new Document([
            '$id' => Id::unique(),
            'userId' => $user->getId(),
            'userInternalId' => $user->getSequence(),
            'type' => TOKEN_TYPE_VERIFICATION,
            'secret' => $proofForToken->hash($verificationSecret), // One way hash encryption to protect DB leak
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

        $url = Template::parseURL($url);
        $url['query'] = Template::mergeQuery(((isset($url['query'])) ? $url['query'] : ''), ['userId' => $user->getId(), 'secret' => $verificationSecret, 'expire' => $expire]);
        $url = Template::unParseURL($url);

        $projectName = $project->isEmpty()
            ? 'Console'
            : $project->getAttribute('name', '[APP-NAME]');

        if ($project->getId() === 'console') {
            $projectName = $platform['platformName'];
        }


        $body = $locale->getText("emails.verification.body");
        $preview = $locale->getText("emails.verification.preview");
        $subject = $locale->getText("emails.verification.subject");
        $heading = $locale->getText("emails.verification.heading");

        $customTemplate =
            $project->getAttribute('templates', [])['email.verification-' . $locale->default] ??
            $project->getAttribute('templates', [])['email.verification-' . $locale->fallback] ?? [];
        $smtpBaseTemplate = $project->getAttribute('smtpBaseTemplate', 'email-base');

        $validator = new FileName();
        if (!$validator->isValid($smtpBaseTemplate)) {
            throw new Exception(Exception::GENERAL_BAD_REQUEST, 'Invalid template path');
        }

        $bodyTemplate = APP_CE_CONFIG_DIR . '/locale/templates/' . $smtpBaseTemplate . '.tpl';

        $message = Template::fromFile(APP_CE_CONFIG_DIR . '/locale/templates/email-inner-base.tpl');
        $message
            ->setParam('{{body}}', $body, escapeHtml: false)
            ->setParam('{{hello}}', $locale->getText("emails.verification.hello"))
            ->setParam('{{footer}}', $locale->getText("emails.verification.footer"))
            ->setParam('{{thanks}}', $locale->getText("emails.verification.thanks"))
            ->setParam('{{buttonText}}', $locale->getText("emails.verification.buttonText"))
            ->setParam('{{signature}}', $locale->getText("emails.verification.signature"));

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
            // Includes backwards compatibility: fall back to legacy `replyTo` key
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
                // Includes backwards compatibility: fall back to legacy `replyTo` key
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

        $emailVariables = [
            'heading' => $heading,
            'direction' => $locale->getText('settings.direction'),
            // {{user}}, {{redirect}} and {{project}} are required in default and custom templates
            'user' => $user->getAttribute('name'),
            'redirect' => $url,
            'project' => $projectName,
            // TODO: remove unnecessary team variable from this email
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
            template: MAIL_TEMPLATE_VERIFICATION,
            bodyTemplate: $bodyTemplate,
            body: $body,
            preview: $preview,
            smtp: $smtpConfig,
            variables: $emailVariables,
            customMailOptions: $project->getId() === 'console' ? ['senderName' => $platform['emailSenderName']] : [],
            platform: $platform,
        ));

        $verification->setAttribute('secret', $verificationSecret);

        $queueForEvents
            ->setParam('userId', $user->getId())
            ->setParam('tokenId', $verification->getId())
            ->setPayload($response->showSensitive(fn () => $response->output($verification, Response::MODEL_TOKEN)), sensitive: ['secret']);

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($verification, Response::MODEL_TOKEN);
    }
}

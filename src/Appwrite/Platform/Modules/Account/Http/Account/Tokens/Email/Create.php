<?php

namespace Appwrite\Platform\Modules\Account\Http\Account\Tokens\Email;

use Appwrite\Auth\Key;
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
use Appwrite\Utopia\Database\Validator\CustomId;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Auth\Proofs\Code as ProofsCode;
use Utopia\Auth\Proofs\Password as ProofsPassword;
use Utopia\Auth\Proofs\Phrase;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Id;
use Utopia\Database\Permission;
use Utopia\Database\Query;
use Utopia\Database\Role;
use Utopia\Database\SetType;
use Utopia\Database\Validator\Authorization;
use Utopia\Emails\Email;
use Utopia\Emails\Validator\Email as EmailValidator;
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
        return 'createEmailToken';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/account/tokens/email')
            ->desc('Create email token (OTP)')
            ->groups(['api', 'account', 'auth'])
            ->label('scope', 'sessions.write')
            ->label('auth.type', 'email-otp')
            ->label('audits.event', 'session.create')
            ->label('audits.resource', 'user/{response.userId}')
            ->label('audits.userId', '{response.userId}')
            ->label('sdk', new Method(
                namespace: 'account',
                group: 'tokens',
                name: 'createEmailToken',
                description: '/docs/references/account/create-token-email.md',
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
            ->label('abuse-key', ['url:{url},email:{param-email}', 'url:{url},ip:{ip}'])
            ->param('userId', '', fn (Database $dbForProject) => new CustomId(false, $dbForProject->getMaxUidLength()), 'User ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars. If the email address has never been used, a new account is created using the provided userId. Otherwise, if the email address is already attached to an account, the user ID is ignored.', false, ['dbForProject'])
            ->param('email', '', new EmailValidator(), 'User email.')
            ->param('phrase', false, new Boolean(), 'Toggle for security phrase. If enabled, email will be send with a randomly generated phrase and the phrase will also be included in the response. Confirming phrases match increases the security of your authentication flow.', true)
            ->inject('request')
            ->inject('response')
            ->inject('project')
            ->inject('platform')
            ->inject('dbForProject')
            ->inject('locale')
            ->inject('queueForEvents')
            ->inject('publisherForMails')
            ->inject('plan')
            ->inject('proofForPassword')
            ->inject('proofForCode')
            ->inject('authorization')
            ->inject('apiKey')
            ->callback($this->action(...));
    }

    public function action(
        string $userId,
        string $email,
        bool $phrase,
        Request $request,
        Response $response,
        Document $project,
        array $platform,
        Database $dbForProject,
        Locale $locale,
        Event $queueForEvents,
        MailPublisher $publisherForMails,
        array $plan,
        ProofsPassword $proofForPassword,
        ProofsCode $proofForCode,
        Authorization $authorization,
        ?Key $apiKey,
    ): void {
        if (empty(System::getEnv('_APP_SMTP_HOST'))) {
            throw new Exception(Exception::GENERAL_SMTP_DISABLED, 'SMTP disabled');
        }

        if ($phrase === true) {
            $phrase = (new Phrase())->generate();
        }

        $result = $dbForProject->findOne('users', [Query::equal('email', [$email])]);
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

            // Makes sure this email is not already used in another identity
            $identityWithMatchingEmail = $dbForProject->findOne('identities', [
                Query::equal('providerEmail', [$email]),
            ]);
            if (!$identityWithMatchingEmail->isEmpty()) {
                throw new Exception(Exception::GENERAL_BAD_REQUEST);
                /** Return a generic bad request to prevent exposing existing accounts */
            }

            $userId = $userId === 'unique()' ? Id::unique() : $userId;

            $emailMetadata = [
                'emailCanonical' => null,
                'emailIsCanonical' => null,
                'emailIsCorporate' => null,
                'emailIsDisposable' => null,
                'emailIsFree' => null,
            ];

            try {
                $parsedEmail = new Email($email);
                $canonical = $parsedEmail->getCanonical();
                $emailMetadata = [
                    'emailCanonical' => $canonical,
                    'emailIsCanonical' => $parsedEmail->get() === $canonical,
                    'emailIsCorporate' => $parsedEmail->isCorporate(),
                    'emailIsDisposable' => $parsedEmail->isDisposable(),
                    'emailIsFree' => $parsedEmail->isFree(),
                ];
            } catch (\Throwable) {
                throw new Exception(Exception::GENERAL_INVALID_EMAIL);
            }

            if ((($project->getId() === 'console') || empty($plan) || ($plan['supportsDisposableEmailValidation'] ?? false)) && ($project->getAttribute('auths', [])['disposableEmails'] ?? false) && $emailMetadata['emailIsDisposable']) {
                throw new Exception(Exception::USER_EMAIL_DISPOSABLE);
            }

            if ((($project->getId() === 'console') || empty($plan) || ($plan['supportsCanonicalEmailValidation'] ?? false)) && ($project->getAttribute('auths', [])['canonicalEmails'] ?? false) && $emailMetadata['emailIsCanonical'] === false) {
                throw new Exception(Exception::USER_EMAIL_NOT_CANONICAL);
            }

            if ((($project->getId() === 'console') || empty($plan) || ($plan['supportsFreeEmailValidation'] ?? false)) && ($project->getAttribute('auths', [])['freeEmails'] ?? false) && $emailMetadata['emailIsFree']) {
                throw new Exception(Exception::USER_EMAIL_FREE);
            }

            if ((($project->getId() === 'console') || empty($plan) || ($plan['supportsCorporateEmailValidation'] ?? false)) && ($project->getAttribute('auths', [])['corporateEmails'] ?? false) && !$emailMetadata['emailIsCorporate']) {
                throw new Exception(Exception::USER_EMAIL_NOT_CORPORATE);
            }

            $recipient = new Document([
                '$id' => $userId,
                '$permissions' => [
                    Permission::read(Role::any()),
                    Permission::update(Role::user($userId)),
                    Permission::delete(Role::user($userId)),
                ],
                'email' => $email,
                'emailVerification' => false,
                'status' => true,
                'password' => null,
                'hash' => $proofForPassword->getHash()->getName(),
                'hashOptions' => $proofForPassword->getHash()->getOptions(),
                'passwordUpdate' => null,
                'registration' => DateTime::now(),
                'reset' => false,
                'prefs' => new \stdClass(),
                'sessions' => null,
                'tokens' => null,
                'memberships' => null,
                'search' => implode(' ', [$userId, $email]),
                'accessedAt' => DateTime::now(),
                'emailCanonical' => $emailMetadata['emailCanonical'],
                'emailIsCanonical' => $emailMetadata['emailIsCanonical'],
                'emailIsCorporate' => $emailMetadata['emailIsCorporate'],
                'emailIsDisposable' => $emailMetadata['emailIsDisposable'],
                'emailIsFree' => $emailMetadata['emailIsFree'],
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
                    'providerType' => MESSAGE_TYPE_EMAIL,
                    'identifier' => $email,
                ])));
                $recipient->setAttribute('targets', [...$recipient->getAttribute('targets', []), $target]);
            } catch (Duplicate) {
                $existingTarget = $dbForProject->findOne('targets', [
                    Query::equal('identifier', [$email]),
                ]);
                if (!$existingTarget->isEmpty()) {
                    $recipient->setAttribute('targets', $existingTarget, SetType::Append);
                }
            }

            $dbForProject->purgeCachedDocument('users', $recipient->getId());
        }

        $tokenSecret = $proofForCode->generate();
        $expire = DateTime::addSeconds(new \DateTime(), TOKEN_EXPIRATION_OTP);

        $token = new Document([
            '$id' => Id::unique(),
            'userId' => $recipient->getId(),
            'userInternalId' => $recipient->getSequence(),
            'type' => TOKEN_TYPE_EMAIL,
            'secret' => $proofForCode->hash($tokenSecret), // One way hash encryption to protect DB leak
            'expire' => $expire,
            'userAgent' => $request->getUserAgent('UNKNOWN'),
            'ip' => $request->getIP(),
        ]);

        $token->setAttribute('$permissions', [
            Permission::read(Role::user($recipient->getId())),
            Permission::update(Role::user($recipient->getId())),
            Permission::delete(Role::user($recipient->getId())),
        ]);

        $created = $authorization->skip(fn () => $dbForProject->withTransaction(function () use ($dbForProject, $recipient, $email, $token) {
            $current = $dbForProject->getDocument('users', $recipient->getId(), forUpdate: true);
            if (
                $current->isEmpty()
                || \strtolower((string) $current->getAttribute('email')) !== \strtolower($email)
            ) {
                return new Document();
            }

            return $dbForProject->createDocument('tokens', $token);
        }));

        $deliverable = !$created->isEmpty();
        if ($deliverable) {
            $token = $created;
            $dbForProject->purgeCachedDocument('users', $recipient->getId());
        } else {
            $dummyUserId = Id::unique();
            $token
                ->setAttribute('userId', $dummyUserId)
                ->setAttribute('userInternalId', Id::unique())
                ->setAttribute('$permissions', [
                    Permission::read(Role::user($dummyUserId)),
                    Permission::update(Role::user($dummyUserId)),
                    Permission::delete(Role::user($dummyUserId)),
                ]);
            $token = $authorization->skip(fn () => $dbForProject->createDocument('tokens', $token));
            $authorization->skip(fn () => $dbForProject->deleteDocument('tokens', $token->getId()));
        }

        $subject = $locale->getText("emails.otpSession.subject");
        $preview = $locale->getText("emails.otpSession.preview");
        $heading = $locale->getText("emails.otpSession.heading");

        $customTemplate =
            $project->getAttribute('templates', [])['email.otpSession-' . $locale->default] ??
            $project->getAttribute('templates', [])['email.otpSession-' . $locale->fallback] ?? [];
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
            ->setParam('{{hello}}', $locale->getText("emails.otpSession.hello"))
            ->setParam('{{description}}', $locale->getText("emails.otpSession.description"))
            ->setParam('{{clientInfo}}', $locale->getText("emails.otpSession.clientInfo"))
            ->setParam('{{thanks}}', $locale->getText("emails.otpSession.thanks"))
            ->setParam('{{signature}}', $locale->getText("emails.otpSession.signature"));

        if (!empty($phrase)) {
            $message->setParam('{{securityPhrase}}', $locale->getText("emails.otpSession.securityPhrase"));
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

        $projectName = $project->getAttribute('name');
        if ($project->getId() === 'console') {
            $projectName = $platform['platformName'];
        }

        $emailVariables = [
            'heading' => $heading,
            'direction' => $locale->getText('settings.direction'),
            // {{user}}, {{project}} and {{otp}} are required in the templates
            'user' => $recipient->getAttribute('name'),
            'project' => $projectName,
            'otp' => $tokenSecret,
            'agentDevice' => $agentDevice['deviceBrand'] ?? $agentDevice['deviceBrand'] ?? 'UNKNOWN',
            'agentClient' => $agentClient['clientName'] ?? 'UNKNOWN',
            'agentOs' => $agentOs['osName'] ?? 'UNKNOWN',
            'phrase' => !empty($phrase) ? $phrase : '',
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

        if ($deliverable) {
            $publisherForMails->enqueue(new MailMessage(
                project: $project,
                recipient: $email,
                subject: $subject,
                template: MAIL_TEMPLATE_OTP,
                bodyTemplate: $bodyTemplate,
                body: $body,
                preview: $preview,
                smtp: $smtpConfig,
                variables: $emailVariables,
                customMailOptions: $smtpBaseTemplate === APP_BRANDED_EMAIL_BASE_TEMPLATE ? ['senderName' => $platform['emailSenderName']] : [],
                platform: $platform,
                headers: OTPToken::from($tokenSecret, $request->getOrigin())?->toHeaders() ?? [],
            ));
        }

        $token->setAttribute('secret', $tokenSecret);

        $queueForEvents
            ->setPayload($response->output($token, Response::MODEL_TOKEN), sensitive: ['secret']);

        if (!empty($phrase)) {
            $token->setAttribute('phrase', $phrase);
        }

        // The secret proves control of the inbox or phone; only keys that can already mint tokens through users.write may read it back.
        if ($apiKey !== null && !\in_array('users.write', $apiKey->getScopes())) {
            $token->setAttribute('secret', '');
        }

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($token, Response::MODEL_TOKEN);
    }
}

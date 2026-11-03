<?php

namespace Appwrite\Platform\Modules\Account\Http\Account\Recovery;

use Appwrite\Auth\Key;
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
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Auth\Proofs\Token as ProofsToken;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Id;
use Utopia\Database\Permission;
use Utopia\Database\Query;
use Utopia\Database\Role;
use Utopia\Database\Validator\Authorization;
use Utopia\Emails\Validator\Email as EmailValidator;
use Utopia\Locale\Locale;
use Utopia\Platform\Scope\HTTP;
use Utopia\Storage\Validator\FileName;
use Utopia\System\System;

class Create extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'createRecovery';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/account/recovery')
            ->desc('Create password recovery')
            ->groups(['api', 'account'])
            ->label('scope', 'sessions.write')
            ->label('event', 'users.[userId].recovery.[tokenId].create')
            ->label('audits.event', 'recovery.create')
            ->label('audits.resource', 'user/{response.userId}')
            ->label('audits.userId', '{response.userId}')
            ->label('sdk', new Method(
                namespace: 'account',
                group: 'recovery',
                name: 'createRecovery',
                description: '/docs/references/account/create-recovery.md',
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
            ->param('email', '', new EmailValidator(), 'User email.')
            ->param('url', '', fn ($redirectValidator) => $redirectValidator, 'URL to redirect the user back to your app from the recovery email. Only URLs from hostnames in your project platform list are allowed. This requirement helps to prevent an [open redirect](https://cheatsheetseries.owasp.org/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.html) attack against your project API.', false, ['redirectValidator'])
            ->inject('request')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('project')
            ->inject('platform')
            ->inject('locale')
            ->inject('publisherForMails')
            ->inject('queueForEvents')
            ->inject('proofForToken')
            ->inject('authorization')
            ->inject('apiKey')
            ->callback($this->action(...));
    }

    public function action(
        string $email,
        string $url,
        Request $request,
        Response $response,
        Database $dbForProject,
        Document $project,
        array $platform,
        Locale $locale,
        MailPublisher $publisherForMails,
        Event $queueForEvents,
        ProofsToken $proofForToken,
        Authorization $authorization,
        ?Key $apiKey,
    ): void {
        if (empty(System::getEnv('_APP_SMTP_HOST'))) {
            throw new Exception(Exception::GENERAL_SMTP_DISABLED, 'SMTP Disabled');
        }

        $email = \strtolower($email);

        $profile = $dbForProject->findOne('users', [
            Query::equal('email', [$email]),
        ]);


        $deliverable = !$profile->isEmpty() && $profile->getAttribute('status') !== false;

        $userId = $deliverable ? $profile->getId() : Id::unique();

        $expire = DateTime::formatTz(DateTime::addSeconds(new \DateTime(), TOKEN_EXPIRATION_RECOVERY));

        $secret = $proofForToken->generate();
        $recovery = new Document([
            '$id' => Id::unique(),
            'userId' => $userId,
            'userInternalId' => $deliverable ? $profile->getSequence() : Id::unique(),
            'type' => TOKEN_TYPE_RECOVERY,
            'secret' => $proofForToken->hash($secret), // One way hash encryption to protect DB leak
            'expire' => $expire,
            'userAgent' => $request->getUserAgent('UNKNOWN'),
            'ip' => $request->getIP(),
        ]);

        $recovery->setAttribute('$permissions', [
            Permission::read(Role::user($userId)),
            Permission::update(Role::user($userId)),
            Permission::delete(Role::user($userId)),
        ]);

        if ($deliverable) {
            $created = $authorization->skip(fn () => $dbForProject->withTransaction(function () use ($dbForProject, $profile, $email, $recovery) {
                $current = $dbForProject->getDocument('users', $profile->getId(), forUpdate: true);
                if (
                    $current->isEmpty()
                    || $current->getAttribute('status') === false
                    || \strtolower((string) $current->getAttribute('email')) !== $email
                ) {
                    return new Document();
                }

                return $dbForProject->createDocument('tokens', $recovery);
            }));

            if ($created->isEmpty()) {
                $deliverable = false;
                $dummyUserId = Id::unique();
                $recovery
                    ->setAttribute('userId', $dummyUserId)
                    ->setAttribute('userInternalId', Id::unique())
                    ->setAttribute('$permissions', [
                        Permission::read(Role::user($dummyUserId)),
                        Permission::update(Role::user($dummyUserId)),
                        Permission::delete(Role::user($dummyUserId)),
                    ]);
                $recovery = $authorization->skip(fn () => $dbForProject->createDocument('tokens', $recovery));
            } else {
                $recovery = $created;
            }
        } else {
            $recovery = $authorization->skip(fn () => $dbForProject->createDocument('tokens', $recovery));
        }

        if ($deliverable) {
            $dbForProject->purgeCachedDocument('users', $profile->getId());
        } else {
            $authorization->skip(fn () => $dbForProject->deleteDocument('tokens', $recovery->getId()));
        }

        $url = Template::parseURL($url);
        $url['query'] = Template::mergeQuery(((isset($url['query'])) ? $url['query'] : ''), ['userId' => $userId, 'secret' => $secret, 'expire' => $expire]);
        $url = Template::unParseURL($url);

        $projectName = $project->isEmpty()
            ? 'Console'
            : $project->getAttribute('name', '[APP-NAME]');

        if ($project->getId() === 'console') {
            $projectName = $platform['platformName'];
        }

        $body = $locale->getText("emails.recovery.body");
        $subject = $locale->getText("emails.recovery.subject");
        $preview = $locale->getText("emails.recovery.preview");
        $customTemplate =
            $project->getAttribute('templates', [])['email.recovery-' . $locale->default] ??
            $project->getAttribute('templates', [])['email.recovery-' . $locale->fallback] ?? [];
        $smtpBaseTemplate = $project->getAttribute('smtpBaseTemplate', 'email-base');

        $validator = new FileName();
        if (!$validator->isValid($smtpBaseTemplate)) {
            throw new Exception(Exception::GENERAL_BAD_REQUEST, 'Invalid template path');
        }

        $bodyTemplate = APP_CE_CONFIG_DIR . '/locale/templates/' . $smtpBaseTemplate . '.tpl';

        $message = Template::fromFile(APP_CE_CONFIG_DIR . '/locale/templates/email-inner-base.tpl');
        $message
            ->setParam('{{body}}', $body, escapeHtml: false)
            ->setParam('{{hello}}', $locale->getText("emails.recovery.hello"))
            ->setParam('{{footer}}', $locale->getText("emails.recovery.footer"))
            ->setParam('{{thanks}}', $locale->getText("emails.recovery.thanks"))
            ->setParam('{{buttonText}}', $locale->getText("emails.recovery.buttonText"))
            ->setParam('{{signature}}', $locale->getText("emails.recovery.signature"));
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
            'direction' => $locale->getText('settings.direction'),
            // {{user}}, {{redirect}} and {{project}} are required in default and custom templates
            'user' => $deliverable ? $profile->getAttribute('name') : '',
            'redirect' => $url,
            'project' => $projectName,
            // TODO: remove unnecessary team variable from this email
            'team' => ''
        ];

        if ($smtpBaseTemplate === APP_BRANDED_EMAIL_BASE_TEMPLATE) {
            $emailVariables = [
                ...$emailVariables,
                'accentColor' => $platform['accentColor'],
                'logoUrl' => $platform['logoUrl'],
                'twitter' => $platform['twitterUrl'],
                'discord' => $platform['discordUrl'],
                'github' => $platform['githubUrl'],
                'terms' => $platform['termsUrl'],
                'privacy' => $platform['privacyUrl'],
                'platform' => $platform['platformName'],
            ];
        }

        if ($deliverable) {
            $publisherForMails->enqueue(new MailMessage(
                project: $project,
                recipient: $profile->getAttribute('email', ''),
                name: $profile->getAttribute('name', ''),
                subject: $subject,
                template: MAIL_TEMPLATE_RECOVERY,
                bodyTemplate: $bodyTemplate,
                body: $body,
                preview: $preview,
                smtp: $smtpConfig,
                variables: $emailVariables,
                customMailOptions: $project->getId() === 'console' ? ['senderName' => $platform['emailSenderName']] : [],
                platform: $platform,
            ));
        }

        $recovery->setAttribute('secret', $secret);

        $queueForEvents
            ->setParam('userId', $userId)
            ->setParam('tokenId', $recovery->getId())
            ->setUser($deliverable ? $profile : new Document())
            ->setPayload($response->showSensitive(fn () => $response->output($recovery, Response::MODEL_TOKEN)), sensitive: ['secret']);

        // The secret proves control of the inbox or phone; only keys that can already mint tokens through users.write may read it back.
        if ($apiKey !== null && !\in_array('users.write', $apiKey->getScopes())) {
            $recovery->setAttribute('secret', '');
        }

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($recovery, Response::MODEL_TOKEN);
    }
}

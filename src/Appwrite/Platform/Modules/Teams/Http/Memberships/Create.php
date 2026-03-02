<?php

namespace Appwrite\Platform\Modules\Teams\Http\Memberships;

use Appwrite\Auth\Validator\Phone;
use Appwrite\Event\Event;
use Appwrite\Event\Mail;
use Appwrite\Event\Messaging;
use Appwrite\Event\StatsUsage;
use Appwrite\Extend\Exception;
use Appwrite\Network\Validator\Email as EmailValidator;
use Appwrite\Platform\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Template\Template;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Response;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberUtil;
use Utopia\Auth\Proofs\Password;
use Utopia\Auth\Proofs\Token;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Key;
use Utopia\Database\Validator\UID;
use Utopia\Emails\Email;
use Utopia\Locale\Locale;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Text;

class Create extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'createTeamMembership';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/teams/:teamId/memberships')
            ->desc('Create team membership')
            ->groups(['api', 'teams', 'auth'])
            ->label('event', 'teams.[teamId].memberships.[membershipId].create')
            ->label('scope', 'teams.write')
            ->label('auth.type', 'invites')
            ->label('audits.event', 'membership.create')
            ->label('audits.resource', 'team/{request.teamId}')
            ->label('audits.userId', '{request.userId}')
            ->label('sdk', new Method(
                namespace: 'teams',
                group: 'memberships',
                name: 'createMembership',
                description: '/docs/references/teams/create-team-membership.md',
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::KEY, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_CREATED,
                        model: Response::MODEL_MEMBERSHIP,
                    )
                ]
            ))
            ->label('abuse-limit', 10)
            ->param('teamId', '', new UID(), 'Team ID.')
            ->param('email', '', new EmailValidator(), 'Email of the new team member.', true)
            ->param('userId', '', new UID(), 'ID of the user to be added to a team.', true)
            ->param('phone', '', new Phone(), 'Phone number. Format this number with a leading \'+\' and a country code, e.g., +16175551212.', true)
            ->param('roles', [], new ArrayList(new Key(maxLength: 81), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Array of strings. Use this param to set the user roles in the team. A role can be any string. Learn more about [roles and permissions](https://appwrite.io/docs/permissions). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' roles are allowed, each 81 characters long.', false, ['project']) // For project-specific permissions, roles will be in the format `project-<projectId>-<role>`. Template takes 9 characters, `projectId` and `role` can be upto 36 characters. In total, 81 characters.
            ->param('url', '', fn ($redirectValidator) => $redirectValidator, 'URL to redirect the user back to your app from the invitation email. This parameter is not required when an API key is supplied. Only URLs from hostnames in your project platform list are allowed. This requirement helps to prevent an [open redirect](https://cheatsheetseries.owasp.org/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.html) attack against your project API.', true, ['redirectValidator']) // TODO add our own built-in confirm page
            ->param('name', '', new Text(128), 'Name of the new team member. Max length: 128 chars.', true)
            ->inject('response')
            ->inject('project')
            ->inject('user')
            ->inject('dbForProject')
            ->inject('authorization')
            ->inject('locale')
            ->inject('queueForMails')
            ->inject('queueForMessaging')
            ->inject('queueForEvents')
            ->inject('timelimit')
            ->inject('queueForStatsUsage')
            ->inject('plan')
            ->inject('proofForPassword')
            ->inject('proofForToken')
            ->callback($this->action(...));
    }

    public function action(string $teamId, string $email, string $userId, string $phone, array $roles, string $url, string $name, Response $response, Document $project, Document $user, Database $dbForProject, Authorization $authorization, Locale $locale, Mail $queueForMails, Messaging $queueForMessaging, Event $queueForEvents, callable $timelimit, StatsUsage $queueForStatsUsage, array $plan, Password $proofForPassword, Token $proofForToken)
    {
        $isAppUser = User::isApp($authorization->getRoles());
        $isPrivilegedUser = User::isPrivileged($authorization->getRoles());

        if (empty($url)) {
            if (!$isAppUser && !$isPrivilegedUser) {
                throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'URL is required');
            }
        }

        if (empty($userId) && empty($email) && empty($phone)) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'At least one of userId, email, or phone is required');
        }

        if (!$isPrivilegedUser && !$isAppUser && empty(System::getEnv('_APP_SMTP_HOST'))) {
            throw new Exception(Exception::GENERAL_SMTP_DISABLED);
        }

        $email = \strtolower($email);
        $name = empty($name) ? $email : $name;
        $team = $dbForProject->getDocument('teams', $teamId);

        if ($team->isEmpty()) {
            throw new Exception(Exception::TEAM_NOT_FOUND);
        }
        if (!empty($userId)) {
            $invitee = $dbForProject->getDocument('users', $userId);
            if ($invitee->isEmpty()) {
                throw new Exception(Exception::USER_NOT_FOUND, 'User with given userId doesn\'t exist.', 404);
            }
            if (!empty($email) && $invitee->getAttribute('email', '') !== $email) {
                throw new Exception(Exception::USER_ALREADY_EXISTS, 'Given userId and email doesn\'t match', 409);
            }
            if (!empty($phone) && $invitee->getAttribute('phone', '') !== $phone) {
                throw new Exception(Exception::USER_ALREADY_EXISTS, 'Given userId and phone doesn\'t match', 409);
            }
            $email = $invitee->getAttribute('email', '');
            $phone = $invitee->getAttribute('phone', '');
            $name = $invitee->getAttribute('name', '') ?: $name;
        } elseif (!empty($email)) {
            $invitee = $dbForProject->findOne('users', [Query::equal('email', [$email])]); // Get user by email address
            if (!$invitee->isEmpty() && !empty($phone) && $invitee->getAttribute('phone', '') !== $phone) {
                throw new Exception(Exception::USER_ALREADY_EXISTS, 'Given email and phone doesn\'t match', 409);
            }
        } elseif (!empty($phone)) {
            $invitee = $dbForProject->findOne('users', [Query::equal('phone', [$phone])]);
            if (!$invitee->isEmpty() && !empty($email) && $invitee->getAttribute('email', '') !== $email) {
                throw new Exception(Exception::USER_ALREADY_EXISTS, 'Given phone and email doesn\'t match', 409);
            }
        }

        if ($invitee->isEmpty()) { // Create new user if no user with same email found
            $limit = $project->getAttribute('auths', [])['limit'] ?? 0;

            if (!$isPrivilegedUser && !$isAppUser && $limit !== 0 && $project->getId() !== 'console') { // check users limit, console invites are allways allowed.
                $total = $dbForProject->count('users', [], APP_LIMIT_USERS);

                if ($total >= $limit) {
                    throw new Exception(Exception::USER_COUNT_EXCEEDED, 'Project registration is restricted. Contact your administrator for more information.');
                }
            }

            // Makes sure this email is not already used in another identity
            $identityWithMatchingEmail = $dbForProject->findOne('identities', [
                Query::equal('providerEmail', [$email]),
            ]);
            if (!$identityWithMatchingEmail->isEmpty()) {
                throw new Exception(Exception::USER_EMAIL_ALREADY_EXISTS);
            }

            try {
                $userId = ID::unique();
                $hash = $proofForPassword->hash($proofForPassword->generate());
                $emailCanonical = new Email($email);
            } catch (Throwable) {
                $emailCanonical = null;
            }

            $userId = ID::unique();

            $userDocument = new Document([
                '$id' => $userId,
                '$permissions' => [
                    Permission::read(Role::any()),
                    Permission::read(Role::user($userId)),
                    Permission::update(Role::user($userId)),
                    Permission::delete(Role::user($userId)),
                ],
                'email' => empty($email) ? null : $email,
                'phone' => empty($phone) ? null : $phone,
                'emailVerification' => false,
                'status' => true,
                // TODO: Set password empty?
                'password' => $hash,
                'hash' => $proofForPassword->getHash()->getName(),
                'hashOptions' => $proofForPassword->getHash()->getOptions(),
                /**
                 * Set the password update time to 0 for users created using
                 * team invite and OAuth to allow password updates without an
                 * old password
                 */
                'passwordUpdate' => null,
                'registration' => DateTime::now(),
                'reset' => false,
                'name' => $name,
                'prefs' => new \stdClass(),
                'sessions' => null,
                'tokens' => null,
                'memberships' => null,
                'search' => implode(' ', [$userId, $email, $name]),
                'emailCanonical' => $emailCanonical?->getCanonical(),
                'emailIsCanonical' => $emailCanonical?->isCanonicalSupported(),
                'emailIsCorporate' => $emailCanonical?->isCorporate(),
                'emailIsDisposable' => $emailCanonical?->isDisposable(),
                'emailIsFree' => $emailCanonical?->isFree(),
            ]);

            try {
                $invitee = $authorization->skip(fn () => $dbForProject->createDocument('users', $userDocument));
            } catch (Duplicate $th) {
                throw new Exception(Exception::USER_ALREADY_EXISTS);
            }
        }

        $isOwner = $authorization->hasRole('team:' . $team->getId() . '/owner');

        if (!$isOwner && !$isPrivilegedUser && !$isAppUser) { // Not owner, not admin, not app (server)
            throw new Exception(Exception::USER_UNAUTHORIZED, 'User is not allowed to send invitations for this team');
        }

        $membership = $dbForProject->findOne('memberships', [
            Query::equal('userInternalId', [$invitee->getSequence()]),
            Query::equal('teamInternalId', [$team->getSequence()]),
        ]);

        $secret = $proofForToken->generate();
        if ($membership->isEmpty()) {
            $membershipId = ID::unique();
            $membership = new Document([
                '$id' => $membershipId,
                '$permissions' => [
                    Permission::read(Role::any()),
                    Permission::update(Role::user($invitee->getId())),
                    Permission::update(Role::team($team->getId(), 'owner')),
                    Permission::delete(Role::user($invitee->getId())),
                    Permission::delete(Role::team($team->getId(), 'owner')),
                ],
                'userId' => $invitee->getId(),
                'userInternalId' => $invitee->getSequence(),
                'teamId' => $team->getId(),
                'teamInternalId' => $team->getSequence(),
                'roles' => $roles,
                'invited' => DateTime::now(),
                'joined' => ($isPrivilegedUser || $isAppUser) ? DateTime::now() : null,
                'confirm' => ($isPrivilegedUser || $isAppUser),
                'secret' => $proofForToken->hash($secret),
                'search' => implode(' ', [$membershipId, $invitee->getId()])
            ]);

            $membership = ($isPrivilegedUser || $isAppUser) ?
                $authorization->skip(fn () => $dbForProject->createDocument('memberships', $membership)) :
                $dbForProject->createDocument('memberships', $membership);

            if ($isPrivilegedUser || $isAppUser) {
                $authorization->skip(fn () => $dbForProject->increaseDocumentAttribute('teams', $team->getId(), 'total', 1));
            }
        } elseif ($membership->getAttribute('confirm') === false) {
            $membership->setAttribute('secret', $proofForToken->hash($secret));
            $membership->setAttribute('invited', DateTime::now());

            if ($isPrivilegedUser || $isAppUser) {
                $membership->setAttribute('joined', DateTime::now());
                $membership->setAttribute('confirm', true);
            }

            $membership = ($isPrivilegedUser || $isAppUser) ?
                $authorization->skip(fn () => $dbForProject->updateDocument('memberships', $membership->getId(), $membership)) :
                $dbForProject->updateDocument('memberships', $membership->getId(), $membership);
        } else {
            throw new Exception(Exception::MEMBERSHIP_ALREADY_CONFIRMED);
        }

        if ($isPrivilegedUser || $isAppUser) {
            $dbForProject->purgeCachedDocument('users', $invitee->getId());
        } else {
            $url = Template::parseURL($url);
            $url['query'] = Template::mergeQuery(((isset($url['query'])) ? $url['query'] : ''), ['membershipId' => $membership->getId(), 'userId' => $invitee->getId(), 'secret' => $secret, 'teamId' => $teamId, 'teamName' => $team->getAttribute('name')]);
            $url = Template::unParseURL($url);
            if (!empty($email)) {
                $projectName = $project->isEmpty() ? 'Console' : $project->getAttribute('name', '[APP-NAME]');

                $body = $locale->getText("emails.invitation.body");
                $preview = $locale->getText("emails.invitation.preview");
                $subject = $locale->getText("emails.invitation.subject");
                $customTemplate = $project->getAttribute('templates', [])['email.invitation-' . $locale->default] ?? [];

                $message = Template::fromFile(APP_CE_CONFIG_DIR . '/locale/templates/email-inner-base.tpl');
                $message
                    ->setParam('{{body}}', $body, escapeHtml: false)
                    ->setParam('{{hello}}', $locale->getText("emails.invitation.hello"))
                    ->setParam('{{footer}}', $locale->getText("emails.invitation.footer"))
                    ->setParam('{{thanks}}', $locale->getText("emails.invitation.thanks"))
                    ->setParam('{{buttonText}}', $locale->getText("emails.invitation.buttonText"))
                    ->setParam('{{signature}}', $locale->getText("emails.invitation.signature"));
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
                    'owner' => $user->getAttribute('name'),
                    'direction' => $locale->getText('settings.direction'),
                    /* {{user}}, {{team}}, {{redirect}} and {{project}} are required in default and custom templates */
                    'user' => $name,
                    'team' => $team->getAttribute('name'),
                    'redirect' => $url,
                    'project' => $projectName
                ];

                $queueForMails
                    ->setSubject($subject)
                    ->setBody($body)
                    ->setPreview($preview)
                    ->setRecipient($invitee->getAttribute('email'))
                    ->setName($invitee->getAttribute('name', ''))
                    ->appendVariables($emailVariables)
                    ->trigger();
            } elseif (!empty($phone)) {
                if (empty(System::getEnv('_APP_SMS_PROVIDER'))) {
                    throw new Exception(Exception::GENERAL_PHONE_DISABLED, 'Phone provider not configured');
                }

                $message = Template::fromFile(APP_CE_CONFIG_DIR . '/locale/templates/sms-base.tpl');

                $customTemplate = $project->getAttribute('templates', [])['sms.invitation-' . $locale->default] ?? [];
                if (!empty($customTemplate)) {
                    $message = $customTemplate['message'];
                }

                $message = $message->setParam('{{token}}', $url);
                $message = $message->render();

                $messageDoc = new Document([
                    '$id' => ID::unique(),
                    'data' => [
                        'content' => $message,
                    ],
                ]);

                $queueForMessaging
                    ->setType(MESSAGE_SEND_TYPE_INTERNAL)
                    ->setMessage($messageDoc)
                    ->setRecipients([$phone])
                    ->setProviderType('SMS');

                $helper = PhoneNumberUtil::getInstance();
                try {
                    $countryCode = $helper->parse($phone)->getCountryCode();

                    if (!empty($countryCode)) {
                        $queueForStatsUsage
                            ->addMetric(str_replace('{countryCode}', $countryCode, METRIC_AUTH_METHOD_PHONE_COUNTRY_CODE), 1);
                    }
                } catch (NumberParseException $e) {
                    // Ignore invalid phone number for country code stats
                }
                $queueForStatsUsage
                    ->addMetric(METRIC_AUTH_METHOD_PHONE, 1)
                    ->setProject($project)
                    ->trigger();
            }
        }

        $queueForEvents
            ->setParam('userId', $invitee->getId())
            ->setParam('teamId', $team->getId())
            ->setParam('membershipId', $membership->getId())
        ;

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic(
                $membership
                    ->setAttribute('teamName', $team->getAttribute('name'))
                    ->setAttribute('userName', $invitee->getAttribute('name'))
                    ->setAttribute('userEmail', $invitee->getAttribute('email')),
                Response::MODEL_MEMBERSHIP
            );
    }
}

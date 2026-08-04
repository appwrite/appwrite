<?php

namespace Appwrite\Platform\Modules\Users;

use Appwrite\Auth\Validator\PersonalData;
use Appwrite\Extend\Exception;
use Appwrite\Hooks\Hooks;
use Appwrite\Platform\Action;
use Utopia\Auth\Hash;
use Utopia\Auth\Hashes\Plaintext;
use Utopia\Auth\Proofs\Password as ProofsPassword;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;
use Utopia\Emails\Email;

class Base extends Action
{
    protected function createUser(Hash $hash, string $userId, ?string $email, ?string $password, ?string $phone, ?string $name, Document $project, Database $dbForProject, Hooks $hooks, array $plan): Document
    {
        $name = $name ?? '';
        $plaintextPassword = $password;
        $passwordHistory = $project->getAttribute('auths', [])['passwordHistory'] ?? 0;

        if (!empty($email)) {
            $email = \strtolower($email);

            // Makes sure this email is not already used in another identity
            $identityWithMatchingEmail = $dbForProject->findOne('identities', [
                Query::equal('providerEmail', [$email]),
            ]);
            if (!$identityWithMatchingEmail->isEmpty()) {
                throw new Exception(Exception::USER_EMAIL_ALREADY_EXISTS);
            }
        }

        try {
            $userId = $userId == 'unique()'
                ? ID::unique()
                : ID::custom($userId);

            if ($project->getAttribute('auths', [])['personalDataCheck'] ?? false) {
                $personalDataValidator = new PersonalData(
                    $userId,
                    $email,
                    $name,
                    $phone,
                    strict: false,
                    allowEmpty: true
                );
                if (!$personalDataValidator->isValid($plaintextPassword)) {
                    throw new Exception(Exception::USER_PASSWORD_PERSONAL_DATA);
                }
            }

            $emailMetadata = [
                'emailCanonical' => null,
                'emailIsCanonical' => null,
                'emailIsCorporate' => null,
                'emailIsDisposable' => null,
                'emailIsFree' => null,
            ];

            try {
                $parsedEmail = new Email($email ?? '');
                $canonical = $parsedEmail->getCanonical();
                $emailMetadata = [
                    'emailCanonical' => $canonical,
                    'emailIsCanonical' => $parsedEmail->get() === $canonical,
                    'emailIsCorporate' => $parsedEmail->isCorporate(),
                    'emailIsDisposable' => $parsedEmail->isDisposable(),
                    'emailIsFree' => $parsedEmail->isFree(),
                ];
            } catch (\Throwable) {
            }

            if ((($project->getId() === 'console') || ($plan['supportsDisposableEmailValidation'] ?? false)) && ($project->getAttribute('auths', [])['disposableEmails'] ?? false) && ($emailMetadata['emailIsDisposable'] ?? false)) {
                throw new Exception(Exception::USER_EMAIL_DISPOSABLE);
            }

            if ((($project->getId() === 'console') || ($plan['supportsCanonicalEmailValidation'] ?? false)) && ($project->getAttribute('auths', [])['canonicalEmails'] ?? false) && ($emailMetadata['emailIsCanonical'] ?? true) === false) {
                throw new Exception(Exception::USER_EMAIL_NOT_CANONICAL);
            }

            if ((($project->getId() === 'console') || ($plan['supportsFreeEmailValidation'] ?? false)) && ($project->getAttribute('auths', [])['freeEmails'] ?? false) && ($emailMetadata['emailIsFree'] ?? false)) {
                throw new Exception(Exception::USER_EMAIL_FREE);
            }

            if ((($project->getId() === 'console') || ($plan['supportsCorporateEmailValidation'] ?? false)) && ($project->getAttribute('auths', [])['corporateEmails'] ?? false) && !($emailMetadata['emailIsCorporate'] ?? true)) {
                throw new Exception(Exception::USER_EMAIL_NOT_CORPORATE);
            }

            $hashedPassword = null;

            $isHashed = !$hash instanceof Plaintext;

            $defaultHash = new ProofsPassword();
            if (!empty($password)) {
                if (!$isHashed) { // Password was never hashed, hash it with the default hash
                    $hashedPassword = $defaultHash->hash($password);
                    $hash = $defaultHash->getHash();
                } else {
                    $hashedPassword = $password;
                }
            } else {
                // when password is not provided, plaintext was set as the default hash causing the issue
                $hash = $defaultHash->getHash();
                $isHashed = !$hash instanceof Plaintext;
            }

            $user = new Document([
                '$id' => $userId,
                '$permissions' => [
                    Permission::read(Role::any()),
                    Permission::update(Role::user($userId)),
                    Permission::delete(Role::user($userId)),
                ],
                'email' => $email,
                'emailVerification' => false,
                'phone' => $phone,
                'phoneVerification' => false,
                'status' => true,
                'labels' => [],
                'password' => $hashedPassword,
                'passwordHistory' => is_null($hashedPassword) || $passwordHistory === 0 ? [] : [$hashedPassword],
                'passwordUpdate' => (!empty($hashedPassword)) ? DateTime::now() : null,
                'hash' => $hash->getName(),
                'hashOptions' => $hash->getOptions(),
                'registration' => DateTime::now(),
                'reset' => false,
                'name' => $name,
                'prefs' => new \stdClass(),
                'sessions' => null,
                'tokens' => null,
                'memberships' => null,
                'search' => implode(' ', [$userId, $email, $phone, $name]),
                'emailCanonical' => $emailMetadata['emailCanonical'],
                'emailIsCanonical' => $emailMetadata['emailIsCanonical'],
                'emailIsCorporate' => $emailMetadata['emailIsCorporate'],
                'emailIsDisposable' => $emailMetadata['emailIsDisposable'],
                'emailIsFree' => $emailMetadata['emailIsFree'],
            ]);

            if (!$isHashed && !empty($password)) {
                $hooks->trigger('passwordValidator', [$dbForProject, $project, $plaintextPassword, &$user, true]);
            }

            $user = $dbForProject->createDocument('users', $user);

            if ($email) {
                try {
                    $target = $dbForProject->createDocument('targets', new Document([
                        '$permissions' => [
                            Permission::read(Role::user($user->getId())),
                            Permission::update(Role::user($user->getId())),
                            Permission::delete(Role::user($user->getId())),
                        ],
                        'userId' => $user->getId(),
                        'userInternalId' => $user->getSequence(),
                        'providerType' => 'email',
                        'identifier' => $email,
                    ]));
                    $user->setAttribute('targets', [...$user->getAttribute('targets', []), $target]);
                } catch (Duplicate) {
                    $existingTarget = $dbForProject->findOne('targets', [
                        Query::equal('identifier', [$email]),
                    ]);
                    if (!$existingTarget->isEmpty()) {
                        $user->setAttribute('targets', $existingTarget, Document::SET_TYPE_APPEND);
                    }
                }
            }

            if ($phone) {
                try {
                    $target = $dbForProject->createDocument('targets', new Document([
                        '$permissions' => [
                            Permission::read(Role::user($user->getId())),
                            Permission::update(Role::user($user->getId())),
                            Permission::delete(Role::user($user->getId())),
                        ],
                        'userId' => $user->getId(),
                        'userInternalId' => $user->getSequence(),
                        'providerType' => 'sms',
                        'identifier' => $phone,
                    ]));
                    $user->setAttribute('targets', [...$user->getAttribute('targets', []), $target]);
                } catch (Duplicate) {
                    $existingTarget = $dbForProject->findOne('targets', [
                        Query::equal('identifier', [$phone]),
                    ]);
                    if (!$existingTarget->isEmpty()) {
                        $user->setAttribute('targets', $existingTarget, Document::SET_TYPE_APPEND);
                    }
                }
            }

            $dbForProject->purgeCachedDocument('users', $user->getId());
        } catch (Duplicate $th) {
            throw new Exception(Exception::USER_ALREADY_EXISTS);
        }

        return $user;
    }
}

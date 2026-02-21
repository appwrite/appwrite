<?php

use Ahc\Jwt\JWT;
use Appwrite\Auth\MFA\Type;
use Appwrite\Auth\MFA\Type\TOTP;
use Appwrite\Auth\Validator\Password;
use Appwrite\Auth\Validator\PasswordDictionary;
use Appwrite\Auth\Validator\PasswordHistory;
use Appwrite\Auth\Validator\PersonalData;
use Appwrite\Auth\Validator\Phone;
use Appwrite\Deletes\Identities as DeleteIdentities;
use Appwrite\Deletes\Targets as DeleteTargets;
use Appwrite\Detector\Detector;
use Appwrite\Event\Delete;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Hooks\Hooks;
use Appwrite\Network\Validator\Email as EmailValidator;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Deprecated;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Validator\CustomId;
use Appwrite\Utopia\Database\Validator\Queries\Identities;
use Appwrite\Utopia\Database\Validator\Queries\Memberships;
use Appwrite\Utopia\Database\Validator\Queries\Targets;
use Appwrite\Utopia\Database\Validator\Queries\Users;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use MaxMind\Db\Reader;
use Utopia\App;
use Utopia\Audit\Audit;
use Utopia\Auth\Hash;
use Utopia\Auth\Hashes\Argon2;
use Utopia\Auth\Hashes\Bcrypt;
use Utopia\Auth\Hashes\MD5;
use Utopia\Auth\Hashes\PHPass;
use Utopia\Auth\Hashes\Plaintext;
use Utopia\Auth\Hashes\Scrypt;
use Utopia\Auth\Hashes\ScryptModified;
use Utopia\Auth\Hashes\Sha;
use Utopia\Auth\Proofs\Password as ProofsPassword;
use Utopia\Auth\Proofs\Token;
use Utopia\Auth\Store;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Exception\Order as OrderException;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Queries;
use Utopia\Database\Validator\Query\Cursor;
use Utopia\Database\Validator\Query\Limit;
use Utopia\Database\Validator\Query\Offset;
use Utopia\Database\Validator\UID;
use Utopia\Emails\Email;
use Utopia\Locale\Locale;
use Utopia\System\System;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Assoc;
use Utopia\Validator\Boolean;
use Utopia\Validator\Integer;
use Utopia\Validator\Nullable;
use Utopia\Validator\Range;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

/** TODO: Remove function when we move to using utopia/platform */
function createUser(Hash $hash, string $userId, ?string $email, ?string $password, ?string $phone, string $name, Document $project, Database $dbForProject, Hooks $hooks): Document
{
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

        try {
            $emailCanonical = new Email($email);
        } catch (Throwable) {
            $emailCanonical = null;
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
            'emailCanonical' => $emailCanonical?->getCanonical(),
            'emailIsCanonical' => $emailCanonical?->isCanonicalSupported(),
            'emailIsCorporate' => $emailCanonical?->isCorporate(),
            'emailIsDisposable' => $emailCanonical?->isDisposable(),
            'emailIsFree' => $emailCanonical?->isFree(),
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

App::post('/v1/users')
    ->desc('Create user')
    ->groups(['api', 'users'])
    ->label('scope', 'users.write')
    ->label('audits.event', 'user.create')
    ->label('audits.resource', 'user/{response.$id}')
    ->label('sdk', new Method(
        namespace: 'users',
        group: 'users',
        name: 'create',
        description: '/docs/references/users/create-user.md',
        auth: [AuthType::ADMIN, AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_CREATED,
                model: Response::MODEL_USER,
            )
        ]
    ))
    ->param('userId', '', new CustomId(), 'User ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('email', null, new Nullable(new EmailValidator()), 'User email.', true)
    ->param('phone', null, new Nullable(new Phone()), 'Phone number. Format this number with a leading \'+\' and a country code, e.g., +16175551212.', true)
    ->param('password', '', fn ($project, $passwordsDictionary) => new PasswordDictionary($passwordsDictionary, $project->getAttribute('auths', [])['passwordDictionary'] ?? false), 'Plain text user password. Must be at least 8 chars.', true, ['project', 'passwordsDictionary'])
    ->param('name', '', new Text(128), 'User name. Max length: 128 chars.', true)
    ->inject('response')
    ->inject('project')
    ->inject('dbForProject')
    ->inject('hooks')
    ->action(function (string $userId, ?string $email, ?string $phone, ?string $password, string $name, Response $response, Document $project, Database $dbForProject, Hooks $hooks) {
        $plaintext = new Plaintext();

        $user = createUser($plaintext, $userId, $email, $password, $phone, $name, $project, $dbForProject, $hooks);
        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($user, Response::MODEL_USER);
    });

App::post('/v1/users/bcrypt')
    ->desc('Create user with bcrypt password')
    ->groups(['api', 'users'])
    ->label('scope', 'users.write')
    ->label('audits.event', 'user.create')
    ->label('audits.resource', 'user/{response.$id}')
    ->label('sdk', new Method(
        namespace: 'users',
        group: 'users',
        name: 'createBcryptUser',
        description: '/docs/references/users/create-bcrypt-user.md',
        auth: [AuthType::ADMIN, AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_CREATED,
                model: Response::MODEL_USER,
            )
        ]
    ))
    ->param('userId', '', new CustomId(), 'User ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('email', '', new EmailValidator(), 'User email.')
    ->param('password', '', new Password(), 'User password hashed using Bcrypt.')
    ->param('name', '', new Text(128), 'User name. Max length: 128 chars.', true)
    ->inject('response')
    ->inject('project')
    ->inject('dbForProject')
    ->inject('hooks')
    ->action(function (string $userId, string $email, string $password, string $name, Response $response, Document $project, Database $dbForProject, Hooks $hooks) {
        $bcrypt = new Bcrypt();
        $bcrypt->setCost(8); // Default cost

        $user = createUser($bcrypt, $userId, $email, $password, null, $name, $project, $dbForProject, $hooks);

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($user, Response::MODEL_USER);
    });

App::post('/v1/users/md5')
    ->desc('Create user with MD5 password')
    ->groups(['api', 'users'])
    ->label('scope', 'users.write')
    ->label('audits.event', 'user.create')
    ->label('audits.resource', 'user/{response.$id}')
    ->label('sdk', new Method(
        namespace: 'users',
        group: 'users',
        name: 'createMD5User',
        description: '/docs/references/users/create-md5-user.md',
        auth: [AuthType::ADMIN, AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_CREATED,
                model: Response::MODEL_USER,
            )
        ]
    ))
    ->param('userId', '', new CustomId(), 'User ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('email', '', new EmailValidator(), 'User email.')
    ->param('password', '', new Password(), 'User password hashed using MD5.')
    ->param('name', '', new Text(128), 'User name. Max length: 128 chars.', true)
    ->inject('response')
    ->inject('project')
    ->inject('dbForProject')
    ->inject('hooks')
    ->action(function (string $userId, string $email, string $password, string $name, Response $response, Document $project, Database $dbForProject, Hooks $hooks) {
        $md5 = new MD5();

        $user = createUser($md5, $userId, $email, $password, null, $name, $project, $dbForProject, $hooks);

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($user, Response::MODEL_USER);
    });

App::post('/v1/users/argon2')
    ->desc('Create user with Argon2 password')
    ->groups(['api', 'users'])
    ->label('scope', 'users.write')
    ->label('audits.event', 'user.create')
    ->label('audits.resource', 'user/{response.$id}')
    ->label('sdk', new Method(
        namespace: 'users',
        group: 'users',
        name: 'createArgon2User',
        description: '/docs/references/users/create-argon2-user.md',
        auth: [AuthType::ADMIN, AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_CREATED,
                model: Response::MODEL_USER,
            )
        ]
    ))
    ->param('userId', '', new CustomId(), 'User ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('email', '', new EmailValidator(), 'User email.')
    ->param('password', '', new Password(), 'User password hashed using Argon2.')
    ->param('name', '', new Text(128), 'User name. Max length: 128 chars.', true)
    ->inject('response')
    ->inject('project')
    ->inject('dbForProject')
    ->inject('hooks')
    ->action(function (string $userId, string $email, string $password, string $name, Response $response, Document $project, Database $dbForProject, Hooks $hooks) {
        $argon2 = new Argon2();

        $user = createUser($argon2, $userId, $email, $password, null, $name, $project, $dbForProject, $hooks);

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($user, Response::MODEL_USER);
    });

App::post('/v1/users/sha')
    ->desc('Create user with SHA password')
    ->groups(['api', 'users'])
    ->label('scope', 'users.write')
    ->label('audits.event', 'user.create')
    ->label('audits.resource', 'user/{response.$id}')
    ->label('sdk', new Method(
        namespace: 'users',
        group: 'users',
        name: 'createSHAUser',
        description: '/docs/references/users/create-sha-user.md',
        auth: [AuthType::ADMIN, AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_CREATED,
                model: Response::MODEL_USER,
            )
        ]
    ))
    ->param('userId', '', new CustomId(), 'User ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('email', '', new EmailValidator(), 'User email.')
    ->param('password', '', new Password(), 'User password hashed using SHA.')
    ->param('passwordVersion', '', new WhiteList(['sha1', 'sha224', 'sha256', 'sha384', 'sha512/224', 'sha512/256', 'sha512', 'sha3-224', 'sha3-256', 'sha3-384', 'sha3-512']), "Optional SHA version used to hash password. Allowed values are: 'sha1', 'sha224', 'sha256', 'sha384', 'sha512/224', 'sha512/256', 'sha512', 'sha3-224', 'sha3-256', 'sha3-384', 'sha3-512'", true)
    ->param('name', '', new Text(128), 'User name. Max length: 128 chars.', true)
    ->inject('response')
    ->inject('project')
    ->inject('dbForProject')
    ->inject('hooks')
    ->action(function (string $userId, string $email, string $password, string $passwordVersion, string $name, Response $response, Document $project, Database $dbForProject, Hooks $hooks) {
        $sha = new Sha();
        if (!empty($passwordVersion)) {
            $sha->setVersion($passwordVersion);
        }

        $user = createUser($sha, $userId, $email, $password, null, $name, $project, $dbForProject, $hooks);

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($user, Response::MODEL_USER);
    });

App::post('/v1/users/phpass')
    ->desc('Create user with PHPass password')
    ->groups(['api', 'users'])
    ->label('scope', 'users.write')
    ->label('audits.event', 'user.create')
    ->label('audits.resource', 'user/{response.$id}')
    ->label('sdk', new Method(
        namespace: 'users',
        group: 'users',
        name: 'createPHPassUser',
        description: '/docs/references/users/create-phpass-user.md',
        auth: [AuthType::ADMIN, AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_CREATED,
                model: Response::MODEL_USER,
            )
        ]
    ))
    ->param('userId', '', new CustomId(), 'User ID. Choose a custom ID or pass the string `ID.unique()`to auto generate it. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('email', '', new EmailValidator(), 'User email.')
    ->param('password', '', new Password(), 'User password hashed using PHPass.')
    ->param('name', '', new Text(128), 'User name. Max length: 128 chars.', true)
    ->inject('response')
    ->inject('project')
    ->inject('dbForProject')
    ->inject('hooks')
    ->action(function (string $userId, string $email, string $password, string $name, Response $response, Document $project, Database $dbForProject, Hooks $hooks) {
        $phpass = new PHPass();

        $user = createUser($phpass, $userId, $email, $password, null, $name, $project, $dbForProject, $hooks);

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($user, Response::MODEL_USER);
    });

App::post('/v1/users/scrypt')
    ->desc('Create user with Scrypt password')
    ->groups(['api', 'users'])
    ->label('scope', 'users.write')
    ->label('audits.event', 'user.create')
    ->label('audits.resource', 'user/{response.$id}')
    ->label('sdk', new Method(
        namespace: 'users',
        group: 'users',
        name: 'createScryptUser',
        description: '/docs/references/users/create-scrypt-user.md',
        auth: [AuthType::ADMIN, AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_CREATED,
                model: Response::MODEL_USER,
            )
        ]
    ))
    ->param('userId', '', new CustomId(), 'User ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('email', '', new EmailValidator(), 'User email.')
    ->param('password', '', new Password(), 'User password hashed using Scrypt.')
    ->param('passwordSalt', '', new Text(128), 'Optional salt used to hash password.')
    ->param('passwordCpu', 8, new Integer(), 'Optional CPU cost used to hash password.')
    ->param('passwordMemory', 14, new Integer(), 'Optional memory cost used to hash password.')
    ->param('passwordParallel', 1, new Integer(), 'Optional parallelization cost used to hash password.')
    ->param('passwordLength', 64, new Integer(), 'Optional hash length used to hash password.')
    ->param('name', '', new Text(128), 'User name. Max length: 128 chars.', true)
    ->inject('response')
    ->inject('project')
    ->inject('dbForProject')
    ->inject('hooks')
    ->action(function (string $userId, string $email, string $password, string $passwordSalt, int $passwordCpu, int $passwordMemory, int $passwordParallel, int $passwordLength, string $name, Response $response, Document $project, Database $dbForProject, Hooks $hooks) {
        $scrypt = new Scrypt();
        $scrypt
            ->setSalt($passwordSalt)
            ->setCpuCost($passwordCpu)
            ->setMemoryCost($passwordMemory)
            ->setParallelCost($passwordParallel)
            ->setLength($passwordLength);

        $user = createUser($scrypt, $userId, $email, $password, null, $name, $project, $dbForProject, $hooks);

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($user, Response::MODEL_USER);
    });

App::post('/v1/users/scrypt-modified')
    ->desc('Create user with Scrypt modified password')
    ->groups(['api', 'users'])
    ->label('scope', 'users.write')
    ->label('audits.event', 'user.create')
    ->label('audits.resource', 'user/{response.$id}')
    ->label('sdk', new Method(
        namespace: 'users',
        group: 'users',
        name: 'createScryptModifiedUser',
        description: '/docs/references/users/create-scrypt-modified-user.md',
        auth: [AuthType::ADMIN, AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_CREATED,
                model: Response::MODEL_USER,
            )
        ]
    ))
    ->param('userId', '', new CustomId(), 'User ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('email', '', new EmailValidator(), 'User email.')
    ->param('password', '', new Password(), 'User password hashed using Scrypt Modified.')
    ->param('passwordSalt', '', new Text(128), 'Salt used to hash password.')
    ->param('passwordSaltSeparator', '', new Text(128), 'Salt separator used to hash password.')
    ->param('passwordSignerKey', '', new Text(128), 'Signer key used to hash password.')
    ->param('name', '', new Text(128), 'User name. Max length: 128 chars.', true)
    ->inject('response')
    ->inject('project')
    ->inject('dbForProject')
    ->inject('hooks')
    ->action(function (string $userId, string $email, string $password, string $passwordSalt, string $passwordSaltSeparator, string $passwordSignerKey, string $name, Response $response, Document $project, Database $dbForProject, Hooks $hooks) {
        $scryptModified = new ScryptModified();
        $scryptModified
            ->setSalt($passwordSalt)
            ->setSaltSeparator($passwordSaltSeparator)
            ->setSignerKey($passwordSignerKey);

        $user = createUser($scryptModified, $userId, $email, $password, null, $name, $project, $dbForProject, $hooks);

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($user, Response::MODEL_USER);
    });

App::post('/v1/users/:userId/targets')
    ->desc('Create user target')
    ->groups(['api', 'users'])
    ->label('audits.event', 'target.create')
    ->label('audits.resource', 'target/response.$id')
    ->label('event', 'users.[userId].targets.[targetId].create')
    ->label('scope', 'targets.write')
    ->label('sdk', new Method(
        namespace: 'users',
        group: 'targets',
        name: 'createTarget',
        description: '/docs/references/users/create-target.md',
        auth: [AuthType::KEY, AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_CREATED,
                model: Response::MODEL_TARGET,
            )
        ]
    ))
    ->param('targetId', '', new CustomId(), 'Target ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('userId', '', new UID(), 'User ID.')
    ->param('providerType', '', new WhiteList([MESSAGE_TYPE_EMAIL, MESSAGE_TYPE_SMS, MESSAGE_TYPE_PUSH]), 'The target provider type. Can be one of the following: `email`, `sms` or `push`.')
    ->param('identifier', '', new Text(Database::LENGTH_KEY), 'The target identifier (token, email, phone etc.)')
    ->param('providerId', '', new UID(), 'Provider ID. Message will be sent to this target from the specified provider ID. If no provider ID is set the first setup provider will be used.', true)
    ->param('name', '', new Text(128), 'Target name. Max length: 128 chars. For example: My Awesome App Galaxy S23.', true)
    ->inject('queueForEvents')
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (string $targetId, string $userId, string $providerType, string $identifier, string $providerId, string $name, Event $queueForEvents, Response $response, Database $dbForProject) {
        $targetId = $targetId == 'unique()' ? ID::unique() : $targetId;

        $provider = $dbForProject->getDocument('providers', $providerId);

        switch ($providerType) {
            case 'email':
                $validator = new EmailValidator();
                if (!$validator->isValid($identifier)) {
                    throw new Exception(Exception::GENERAL_INVALID_EMAIL);
                }
                break;
            case MESSAGE_TYPE_SMS:
                $validator = new Phone();
                if (!$validator->isValid($identifier)) {
                    throw new Exception(Exception::GENERAL_INVALID_PHONE);
                }
                break;
            case MESSAGE_TYPE_PUSH:
                break;
            default:
                throw new Exception(Exception::PROVIDER_INCORRECT_TYPE);
        }

        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        $target = $dbForProject->getDocument('targets', $targetId);

        if (!$target->isEmpty()) {
            throw new Exception(Exception::USER_TARGET_ALREADY_EXISTS);
        }

        try {
            $target = $dbForProject->createDocument('targets', new Document([
                '$id' => $targetId,
                '$permissions' => [
                    Permission::read(Role::user($user->getId())),
                    Permission::update(Role::user($user->getId())),
                    Permission::delete(Role::user($user->getId())),
                ],
                'providerId' => empty($provider->getId()) ? null : $provider->getId(),
                'providerInternalId' => $provider->isEmpty() ? null : $provider->getSequence(),
                'providerType' =>  $providerType,
                'userId' => $userId,
                'userInternalId' => $user->getSequence(),
                'identifier' => $identifier,
                'name' => ($name !== '') ? $name : null,
            ]));
        } catch (Duplicate) {
            throw new Exception(Exception::USER_TARGET_ALREADY_EXISTS);
        }
        $dbForProject->purgeCachedDocument('users', $user->getId());

        $queueForEvents
            ->setParam('userId', $user->getId())
            ->setParam('targetId', $target->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($target, Response::MODEL_TARGET);
    });

App::get('/v1/users')
    ->desc('List users')
    ->groups(['api', 'users'])
    ->label('scope', 'users.read')
    ->label('sdk', new Method(
        namespace: 'users',
        group: 'users',
        name: 'list',
        description: '/docs/references/users/list-users.md',
        auth: [AuthType::ADMIN, AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_USER_LIST,
            )
        ]
    ))
    ->param('queries', [], new Users(), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long. You may filter on the following attributes: ' . implode(', ', Users::ALLOWED_ATTRIBUTES), true)
    ->param('search', '', new Text(256), 'Search term to filter your list results. Max length: 256 chars.', true)
    ->param('total', true, new Boolean(true), 'When set to false, the total count returned will be 0 and will not be calculated.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (array $queries, string $search, bool $includeTotal, Response $response, Database $dbForProject) {

        try {
            $queries = Query::parseQueries($queries);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        if (!empty($search)) {
            $queries[] = Query::search('search', $search);
        }

        /**
         * Get cursor document if there was a cursor query, we use array_filter and reset for reference $cursor to $queries
         */
        $cursor = \array_filter($queries, function ($query) {
            return \in_array($query->getMethod(), [Query::TYPE_CURSOR_AFTER, Query::TYPE_CURSOR_BEFORE]);
        });
        $cursor = reset($cursor);
        if ($cursor) {
            /** @var Query $cursor */

            $validator = new Cursor();
            if (!$validator->isValid($cursor)) {
                throw new Exception(Exception::GENERAL_QUERY_INVALID, $validator->getDescription());
            }

            $userId = $cursor->getValue();
            $cursorDocument = $dbForProject->getDocument('users', $userId);

            if ($cursorDocument->isEmpty()) {
                throw new Exception(Exception::GENERAL_CURSOR_NOT_FOUND, "User '{$userId}' for the 'cursor' value not found.");
            }

            $cursor->setValue($cursorDocument);
        }

        $users = [];
        $total = 0;

        $dbForProject->skipFilters(function () use ($dbForProject, $queries, $includeTotal, &$users, &$total) {
            try {
                $users = $dbForProject->find('users', $queries);
                $total = $includeTotal ? $dbForProject->count('users', $queries, APP_LIMIT_COUNT) : 0;
            } catch (OrderException $e) {
                throw new Exception(Exception::DATABASE_QUERY_ORDER_NULL, "The order attribute '{$e->getAttribute()}' had a null value. Cursor pagination requires all documents order attribute values are non-null.");
            } catch (QueryException $e) {
                throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
            }
        }, ['subQueryAuthenticators', 'subQuerySessions', 'subQueryTokens', 'subQueryChallenges', 'subQueryMemberships']);

        $response->dynamic(new Document([
            'users' => $users,
            'total' => $total,
        ]), Response::MODEL_USER_LIST);
    });

App::get('/v1/users/:userId')
    ->desc('Get user')
    ->groups(['api', 'users'])
    ->label('scope', 'users.read')
    ->label('sdk', new Method(
        namespace: 'users',
        group: 'users',
        name: 'get',
        description: '/docs/references/users/get-user.md',
        auth: [AuthType::ADMIN, AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_USER,
            )
        ]
    ))
    ->param('userId', '', new UID(), 'User ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (string $userId, Response $response, Database $dbForProject) {

        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        $response->dynamic($user, Response::MODEL_USER);
    });

App::get('/v1/users/:userId/prefs')
    ->desc('Get user preferences')
    ->groups(['api', 'users'])
    ->label('scope', 'users.read')
    ->label('sdk', new Method(
        namespace: 'users',
        group: 'users',
        name: 'getPrefs',
        description: '/docs/references/users/get-user-prefs.md',
        auth: [AuthType::ADMIN, AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_PREFERENCES,
            )
        ]
    ))
    ->param('userId', '', new UID(), 'User ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (string $userId, Response $response, Database $dbForProject) {

        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        $prefs = $user->getAttribute('prefs', []);

        $response->dynamic(new Document($prefs), Response::MODEL_PREFERENCES);
    });

App::get('/v1/users/:userId/targets/:targetId')
    ->desc('Get user target')
    ->groups(['api', 'users'])
    ->label('scope', 'targets.read')
    ->label('sdk', new Method(
        namespace: 'users',
        group: 'targets',
        name: 'getTarget',
        description: '/docs/references/users/get-user-target.md',
        auth: [AuthType::KEY, AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_TARGET,
            )
        ]
    ))
    ->param('userId', '', new UID(), 'User ID.')
    ->param('targetId', '', new UID(), 'Target ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (string $userId, string $targetId, Response $response, Database $dbForProject) {

        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        $target = $user->find('$id', $targetId, 'targets');

        if (empty($target)) {
            throw new Exception(Exception::USER_TARGET_NOT_FOUND);
        }

        $response->dynamic($target, Response::MODEL_TARGET);
    });

App::get('/v1/users/:userId/sessions')
    ->desc('List user sessions')
    ->groups(['api', 'users'])
    ->label('scope', 'users.read')
    ->label('sdk', new Method(
        namespace: 'users',
        group: 'sessions',
        name: 'listSessions',
        description: '/docs/references/users/list-user-sessions.md',
        auth: [AuthType::ADMIN, AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_SESSION_LIST,
            )
        ]
    ))
    ->param('userId', '', new UID(), 'User ID.')
    ->param('total', true, new Boolean(true), 'When set to false, the total count returned will be 0 and will not be calculated.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('locale')
    ->action(function (string $userId, bool $includeTotal, Response $response, Database $dbForProject, Locale $locale) {

        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }
        $sessions = $user->getAttribute('sessions', []);
        foreach ($sessions as $key => $session) {
            /** @var Document $session */
            $countryName = $locale->getText('countries.' . strtolower($session->getAttribute('countryCode')), $locale->getText('locale.country.unknown'));
            $session->setAttribute('countryName', $countryName);
            $session->setAttribute('current', false);
            $sessions[$key] = $session;
        }

        $response->dynamic(new Document([
            'sessions' => $sessions,
            'total' => $includeTotal ? count($sessions) : 0,
        ]), Response::MODEL_SESSION_LIST);
    });

App::get('/v1/users/:userId/memberships')
    ->desc('List user memberships')
    ->groups(['api', 'users'])
    ->label('scope', 'users.read')
    ->label('sdk', new Method(
        namespace: 'users',
        group: 'memberships',
        name: 'listMemberships',
        description: '/docs/references/users/list-user-memberships.md',
        auth: [AuthType::ADMIN, AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_MEMBERSHIP_LIST,
            )
        ]
    ))
    ->param('userId', '', new UID(), 'User ID.')
    ->param('queries', [], new Memberships(), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long. You may filter on the following attributes: ' . implode(', ', Memberships::ALLOWED_ATTRIBUTES), true)
    ->param('search', '', new Text(256), 'Search term to filter your list results. Max length: 256 chars.', true)
    ->param('total', true, new Boolean(true), 'When set to false, the total count returned will be 0 and will not be calculated.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (string $userId, array $queries, string $search, bool $includeTotal, Response $response, Database $dbForProject) {

        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }
        try {
            $queries = Query::parseQueries($queries);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }
        if (!empty($search)) {
            $queries[] = Query::search('search', $search);
        }
        // Set internal queries
        $queries[] = Query::equal('userInternalId', [$user->getSequence()]);
        $memberships = array_map(function ($membership) use ($dbForProject, $user) {
            $team = $dbForProject->getDocument('teams', $membership->getAttribute('teamId'));
            $membership
                ->setAttribute('teamName', $team->getAttribute('name'))
                ->setAttribute('userName', $user->getAttribute('name'))
                ->setAttribute('userEmail', $user->getAttribute('email'));
            return $membership;
        }, $dbForProject->find('memberships', $queries));

        $response->dynamic(new Document([
            'memberships' => $memberships,
            'total' => $includeTotal ? count($memberships) : 0,
        ]), Response::MODEL_MEMBERSHIP_LIST);
    });

App::get('/v1/users/:userId/logs')
    ->desc('List user logs')
    ->groups(['api', 'users'])
    ->label('scope', 'users.read')
    ->label('sdk', new Method(
        namespace: 'users',
        group: 'logs',
        name: 'listLogs',
        description: '/docs/references/users/list-user-logs.md',
        auth: [AuthType::ADMIN, AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_LOG_LIST,
            )
        ]
    ))
    ->param('userId', '', new UID(), 'User ID.')
    ->param('queries', [], new Queries([new Limit(), new Offset()]), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Only supported methods are limit and offset', true)
    ->param('total', true, new Boolean(true), 'When set to false, the total count returned will be 0 and will not be calculated.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('locale')
    ->inject('geodb')
    ->action(function (string $userId, array $queries, bool $includeTotal, Response $response, Database $dbForProject, Locale $locale, Reader $geodb) {

        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }
        try {
            $queries = Query::parseQueries($queries);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        $audit = new Audit($dbForProject);
        $logs = $audit->getLogsByUser($user->getSequence(), $queries);
        $output = [];
        foreach ($logs as $i => &$log) {
            $log['userAgent'] = (!empty($log['userAgent'])) ? $log['userAgent'] : 'UNKNOWN';
            $detector = new Detector($log['userAgent']);
            $detector->skipBotDetection(); // OPTIONAL: If called, bot detection will completely be skipped (bots will be detected as regular devices then)
            $os = $detector->getOS();
            $client = $detector->getClient();
            $device = $detector->getDevice();
            $output[$i] = new Document([
                'event' => $log['event'],
                'userId' => ID::custom($log['data']['userId']),
                'userEmail' => $log['data']['userEmail'] ?? null,
                'userName' => $log['data']['userName'] ?? null,
                'ip' => $log['ip'],
                'time' => $log['time'],
                'osCode' => $os['osCode'],
                'osName' => $os['osName'],
                'osVersion' => $os['osVersion'],
                'clientType' => $client['clientType'],
                'clientCode' => $client['clientCode'],
                'clientName' => $client['clientName'],
                'clientVersion' => $client['clientVersion'],
                'clientEngine' => $client['clientEngine'],
                'clientEngineVersion' => $client['clientEngineVersion'],
                'deviceName' => $device['deviceName'],
                'deviceBrand' => $device['deviceBrand'],
                'deviceModel' => $device['deviceModel']
            ]);
            $record = $geodb->get($log['ip']);
            if ($record) {
                $output[$i]['countryCode'] = $locale->getText('countries.' . strtolower($record['country']['iso_code']), false) ? \strtolower($record['country']['iso_code']) : '--';
                $output[$i]['countryName'] = $locale->getText('countries.' . strtolower($record['country']['iso_code']), $locale->getText('locale.country.unknown'));
            } else {
                $output[$i]['countryCode'] = '--';
                $output[$i]['countryName'] = $locale->getText('locale.country.unknown');
            }
        }

        $response->dynamic(new Document([
            'total' => $includeTotal ? $audit->countLogsByUser($user->getSequence(), $queries) : 0,
            'logs' => $output,
        ]), Response::MODEL_LOG_LIST);
    });

App::get('/v1/users/:userId/targets')
    ->desc('List user targets')
    ->groups(['api', 'users'])
    ->label('scope', 'targets.read')
    ->label('sdk', new Method(
        namespace: 'users',
        group: 'targets',
        name: 'listTargets',
        description: '/docs/references/users/list-user-targets.md',
        auth: [AuthType::KEY, AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_TARGET_LIST,
            )
        ]
    ))
    ->param('userId', '', new UID(), 'User ID.')
    ->param('queries', [], new Targets(), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long. You may filter on the following attributes: ' . implode(', ', Targets::ALLOWED_ATTRIBUTES), true)
    ->param('total', true, new Boolean(true), 'When set to false, the total count returned will be 0 and will not be calculated.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (string $userId, array $queries, bool $includeTotal, Response $response, Database $dbForProject) {
        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }
        try {
            $queries = Query::parseQueries($queries);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }
        $queries[] = Query::equal('userId', [$userId]);
        /**
         * Get cursor document if there was a cursor query, we use array_filter and reset for reference $cursor to $queries
         */
        $cursor = \array_filter($queries, function ($query) {
            return \in_array($query->getMethod(), [Query::TYPE_CURSOR_AFTER, Query::TYPE_CURSOR_BEFORE]);
        });
        $cursor = reset($cursor);
        if ($cursor) {
            $validator = new Cursor();
            if (!$validator->isValid($cursor)) {
                throw new Exception(Exception::GENERAL_QUERY_INVALID, $validator->getDescription());
            }
            $targetId = $cursor->getValue();
            $cursorDocument = $dbForProject->getDocument('targets', $targetId);
            if ($cursorDocument->isEmpty()) {
                throw new Exception(Exception::GENERAL_CURSOR_NOT_FOUND, "Target '{$targetId}' for the 'cursor' value not found.");
            }
            $cursor->setValue($cursorDocument);
        }
        try {
            $targets = $dbForProject->find('targets', $queries);
            $total = $includeTotal ? $dbForProject->count('targets', $queries, APP_LIMIT_COUNT) : 0;
        } catch (OrderException $e) {
            throw new Exception(Exception::DATABASE_QUERY_ORDER_NULL, "The order attribute '{$e->getAttribute()}' had a null value. Cursor pagination requires all documents order attribute values are non-null.");
        }
        $response->dynamic(new Document([
            'targets' => $targets,
            'total' => $total,
        ]), Response::MODEL_TARGET_LIST);
    });

App::get('/v1/users/identities')
    ->desc('List identities')
    ->groups(['api', 'users'])
    ->label('scope', 'users.read')
    ->label('sdk', new Method(
        namespace: 'users',
        group: 'identities',
        name: 'listIdentities',
        description: '/docs/references/users/list-identities.md',
        auth: [AuthType::ADMIN, AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_IDENTITY_LIST,
            )
        ]
    ))
    ->param('queries', [], new Identities(), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long. You may filter on the following attributes: ' . implode(', ', Identities::ALLOWED_ATTRIBUTES), true)
    ->param('search', '', new Text(256), 'Search term to filter your list results. Max length: 256 chars.', true)
    ->param('total', true, new Boolean(true), 'When set to false, the total count returned will be 0 and will not be calculated.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (array $queries, string $search, bool $includeTotal, Response $response, Database $dbForProject) {

        try {
            $queries = Query::parseQueries($queries);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        if (!empty($search)) {
            $queries[] = Query::search('search', $search);
        }
        /**
         * Get cursor document if there was a cursor query, we use array_filter and reset for reference $cursor to $queries
         */
        $cursor = \array_filter($queries, function ($query) {
            return \in_array($query->getMethod(), [Query::TYPE_CURSOR_AFTER, Query::TYPE_CURSOR_BEFORE]);
        });
        $cursor = reset($cursor);
        if ($cursor) {
            /** @var Query $cursor */
            $validator = new Cursor();
            if (!$validator->isValid($cursor)) {
                throw new Exception(Exception::GENERAL_QUERY_INVALID, $validator->getDescription());
            }
            $identityId = $cursor->getValue();
            $cursorDocument = $dbForProject->getDocument('identities', $identityId);
            if ($cursorDocument->isEmpty()) {
                throw new Exception(Exception::GENERAL_CURSOR_NOT_FOUND, "User '{$identityId}' for the 'cursor' value not found.");
            }
            $cursor->setValue($cursorDocument);
        }

        try {
            $identities = $dbForProject->find('identities', $queries);
            $total = $includeTotal ? $dbForProject->count('identities', $queries, APP_LIMIT_COUNT) : 0;
        } catch (OrderException $e) {
            throw new Exception(Exception::DATABASE_QUERY_ORDER_NULL, "The order attribute '{$e->getAttribute()}' had a null value. Cursor pagination requires all documents order attribute values are non-null.");
        }
        $response->dynamic(new Document([
            'identities' => $identities,
            'total' => $total,
        ]), Response::MODEL_IDENTITY_LIST);
    });

App::patch('/v1/users/:userId/status')
    ->desc('Update user status')
    ->groups(['api', 'users'])
    ->label('event', 'users.[userId].update.status')
    ->label('scope', 'users.write')
    ->label('audits.event', 'user.update')
    ->label('audits.resource', 'user/{response.$id}')
    ->label('audits.userId', '{response.$id}')
    ->label('sdk', new Method(
        namespace: 'users',
        group: 'users',
        name: 'updateStatus',
        description: '/docs/references/users/update-user-status.md',
        auth: [AuthType::ADMIN, AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_USER,
            )
        ]
    ))
    ->param('userId', '', new UID(), 'User ID.')
    ->param('status', null, new Boolean(true), 'User Status. To activate the user pass `true` and to block the user pass `false`.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForEvents')
    ->action(function (string $userId, bool $status, Response $response, Database $dbForProject, Event $queueForEvents) {

        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        $user = $dbForProject->updateDocument('users', $user->getId(), $user->setAttribute('status', (bool) $status));

        $queueForEvents
            ->setParam('userId', $user->getId());

        $response->dynamic($user, Response::MODEL_USER);
    });

App::put('/v1/users/:userId/labels')
    ->desc('Update user labels')
    ->groups(['api', 'users'])
    ->label('event', 'users.[userId].update.labels')
    ->label('scope', 'users.write')
    ->label('audits.event', 'user.update')
    ->label('audits.resource', 'user/{response.$id}')
    ->label('sdk', new Method(
        namespace: 'users',
        group: 'users',
        name: 'updateLabels',
        description: '/docs/references/users/update-user-labels.md',
        auth: [AuthType::ADMIN, AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_USER,
            )
        ]
    ))
    ->param('userId', '', new UID(), 'User ID.')
    ->param('labels', [], new ArrayList(new Text(36, allowList: [...Text::NUMBERS, ...Text::ALPHABET_UPPER, ...Text::ALPHABET_LOWER]), APP_LIMIT_ARRAY_LABELS_SIZE), 'Array of user labels. Replaces the previous labels. Maximum of ' . APP_LIMIT_ARRAY_LABELS_SIZE . ' labels are allowed, each up to 36 alphanumeric characters long.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForEvents')
    ->action(function (string $userId, array $labels, Response $response, Database $dbForProject, Event $queueForEvents) {

        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        $user->setAttribute('labels', (array) \array_values(\array_unique($labels)));

        $user = $dbForProject->updateDocument('users', $user->getId(), $user);

        $queueForEvents
            ->setParam('userId', $user->getId());

        $response->dynamic($user, Response::MODEL_USER);
    });

App::patch('/v1/users/:userId/verification/phone')
    ->desc('Update phone verification')
    ->groups(['api', 'users'])
    ->label('event', 'users.[userId].update.verification')
    ->label('scope', 'users.write')
    ->label('audits.event', 'verification.update')
    ->label('audits.resource', 'user/{response.$id}')
    ->label('sdk', new Method(
        namespace: 'users',
        group: 'users',
        name: 'updatePhoneVerification',
        description: '/docs/references/users/update-user-phone-verification.md',
        auth: [AuthType::ADMIN, AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_USER,
            )
        ]
    ))
    ->param('userId', '', new UID(), 'User ID.')
    ->param('phoneVerification', false, new Boolean(), 'User phone verification status.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForEvents')
    ->action(function (string $userId, bool $phoneVerification, Response $response, Database $dbForProject, Event $queueForEvents) {

        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        $user = $dbForProject->updateDocument('users', $user->getId(), $user->setAttribute('phoneVerification', $phoneVerification));

        $queueForEvents
            ->setParam('userId', $user->getId());

        $response->dynamic($user, Response::MODEL_USER);
    });

App::patch('/v1/users/:userId/name')
    ->desc('Update name')
    ->groups(['api', 'users'])
    ->label('event', 'users.[userId].update.name')
    ->label('scope', 'users.write')
    ->label('audits.event', 'user.update')
    ->label('audits.resource', 'user/{response.$id}')
    ->label('audits.userId', '{response.$id}')
    ->label('sdk', new Method(
        namespace: 'users',
        group: 'users',
        name: 'updateName',
        description: '/docs/references/users/update-user-name.md',
        auth: [AuthType::ADMIN, AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_USER,
            )
        ]
    ))
    ->param('userId', '', new UID(), 'User ID.')
    ->param('name', '', new Text(128, 0), 'User name. Max length: 128 chars.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForEvents')
    ->action(function (string $userId, string $name, Response $response, Database $dbForProject, Event $queueForEvents) {

        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        $user->setAttribute('name', $name);

        $user = $dbForProject->updateDocument('users', $user->getId(), $user);

        $queueForEvents->setParam('userId', $user->getId());

        $response->dynamic($user, Response::MODEL_USER);
    });

App::patch('/v1/users/:userId/password')
    ->desc('Update password')
    ->groups(['api', 'users'])
    ->label('event', 'users.[userId].update.password')
    ->label('scope', 'users.write')
    ->label('audits.event', 'user.update')
    ->label('audits.resource', 'user/{response.$id}')
    ->label('audits.userId', '{response.$id}')
    ->label('sdk', new Method(
        namespace: 'users',
        group: 'users',
        name: 'updatePassword',
        description: '/docs/references/users/update-user-password.md',
        auth: [AuthType::ADMIN, AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_USER,
            )
        ]
    ))
    ->param('userId', '', new UID(), 'User ID.')
    ->param('password', '', fn ($project, $passwordsDictionary) => new PasswordDictionary($passwordsDictionary, enabled: $project->getAttribute('auths', [])['passwordDictionary'] ?? false, allowEmpty: true), 'New user password. Must be at least 8 chars.', false, ['project', 'passwordsDictionary'])
    ->inject('response')
    ->inject('project')
    ->inject('dbForProject')
    ->inject('queueForEvents')
    ->inject('hooks')
    ->action(function (string $userId, string $password, Response $response, Document $project, Database $dbForProject, Event $queueForEvents, Hooks $hooks) {

        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        if ($project->getAttribute('auths', [])['personalDataCheck'] ?? false) {
            $personalDataValidator = new PersonalData($userId, $user->getAttribute('email'), $user->getAttribute('name'), $user->getAttribute('phone'));
            if (!$personalDataValidator->isValid($password)) {
                throw new Exception(Exception::USER_PASSWORD_PERSONAL_DATA);
            }
        }

        if (\strlen($password) === 0) {
            $user
                ->setAttribute('password', '')
                ->setAttribute('passwordUpdate', DateTime::now());

            $user = $dbForProject->updateDocument('users', $user->getId(), $user);
            $queueForEvents->setParam('userId', $user->getId());
            $response->dynamic($user, Response::MODEL_USER);
        }

        $hooks->trigger('passwordValidator', [$dbForProject, $project, $password, &$user, true]);

        // Create Argon2 hasher with default settings
        $hasher = new Argon2();

        $newPassword = $hasher->hash($password);

        $hash = ProofsPassword::createHash($user->getAttribute('hash'), $user->getAttribute('hashOptions'));
        $historyLimit = $project->getAttribute('auths', [])['passwordHistory'] ?? 0;
        $history = $user->getAttribute('passwordHistory', []);

        if ($historyLimit > 0) {
            $validator = new PasswordHistory($history, $hash);
            if (!$validator->isValid($password)) {
                throw new Exception(Exception::USER_PASSWORD_RECENTLY_USED);
            }

            $history[] = $newPassword;
            $history = array_slice($history, (count($history) - $historyLimit), $historyLimit);
        }

        $user
            ->setAttribute('password', $newPassword)
            ->setAttribute('passwordHistory', $history)
            ->setAttribute('passwordUpdate', DateTime::now())
            ->setAttribute('hash', $hasher->getName())
            ->setAttribute('hashOptions', $hasher->getOptions());

        $user = $dbForProject->updateDocument('users', $user->getId(), $user);

        $sessions = $user->getAttribute('sessions', []);
        $invalidate = $project->getAttribute('auths', default: [])['invalidateSessions'] ?? false;
        if ($invalidate) {
            foreach ($sessions as $session) {
                /** @var Document $session */
                $dbForProject->deleteDocument('sessions', $session->getId());
            }
        }

        $dbForProject->purgeCachedDocument('users', $user->getId());

        $queueForEvents->setParam('userId', $user->getId());

        $response->dynamic($user, Response::MODEL_USER);
    });

App::patch('/v1/users/:userId/email')
    ->desc('Update email')
    ->groups(['api', 'users'])
    ->label('event', 'users.[userId].update.email')
    ->label('scope', 'users.write')
    ->label('audits.event', 'user.update')
    ->label('audits.resource', 'user/{response.$id}')
    ->label('audits.userId', '{response.$id}')
    ->label('sdk', new Method(
        namespace: 'users',
        group: 'users',
        name: 'updateEmail',
        description: '/docs/references/users/update-user-email.md',
        auth: [AuthType::ADMIN, AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_USER,
            )
        ]
    ))
    ->param('userId', '', new UID(), 'User ID.')
    ->param('email', '', new EmailValidator(allowEmpty: true), 'User email.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForEvents')
    ->action(function (string $userId, string $email, Response $response, Database $dbForProject, Event $queueForEvents) {

        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        $email = \strtolower($email);

        if (\strlen($email) !== 0) {
            // Makes sure this email is not already used in another identity
            $identityWithMatchingEmail = $dbForProject->findOne('identities', [
                Query::equal('providerEmail', [$email]),
                Query::notEqual('userInternalId', $user->getSequence()),
            ]);
            if (!$identityWithMatchingEmail->isEmpty()) {
                throw new Exception(Exception::USER_EMAIL_ALREADY_EXISTS);
            }

            $target = $dbForProject->findOne('targets', [
                Query::equal('identifier', [$email]),
            ]);

            if ($target instanceof Document && !$target->isEmpty()) {
                throw new Exception(Exception::USER_TARGET_ALREADY_EXISTS);
            }
        }

        $oldEmail = $user->getAttribute('email');

        try {
            $emailCanonical = new Email($email);
        } catch (Throwable) {
            $emailCanonical = null;
        }

        $user
            ->setAttribute('email', $email)
            ->setAttribute('emailVerification', false)
            ->setAttribute('emailCanonical', $emailCanonical?->getCanonical())
            ->setAttribute('emailIsCanonical', $emailCanonical?->isCanonicalSupported())
            ->setAttribute('emailIsCorporate', $emailCanonical?->isCorporate())
            ->setAttribute('emailIsDisposable', $emailCanonical?->isDisposable())
            ->setAttribute('emailIsFree', $emailCanonical?->isFree())
        ;

        try {
            $user = $dbForProject->updateDocument('users', $user->getId(), $user);
            /**
             * @var Document $oldTarget
             */
            $oldTarget = $user->find('identifier', $oldEmail, 'targets');

            if ($oldTarget instanceof Document && !$oldTarget->isEmpty()) {
                if (\strlen($email) !== 0) {
                    $dbForProject->updateDocument('targets', $oldTarget->getId(), $oldTarget->setAttribute('identifier', $email));
                } else {
                    $dbForProject->deleteDocument('targets', $oldTarget->getId());
                }
            } else {
                if (\strlen($email) !== 0) {
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
                }
            }
            $dbForProject->purgeCachedDocument('users', $user->getId());
        } catch (Duplicate $th) {
            throw new Exception(Exception::USER_EMAIL_ALREADY_EXISTS);
        }

        $queueForEvents->setParam('userId', $user->getId());

        $response->dynamic($user, Response::MODEL_USER);
    });

App::patch('/v1/users/:userId/phone')
    ->desc('Update phone')
    ->groups(['api', 'users'])
    ->label('event', 'users.[userId].update.phone')
    ->label('scope', 'users.write')
    ->label('audits.event', 'user.update')
    ->label('audits.resource', 'user/{response.$id}')
    ->label('sdk', new Method(
        namespace: 'users',
        group: 'users',
        name: 'updatePhone',
        description: '/docs/references/users/update-user-phone.md',
        auth: [AuthType::ADMIN, AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_USER,
            )
        ]
    ))
    ->param('userId', '', new UID(), 'User ID.')
    ->param('number', '', new Phone(allowEmpty: true), 'User phone number.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForEvents')
    ->action(function (string $userId, string $number, Response $response, Database $dbForProject, Event $queueForEvents) {

        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        $oldPhone = $user->getAttribute('phone');

        $user
            ->setAttribute('phone', $number)
            ->setAttribute('phoneVerification', false)
        ;

        if (\strlen($number) !== 0) {
            $target = $dbForProject->findOne('targets', [
                Query::equal('identifier', [$number]),
            ]);

            if ($target instanceof Document && !$target->isEmpty()) {
                throw new Exception(Exception::USER_TARGET_ALREADY_EXISTS);
            }
        }

        try {
            $user = $dbForProject->updateDocument('users', $user->getId(), $user);
            /**
             * @var Document $oldTarget
             */
            $oldTarget = $user->find('identifier', $oldPhone, 'targets');

            if ($oldTarget instanceof Document && !$oldTarget->isEmpty()) {
                if (\strlen($number) !== 0) {
                    $dbForProject->updateDocument('targets', $oldTarget->getId(), $oldTarget->setAttribute('identifier', $number));
                } else {
                    $dbForProject->deleteDocument('targets', $oldTarget->getId());
                }
            } else {
                if (\strlen($number) !== 0) {
                    $target = $dbForProject->createDocument('targets', new Document([
                        '$permissions' => [
                            Permission::read(Role::user($user->getId())),
                            Permission::update(Role::user($user->getId())),
                            Permission::delete(Role::user($user->getId())),
                        ],
                        'userId' => $user->getId(),
                        'userInternalId' => $user->getSequence(),
                        'providerType' => 'sms',
                        'identifier' => $number,
                    ]));
                    $user->setAttribute('targets', [...$user->getAttribute('targets', []), $target]);
                }
            }
            $dbForProject->purgeCachedDocument('users', $user->getId());
        } catch (Duplicate $th) {
            throw new Exception(Exception::USER_PHONE_ALREADY_EXISTS);
        }

        $queueForEvents->setParam('userId', $user->getId());

        $response->dynamic($user, Response::MODEL_USER);
    });

App::patch('/v1/users/:userId/verification')
    ->desc('Update email verification')
    ->groups(['api', 'users'])
    ->label('event', 'users.[userId].update.verification')
    ->label('scope', 'users.write')
    ->label('audits.event', 'verification.update')
    ->label('audits.resource', 'user/{request.userId}')
    ->label('audits.userId', '{request.userId}')
    ->label('sdk', new Method(
        namespace: 'users',
        group: 'users',
        name: 'updateEmailVerification',
        description: '/docs/references/users/update-user-email-verification.md',
        auth: [AuthType::ADMIN, AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_USER,
            )
        ]
    ))
    ->param('userId', '', new UID(), 'User ID.')
    ->param('emailVerification', false, new Boolean(), 'User email verification status.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForEvents')
    ->action(function (string $userId, bool $emailVerification, Response $response, Database $dbForProject, Event $queueForEvents) {

        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        $user = $dbForProject->updateDocument('users', $user->getId(), $user->setAttribute('emailVerification', $emailVerification));

        $queueForEvents->setParam('userId', $user->getId());

        $response->dynamic($user, Response::MODEL_USER);
    });

App::patch('/v1/users/:userId/prefs')
    ->desc('Update user preferences')
    ->groups(['api', 'users'])
    ->label('event', 'users.[userId].update.prefs')
    ->label('scope', 'users.write')
    ->label('sdk', new Method(
        namespace: 'users',
        group: 'users',
        name: 'updatePrefs',
        description: '/docs/references/users/update-user-prefs.md',
        auth: [AuthType::ADMIN, AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_PREFERENCES,
            )
        ]
    ))
    ->param('userId', '', new UID(), 'User ID.')
    ->param('prefs', '', new Assoc(), 'Prefs key-value JSON object.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForEvents')
    ->action(function (string $userId, array $prefs, Response $response, Database $dbForProject, Event $queueForEvents) {

        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        $user = $dbForProject->updateDocument('users', $user->getId(), $user->setAttribute('prefs', $prefs));

        $queueForEvents
            ->setParam('userId', $user->getId());

        $response->dynamic(new Document($prefs), Response::MODEL_PREFERENCES);
    });

App::patch('/v1/users/:userId/targets/:targetId')
    ->desc('Update user target')
    ->groups(['api', 'users'])
    ->label('audits.event', 'target.update')
    ->label('audits.resource', 'target/{response.$id}')
    ->label('event', 'users.[userId].targets.[targetId].update')
    ->label('scope', 'targets.write')
    ->label('sdk', new Method(
        namespace: 'users',
        group: 'targets',
        name: 'updateTarget',
        description: '/docs/references/users/update-target.md',
        auth: [AuthType::KEY, AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_TARGET,
            )
        ]
    ))
    ->param('userId', '', new UID(), 'User ID.')
    ->param('targetId', '', new UID(), 'Target ID.')
    ->param('identifier', '', new Text(Database::LENGTH_KEY), 'The target identifier (token, email, phone etc.)', true)
    ->param('providerId', '', new UID(), 'Provider ID. Message will be sent to this target from the specified provider ID. If no provider ID is set the first setup provider will be used.', true)
    ->param('name', '', new Text(128), 'Target name. Max length: 128 chars. For example: My Awesome App Galaxy S23.', true)
    ->inject('queueForEvents')
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (string $userId, string $targetId, string $identifier, string $providerId, string $name, Event $queueForEvents, Response $response, Database $dbForProject) {
        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        $target = $dbForProject->getDocument('targets', $targetId);

        if ($target->isEmpty()) {
            throw new Exception(Exception::USER_TARGET_NOT_FOUND);
        }

        if ($user->getId() !== $target->getAttribute('userId')) {
            throw new Exception(Exception::USER_TARGET_NOT_FOUND);
        }

        if ($identifier) {
            $providerType = $target->getAttribute('providerType');

            switch ($providerType) {
                case 'email':
                    $validator = new EmailValidator();
                    if (!$validator->isValid($identifier)) {
                        throw new Exception(Exception::GENERAL_INVALID_EMAIL);
                    }
                    break;
                case MESSAGE_TYPE_SMS:
                    $validator = new Phone();
                    if (!$validator->isValid($identifier)) {
                        throw new Exception(Exception::GENERAL_INVALID_PHONE);
                    }
                    break;
                case MESSAGE_TYPE_PUSH:
                    break;
                default:
                    throw new Exception(Exception::PROVIDER_INCORRECT_TYPE);
            }

            $target
                ->setAttribute('identifier', $identifier)
                ->setAttribute('expired', false);
        }

        if ($providerId) {
            $provider = $dbForProject->getDocument('providers', $providerId);

            if ($provider->isEmpty()) {
                throw new Exception(Exception::PROVIDER_NOT_FOUND);
            }

            if ($provider->getAttribute('type') !== $target->getAttribute('providerType')) {
                throw new Exception(Exception::PROVIDER_INCORRECT_TYPE);
            }

            $target
                ->setAttribute('providerId', $provider->getId())
                ->setAttribute('providerInternalId', $provider->getSequence());
        }

        if ($name) {
            $target->setAttribute('name', $name);
        }

        $target = $dbForProject->updateDocument('targets', $target->getId(), $target);
        $dbForProject->purgeCachedDocument('users', $user->getId());

        $queueForEvents
            ->setParam('userId', $user->getId())
            ->setParam('targetId', $target->getId());

        $response
            ->dynamic($target, Response::MODEL_TARGET);
    });

App::patch('/v1/users/:userId/mfa')
    ->desc('Update MFA')
    ->groups(['api', 'users'])
    ->label('event', 'users.[userId].update.mfa')
    ->label('scope', 'users.write')
    ->label('audits.event', 'user.update')
    ->label('audits.resource', 'user/{response.$id}')
    ->label('audits.userId', '{response.$id}')
    ->label('usage.metric', 'users.{scope}.requests.update')
    ->label('sdk', [
        new Method(
            namespace: 'users',
            group: 'users',
            name: 'updateMfa',
            description: '/docs/references/users/update-user-mfa.md',
            auth: [AuthType::ADMIN, AuthType::KEY],
            responses: [
                new SDKResponse(
                    code: Response::STATUS_CODE_OK,
                    model: Response::MODEL_USER,
                )
            ],
            deprecated: new Deprecated(
                since: '1.8.0',
                replaceWith: 'users.updateMFA',
            ),
            public: false,
        ),
        new Method(
            namespace: 'users',
            group: 'users',
            name: 'updateMFA',
            description: '/docs/references/users/update-user-mfa.md',
            auth: [AuthType::ADMIN, AuthType::KEY],
            responses: [
                new SDKResponse(
                    code: Response::STATUS_CODE_OK,
                    model: Response::MODEL_USER,
                )
            ]
        )
    ])
    ->param('userId', '', new UID(), 'User ID.')
    ->param('mfa', null, new Boolean(), 'Enable or disable MFA.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForEvents')
    ->action(function (string $userId, bool $mfa, Response $response, Database $dbForProject, Event $queueForEvents) {

        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        $user->setAttribute('mfa', $mfa);

        $user = $dbForProject->updateDocument('users', $user->getId(), $user);

        $queueForEvents->setParam('userId', $user->getId());

        $response->dynamic($user, Response::MODEL_USER);
    });

App::get('/v1/users/:userId/mfa/factors')
    ->desc('List factors')
    ->groups(['api', 'users'])
    ->label('scope', 'users.read')
    ->label('usage.metric', 'users.{scope}.requests.read')
    ->label('sdk', [
        new Method(
            namespace: 'users',
            group: 'mfa',
            name: 'listMfaFactors',
            description: '/docs/references/users/list-mfa-factors.md',
            auth: [AuthType::ADMIN, AuthType::KEY],
            responses: [
                new SDKResponse(
                    code: Response::STATUS_CODE_OK,
                    model: Response::MODEL_MFA_FACTORS,
                )
            ],
            deprecated: new Deprecated(
                since: '1.8.0',
                replaceWith: 'users.listMFAFactors',
            ),
            public: false,
        ),
        new Method(
            namespace: 'users',
            group: 'mfa',
            name: 'listMFAFactors',
            description: '/docs/references/users/list-mfa-factors.md',
            auth: [AuthType::ADMIN, AuthType::KEY],
            responses: [
                new SDKResponse(
                    code: Response::STATUS_CODE_OK,
                    model: Response::MODEL_MFA_FACTORS,
                )
            ]
        )
    ])
    ->param('userId', '', new UID(), 'User ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (string $userId, Response $response, Database $dbForProject) {
        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        $totp = TOTP::getAuthenticatorFromUser($user);

        $factors = new Document([
            Type::TOTP => $totp !== null && $totp->getAttribute('verified', false),
            Type::EMAIL => $user->getAttribute('email', false) && $user->getAttribute('emailVerification', false),
            Type::PHONE => $user->getAttribute('phone', false) && $user->getAttribute('phoneVerification', false)
        ]);

        $response->dynamic($factors, Response::MODEL_MFA_FACTORS);
    });

App::get('/v1/users/:userId/mfa/recovery-codes')
    ->desc('Get MFA recovery codes')
    ->groups(['api', 'users'])
    ->label('scope', 'users.read')
    ->label('usage.metric', 'users.{scope}.requests.read')
    ->label('sdk', [
        new Method(
            namespace: 'users',
            group: 'mfa',
            name: 'getMfaRecoveryCodes',
            description: '/docs/references/users/get-mfa-recovery-codes.md',
            auth: [AuthType::ADMIN, AuthType::KEY],
            responses: [
                new SDKResponse(
                    code: Response::STATUS_CODE_OK,
                    model: Response::MODEL_MFA_RECOVERY_CODES,
                )
            ],
            deprecated: new Deprecated(
                since: '1.8.0',
                replaceWith: 'users.getMFARecoveryCodes',
            ),
            public: false,
        ),
        new Method(
            namespace: 'users',
            group: 'mfa',
            name: 'getMFARecoveryCodes',
            description: '/docs/references/users/get-mfa-recovery-codes.md',
            auth: [AuthType::ADMIN, AuthType::KEY],
            responses: [
                new SDKResponse(
                    code: Response::STATUS_CODE_OK,
                    model: Response::MODEL_MFA_RECOVERY_CODES,
                )
            ]
        )
    ])
    ->param('userId', '', new UID(), 'User ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (string $userId, Response $response, Database $dbForProject) {
        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        $mfaRecoveryCodes = $user->getAttribute('mfaRecoveryCodes', []);

        if (empty($mfaRecoveryCodes)) {
            throw new Exception(Exception::USER_RECOVERY_CODES_NOT_FOUND);
        }

        $document = new Document([
            'recoveryCodes' => $mfaRecoveryCodes
        ]);

        $response->dynamic($document, Response::MODEL_MFA_RECOVERY_CODES);
    });

App::patch('/v1/users/:userId/mfa/recovery-codes')
    ->desc('Create MFA recovery codes')
    ->groups(['api', 'users'])
    ->label('event', 'users.[userId].create.mfa.recovery-codes')
    ->label('scope', 'users.write')
    ->label('audits.event', 'user.update')
    ->label('audits.resource', 'user/{response.$id}')
    ->label('audits.userId', '{response.$id}')
    ->label('usage.metric', 'users.{scope}.requests.update')
    ->label('sdk', [
        new Method(
            namespace: 'users',
            group: 'mfa',
            name: 'createMfaRecoveryCodes',
            description: '/docs/references/users/create-mfa-recovery-codes.md',
            auth: [AuthType::ADMIN, AuthType::KEY],
            responses: [
                new SDKResponse(
                    code: Response::STATUS_CODE_CREATED,
                    model: Response::MODEL_MFA_RECOVERY_CODES,
                )
            ],
            deprecated: new Deprecated(
                since: '1.8.0',
                replaceWith: 'users.createMFARecoveryCodes',
            ),
            public: false,
        ),
        new Method(
            namespace: 'users',
            group: 'mfa',
            name: 'createMFARecoveryCodes',
            description: '/docs/references/users/create-mfa-recovery-codes.md',
            auth: [AuthType::ADMIN, AuthType::KEY],
            responses: [
                new SDKResponse(
                    code: Response::STATUS_CODE_CREATED,
                    model: Response::MODEL_MFA_RECOVERY_CODES,
                )
            ]
        )
    ])
    ->param('userId', '', new UID(), 'User ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForEvents')
    ->action(function (string $userId, Response $response, Database $dbForProject, Event $queueForEvents) {
        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        $mfaRecoveryCodes = $user->getAttribute('mfaRecoveryCodes', []);

        if (!empty($mfaRecoveryCodes)) {
            throw new Exception(Exception::USER_RECOVERY_CODES_ALREADY_EXISTS);
        }

        $mfaRecoveryCodes = Type::generateBackupCodes();
        $user->setAttribute('mfaRecoveryCodes', $mfaRecoveryCodes);
        $dbForProject->updateDocument('users', $user->getId(), $user);

        $queueForEvents->setParam('userId', $user->getId());

        $document = new Document([
            'recoveryCodes' => $mfaRecoveryCodes
        ]);

        $response->dynamic($document, Response::MODEL_MFA_RECOVERY_CODES);
    });

App::put('/v1/users/:userId/mfa/recovery-codes')
    ->desc('Update MFA recovery codes (regenerate)')
    ->groups(['api', 'users'])
    ->label('event', 'users.[userId].update.mfa.recovery-codes')
    ->label('scope', 'users.write')
    ->label('audits.event', 'user.update')
    ->label('audits.resource', 'user/{response.$id}')
    ->label('audits.userId', '{response.$id}')
    ->label('usage.metric', 'users.{scope}.requests.update')
    ->label('sdk', [
        new Method(
            namespace: 'users',
            group: 'mfa',
            name: 'updateMfaRecoveryCodes',
            description: '/docs/references/users/update-mfa-recovery-codes.md',
            auth: [AuthType::ADMIN, AuthType::KEY],
            responses: [
                new SDKResponse(
                    code: Response::STATUS_CODE_OK,
                    model: Response::MODEL_MFA_RECOVERY_CODES,
                )
            ],
            deprecated: new Deprecated(
                since: '1.8.0',
                replaceWith: 'users.updateMFARecoveryCodes',
            ),
            public: false,
        ),
        new Method(
            namespace: 'users',
            group: 'mfa',
            name: 'updateMFARecoveryCodes',
            description: '/docs/references/users/update-mfa-recovery-codes.md',
            auth: [AuthType::ADMIN, AuthType::KEY],
            responses: [
                new SDKResponse(
                    code: Response::STATUS_CODE_OK,
                    model: Response::MODEL_MFA_RECOVERY_CODES,
                )
            ],
            public: false,
        )
    ])
    ->param('userId', '', new UID(), 'User ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForEvents')
    ->action(function (string $userId, Response $response, Database $dbForProject, Event $queueForEvents) {
        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        $mfaRecoveryCodes = $user->getAttribute('mfaRecoveryCodes', []);
        if (empty($mfaRecoveryCodes)) {
            throw new Exception(Exception::USER_RECOVERY_CODES_NOT_FOUND);
        }

        $mfaRecoveryCodes = Type::generateBackupCodes();
        $user->setAttribute('mfaRecoveryCodes', $mfaRecoveryCodes);
        $dbForProject->updateDocument('users', $user->getId(), $user);

        $queueForEvents->setParam('userId', $user->getId());

        $document = new Document([
            'recoveryCodes' => $mfaRecoveryCodes
        ]);

        $response->dynamic($document, Response::MODEL_MFA_RECOVERY_CODES);
    });

App::delete('/v1/users/:userId/mfa/authenticators/:type')
    ->desc('Delete authenticator')
    ->groups(['api', 'users'])
    ->label('event', 'users.[userId].delete.mfa')
    ->label('scope', 'users.write')
    ->label('audits.event', 'user.update')
    ->label('audits.resource', 'user/{response.$id}')
    ->label('audits.userId', '{response.$id}')
    ->label('usage.metric', 'users.{scope}.requests.update')
    ->label('sdk', [
        new Method(
            namespace: 'users',
            group: 'mfa',
            name: 'deleteMfaAuthenticator',
            description: '/docs/references/users/delete-mfa-authenticator.md',
            auth: [AuthType::ADMIN, AuthType::KEY],
            responses: [
                new SDKResponse(
                    code: Response::STATUS_CODE_NOCONTENT,
                    model: Response::MODEL_NONE,
                )
            ],
            contentType: ContentType::NONE,
            deprecated: new Deprecated(
                since: '1.8.0',
                replaceWith: 'users.deleteMFAAuthenticator',
            ),
            public: false,
        ),
        new Method(
            namespace: 'users',
            group: 'mfa',
            name: 'deleteMFAAuthenticator',
            description: '/docs/references/users/delete-mfa-authenticator.md',
            auth: [AuthType::ADMIN, AuthType::KEY],
            responses: [
                new SDKResponse(
                    code: Response::STATUS_CODE_NOCONTENT,
                    model: Response::MODEL_NONE,
                )
            ],
            contentType: ContentType::NONE
        )
    ])
    ->param('userId', '', new UID(), 'User ID.')
    ->param('type', null, new WhiteList([Type::TOTP]), 'Type of authenticator.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForEvents')
    ->action(function (string $userId, string $type, Response $response, Database $dbForProject, Event $queueForEvents) {
        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        $authenticator = TOTP::getAuthenticatorFromUser($user);

        if ($authenticator === null) {
            throw new Exception(Exception::USER_AUTHENTICATOR_NOT_FOUND);
        }

        $dbForProject->deleteDocument('authenticators', $authenticator->getId());
        $dbForProject->purgeCachedDocument('users', $user->getId());

        $queueForEvents->setParam('userId', $user->getId());

        $response->noContent();
    });

App::post('/v1/users/:userId/sessions')
    ->desc('Create session')
    ->groups(['api', 'users'])
    ->label('event', 'users.[userId].sessions.[sessionId].create')
    ->label('scope', 'users.write')
    ->label('audits.event', 'session.create')
    ->label('audits.resource', 'user/{request.userId}')
    ->label('usage.metric', 'sessions.{scope}.requests.create')
    ->label('sdk', new Method(
        namespace: 'users',
        group: 'sessions',
        name: 'createSession',
        description: '/docs/references/users/create-session.md',
        auth: [AuthType::ADMIN, AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_CREATED,
                model: Response::MODEL_SESSION,
            )
        ]
    ))
    ->param('userId', '', new CustomId(), 'User ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->inject('request')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('project')
    ->inject('locale')
    ->inject('geodb')
    ->inject('queueForEvents')
    ->inject('store')
    ->inject('proofForToken')
    ->action(function (string $userId, Request $request, Response $response, Database $dbForProject, Document $project, Locale $locale, Reader $geodb, Event $queueForEvents, Store $store, Token $proofForToken) {
        $user = $dbForProject->getDocument('users', $userId);
        if ($user->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        $secret = $proofForToken->generate();
        $detector = new Detector($request->getUserAgent('UNKNOWN'));
        $record = $geodb->get($request->getIP());

        $duration = $project->getAttribute('auths', [])['duration'] ?? TOKEN_EXPIRATION_LOGIN_LONG;
        $expire = DateTime::formatTz(DateTime::addSeconds(new \DateTime(), $duration));

        $session = new Document(array_merge(
            [
                '$id' => ID::unique(),
                'userId' => $user->getId(),
                'userInternalId' => $user->getSequence(),
                'provider' => SESSION_PROVIDER_SERVER,
                'secret' => $proofForToken->hash($secret), // One way hash encryption to protect DB leak
                'userAgent' => $request->getUserAgent('UNKNOWN'),
                'factors' => ['server'],
                'ip' => $request->getIP(),
                'countryCode' => ($record) ? \strtolower($record['country']['iso_code']) : '--',
                'expire' => $expire,
            ],
            $detector->getOS(),
            $detector->getClient(),
            $detector->getDevice()
        ));

        $session->setAttribute('$permissions', [
            Permission::read(Role::user($user->getId())),
            Permission::update(Role::user($user->getId())),
            Permission::delete(Role::user($user->getId())),
        ]);

        $countryName = $locale->getText('countries.' . strtolower($session->getAttribute('countryCode')), $locale->getText('locale.country.unknown'));

        $session = $dbForProject->createDocument('sessions', $session);

        $dbForProject->purgeCachedDocument('users', $user->getId());

        $encoded = $store
            ->setProperty('id', $user->getId())
            ->setProperty('secret', $secret)
            ->encode();

        $session
            ->setAttribute('secret', $encoded)
            ->setAttribute('countryName', $countryName);

        $queueForEvents
            ->setParam('userId', $user->getId())
            ->setParam('sessionId', $session->getId())
            ->setPayload($response->output($session, Response::MODEL_SESSION));

        return $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($session, Response::MODEL_SESSION);
    });

App::post('/v1/users/:userId/tokens')
    ->desc('Create token')
    ->groups(['api', 'users'])
    ->label('event', 'users.[userId].tokens.[tokenId].create')
    ->label('scope', 'users.write')
    ->label('audits.event', 'tokens.create')
    ->label('audits.resource', 'user/{request.userId}')
    ->label('sdk', new Method(
        namespace: 'users',
        group: 'sessions',
        name: 'createToken',
        description: '/docs/references/users/create-token.md',
        auth: [AuthType::ADMIN, AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_CREATED,
                model: Response::MODEL_TOKEN,
            )
        ]
    ))
    ->param('userId', '', new UID(), 'User ID.')
    ->param('length', 6, new Range(4, 128), 'Token length in characters. The default length is 6 characters', true)
    ->param('expire', TOKEN_EXPIRATION_GENERIC, new Range(60, TOKEN_EXPIRATION_LOGIN_LONG), 'Token expiration period in seconds. The default expiration is 15 minutes.', true)
    ->inject('request')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForEvents')
    ->action(function (string $userId, int $length, int $expire, Request $request, Response $response, Database $dbForProject, Event $queueForEvents) {
        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        $proofForToken = new Token($length);
        $proofForToken->setHash(new Sha());
        $secret = $proofForToken->generate();
        $expire = DateTime::formatTz(DateTime::addSeconds(new \DateTime(), $expire));

        $token = new Document([
            '$id' => ID::unique(),
            'userId' => $user->getId(),
            'userInternalId' => $user->getSequence(),
            'type' => TOKEN_TYPE_GENERIC,
            'secret' => $proofForToken->hash($secret),
            'expire' => $expire,
            'userAgent' => $request->getUserAgent('UNKNOWN'),
            'ip' => $request->getIP()
        ]);

        $token = $dbForProject->createDocument('tokens', $token);
        $dbForProject->purgeCachedDocument('users', $user->getId());

        $token->setAttribute('secret', $secret);

        $queueForEvents
            ->setParam('userId', $user->getId())
            ->setParam('tokenId', $token->getId())
            ->setPayload($response->output($token, Response::MODEL_TOKEN));

        return $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($token, Response::MODEL_TOKEN);
    });

App::delete('/v1/users/:userId/sessions/:sessionId')
    ->desc('Delete user session')
    ->groups(['api', 'users'])
    ->label('event', 'users.[userId].sessions.[sessionId].delete')
    ->label('scope', 'users.write')
    ->label('audits.event', 'session.delete')
    ->label('audits.resource', 'user/{request.userId}')
    ->label('sdk', new Method(
        namespace: 'users',
        group: 'sessions',
        name: 'deleteSession',
        description: '/docs/references/users/delete-user-session.md',
        auth: [AuthType::ADMIN, AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_NOCONTENT,
                model: Response::MODEL_NONE,
            )
        ],
        contentType: ContentType::NONE
    ))
    ->param('userId', '', new UID(), 'User ID.')
    ->param('sessionId', '', new UID(), 'Session ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForEvents')
    ->action(function (string $userId, string $sessionId, Response $response, Database $dbForProject, Event $queueForEvents) {

        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        $session = $dbForProject->getDocument('sessions', $sessionId);

        if ($session->isEmpty()) {
            throw new Exception(Exception::USER_SESSION_NOT_FOUND);
        }

        $dbForProject->deleteDocument('sessions', $session->getId());
        $dbForProject->purgeCachedDocument('users', $user->getId());

        $queueForEvents
            ->setParam('userId', $user->getId())
            ->setParam('sessionId', $sessionId)
            ->setPayload($response->output($session, Response::MODEL_SESSION));

        $response->noContent();
    });

App::delete('/v1/users/:userId/sessions')
    ->desc('Delete user sessions')
    ->groups(['api', 'users'])
    ->label('event', 'users.[userId].sessions.delete')
    ->label('scope', 'users.write')
    ->label('audits.event', 'session.delete')
    ->label('audits.resource', 'user/{user.$id}')
    ->label('sdk', new Method(
        namespace: 'users',
        group: 'sessions',
        name: 'deleteSessions',
        description: '/docs/references/users/delete-user-sessions.md',
        auth: [AuthType::ADMIN, AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_NOCONTENT,
                model: Response::MODEL_NONE,
            )
        ],
        contentType: ContentType::NONE
    ))
    ->param('userId', '', new UID(), 'User ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForEvents')
    ->action(function (string $userId, Response $response, Database $dbForProject, Event $queueForEvents) {

        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        $sessions = $user->getAttribute('sessions', []);

        foreach ($sessions as $key => $session) {
            /** @var Document $session */
            $dbForProject->deleteDocument('sessions', $session->getId());
            //TODO: fix this
        }

        $dbForProject->purgeCachedDocument('users', $user->getId());

        $queueForEvents
            ->setParam('userId', $user->getId())
            ->setPayload($response->output($user, Response::MODEL_USER));

        $response->noContent();
    });

App::delete('/v1/users/:userId')
    ->desc('Delete user')
    ->groups(['api', 'users'])
    ->label('event', 'users.[userId].delete')
    ->label('scope', 'users.write')
    ->label('audits.event', 'user.delete')
    ->label('audits.resource', 'user/{request.userId}')
    ->label('sdk', new Method(
        namespace: 'users',
        group: 'users',
        name: 'delete',
        description: '/docs/references/users/delete.md',
        auth: [AuthType::ADMIN, AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_NOCONTENT,
                model: Response::MODEL_NONE,
            )
        ],
        contentType: ContentType::NONE
    ))
    ->param('userId', '', new UID(), 'User ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForEvents')
    ->inject('queueForDeletes')
    ->action(function (string $userId, Response $response, Database $dbForProject, Event $queueForEvents, Delete $queueForDeletes) {

        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        // clone user object to send to workers
        $clone = clone $user;

        $dbForProject->deleteDocument('users', $userId);
        DeleteIdentities::delete($dbForProject, Query::equal('userInternalId', [$user->getSequence()]));
        DeleteTargets::delete($dbForProject, Query::equal('userInternalId', [$user->getSequence()]));

        $queueForDeletes
            ->setType(DELETE_TYPE_DOCUMENT)
            ->setDocument($clone);

        $queueForEvents
            ->setParam('userId', $user->getId())
            ->setPayload($response->output($clone, Response::MODEL_USER));

        $response->noContent();
    });

App::delete('/v1/users/:userId/targets/:targetId')
    ->desc('Delete user target')
    ->groups(['api', 'users'])
    ->label('audits.event', 'target.delete')
    ->label('audits.resource', 'target/{request.$targetId}')
    ->label('event', 'users.[userId].targets.[targetId].delete')
    ->label('scope', 'targets.write')
    ->label('sdk', new Method(
        namespace: 'users',
        group: 'targets',
        name: 'deleteTarget',
        description: '/docs/references/users/delete-target.md',
        auth: [AuthType::KEY, AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_NOCONTENT,
                model: Response::MODEL_NONE,
            )
        ],
        contentType: ContentType::NONE
    ))
    ->param('userId', '', new UID(), 'User ID.')
    ->param('targetId', '', new UID(), 'Target ID.')
    ->inject('queueForEvents')
    ->inject('queueForDeletes')
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (string $userId, string $targetId, Event $queueForEvents, Delete $queueForDeletes, Response $response, Database $dbForProject) {
        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        $target = $dbForProject->getDocument('targets', $targetId);

        if ($target->isEmpty()) {
            throw new Exception(Exception::USER_TARGET_NOT_FOUND);
        }

        if ($user->getId() !== $target->getAttribute('userId')) {
            throw new Exception(Exception::USER_TARGET_NOT_FOUND);
        }

        $dbForProject->deleteDocument('targets', $target->getId());
        $dbForProject->purgeCachedDocument('users', $user->getId());

        $queueForDeletes
            ->setType(DELETE_TYPE_TARGET)
            ->setDocument($target);

        $queueForEvents
            ->setParam('userId', $user->getId())
            ->setParam('targetId', $target->getId());

        $response->noContent();
    });

App::delete('/v1/users/identities/:identityId')
    ->desc('Delete identity')
    ->groups(['api', 'users'])
    ->label('event', 'users.[userId].identities.[identityId].delete')
    ->label('scope', 'users.write')
    ->label('audits.event', 'identity.delete')
    ->label('audits.resource', 'identity/{request.$identityId}')
    ->label('sdk', new Method(
        namespace: 'users',
        group: 'identities',
        name: 'deleteIdentity',
        description: '/docs/references/users/delete-identity.md',
        auth: [AuthType::ADMIN, AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_NOCONTENT,
                model: Response::MODEL_NONE,
            )
        ],
        contentType: ContentType::NONE,
    ))
    ->param('identityId', '', new UID(), 'Identity ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForEvents')
    ->action(function (string $identityId, Response $response, Database $dbForProject, Event $queueForEvents) {

        $identity = $dbForProject->getDocument('identities', $identityId);

        if ($identity->isEmpty()) {
            throw new Exception(Exception::USER_IDENTITY_NOT_FOUND);
        }

        $dbForProject->deleteDocument('identities', $identityId);

        $queueForEvents
            ->setParam('userId', $identity->getAttribute('userId'))
            ->setParam('identityId', $identity->getId())
            ->setPayload($response->output($identity, Response::MODEL_IDENTITY));

        return $response->noContent();
    });

App::post('/v1/users/:userId/jwts')
    ->desc('Create user JWT')
    ->groups(['api', 'users'])
    ->label('scope', 'users.write')
    ->label('sdk', new Method(
        namespace: 'users',
        group: 'sessions',
        name: 'createJWT',
        description: '/docs/references/users/create-user-jwt.md',
        auth: [AuthType::ADMIN, AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_CREATED,
                model: Response::MODEL_JWT,
            )
        ]
    ))
    ->param('userId', '', new UID(), 'User ID.')
    ->param('sessionId', '', new UID(), 'Session ID. Use the string \'recent\' to use the most recent session. Defaults to the most recent session.', true)
    ->param('duration', 900, new Range(0, 3600), 'Time in seconds before JWT expires. Default duration is 900 seconds, and maximum is 3600 seconds.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (string $userId, string $sessionId, int $duration, Response $response, Database $dbForProject) {

        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        $sessions = $user->getAttribute('sessions', []);
        $session = new Document();

        if ($sessionId === 'recent') {
            // Get most recent
            $session = \count($sessions) > 0 ? $sessions[\count($sessions) - 1] : new Document();
        } else {
            // Find by ID
            foreach ($sessions as $loopSession) {
                /** @var Utopia\Database\Document $loopSession */
                if ($loopSession->getId() == $sessionId) {
                    $session = $loopSession;
                    break;
                }
            }
        }

        $jwt = new JWT(System::getEnv('_APP_OPENSSL_KEY_V1'), 'HS256', $duration, 0);

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic(new Document(['jwt' => $jwt->encode([
                'userId' => $user->getId(),
                'sessionId' => $session->isEmpty() ? '' : $session->getId()
            ])]), Response::MODEL_JWT);
    });

App::get('/v1/users/usage')
    ->desc('Get users usage stats')
    ->groups(['api', 'users'])
    ->label('scope', 'users.read')
    ->label('sdk', new Method(
        namespace: 'users',
        group: null,
        name: 'getUsage',
        description: '/docs/references/users/get-usage.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_USAGE_USERS,
            )
        ]
    ))
    ->param('range', '30d', new WhiteList(['24h', '30d', '90d'], true), 'Date range.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('authorization')
    ->action(function (string $range, Response $response, Database $dbForProject, Authorization $authorization) {

        $periods = Config::getParam('usage', []);
        $stats = $usage = [];
        $days = $periods[$range];
        $metrics = [
            METRIC_USERS,
            METRIC_SESSIONS,
        ];

        $authorization->skip(function () use ($dbForProject, $days, $metrics, &$stats) {
            foreach ($metrics as $count => $metric) {
                $result =  $dbForProject->findOne('stats', [
                    Query::equal('metric', [$metric]),
                    Query::equal('period', ['inf'])
                ]);

                $stats[$metric]['total'] = $result['value'] ?? 0;
                $limit = $days['limit'];
                $period = $days['period'];
                $results = $dbForProject->find('stats', [
                    Query::equal('metric', [$metric]),
                    Query::equal('period', [$period]),
                    Query::limit($limit),
                    Query::orderDesc('time'),
                ]);
                $stats[$metric]['data'] = [];
                foreach ($results as $result) {
                    $stats[$metric]['data'][$result->getAttribute('time')] = [
                        'value' => $result->getAttribute('value'),
                    ];
                }
            }
        });

        $format = match ($days['period']) {
            '1h' => 'Y-m-d\TH:00:00.000P',
            '1d' => 'Y-m-d\T00:00:00.000P',
        };

        foreach ($metrics as $metric) {
            $usage[$metric]['total'] =  $stats[$metric]['total'];
            $usage[$metric]['data'] = [];
            $leap = time() - ($days['limit'] * $days['factor']);
            while ($leap < time()) {
                $leap += $days['factor'];
                $formatDate = date($format, $leap);
                $usage[$metric]['data'][] = [
                    'value' => $stats[$metric]['data'][$formatDate]['value'] ?? 0,
                    'date' => $formatDate,
                ];
            }
        }

        $response->dynamic(new Document([
            'range' => $range,
            'usersTotal'   => $usage[$metrics[0]]['total'],
            'sessionsTotal' => $usage[$metrics[1]]['total'],
            'users'   => $usage[$metrics[0]]['data'],
            'sessions' => $usage[$metrics[1]]['data'],
        ]), Response::MODEL_USAGE_USERS);
    });

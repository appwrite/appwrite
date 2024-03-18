<?php

use Appwrite\Auth\Auth;
use Appwrite\Auth\MFA\Type;
use Appwrite\Auth\MFA\Type\TOTP;
use Appwrite\Auth\Validator\Password;
use Appwrite\Auth\Validator\PasswordDictionary;
use Appwrite\Auth\Validator\PasswordHistory;
use Appwrite\Auth\Validator\PersonalData;
use Appwrite\Auth\Validator\Phone;
use Appwrite\Detector\Detector;
use Appwrite\Event\Delete;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Hooks\Hooks;
use Appwrite\Network\Validator\Email;
use Appwrite\Utopia\Database\Validator\CustomId;
use Appwrite\Utopia\Database\Validator\Queries\Identities;
use Appwrite\Utopia\Database\Validator\Queries\Targets;
use Appwrite\Utopia\Database\Validator\Queries\Users;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use MaxMind\Db\Reader;
use Utopia\App;
use Utopia\Audit\Audit;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Queries;
use Utopia\Database\Validator\Query\Limit;
use Utopia\Database\Validator\Query\Offset;
use Utopia\Database\Validator\UID;
use Utopia\Locale\Locale;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Assoc;
use Utopia\Validator\Boolean;
use Utopia\Validator\Integer;
use Utopia\Validator\Range;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

/** TODO: Remove function when we move to using utopia/platform */
function createUser(string $hash, mixed $hashOptions, string $userId, ?string $email, ?string $password, ?string $phone, string $name, Document $project, Database $dbForProject, Event $queueForEvents, Hooks $hooks): Document
{
    $plaintextPassword = $password;
    $hashOptionsObject = (\is_string($hashOptions)) ? \json_decode($hashOptions, true) : $hashOptions; // Cast to JSON array
    $passwordHistory = $project->getAttribute('auths', [])['passwordHistory'] ?? 0;

    if (!empty($email)) {
        $email = \strtolower($email);

        // Makes sure this email is not already used in another identity
        $identityWithMatchingEmail = $dbForProject->findOne('identities', [
            Query::equal('providerEmail', [$email]),
        ]);
        if ($identityWithMatchingEmail !== false && !$identityWithMatchingEmail->isEmpty()) {
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

        $password = (!empty($password)) ? ($hash === 'plaintext' ? Auth::passwordHash($password, $hash, $hashOptionsObject) : $password) : null;
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
            'password' => $password,
            'passwordHistory' => is_null($password) || $passwordHistory === 0 ? [] : [$password],
            'passwordUpdate' => (!empty($password)) ? DateTime::now() : null,
            'hash' => $hash === 'plaintext' ? Auth::DEFAULT_ALGO : $hash,
            'hashOptions' => $hash === 'plaintext' ? Auth::DEFAULT_ALGO_OPTIONS : $hashOptionsObject + ['type' => $hash],
            'registration' => DateTime::now(),
            'reset' => false,
            'name' => $name,
            'prefs' => new \stdClass(),
            'sessions' => null,
            'tokens' => null,
            'memberships' => null,
            'search' => implode(' ', [$userId, $email, $phone, $name]),
        ]);

        if ($hash === 'plaintext') {
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
                    'userInternalId' => $user->getInternalId(),
                    'providerType' => 'email',
                    'identifier' => $email,
                ]));
                $user->setAttribute('targets', [...$user->getAttribute('targets', []), $target]);
            } catch (Duplicate) {
                $existingTarget = $dbForProject->findOne('targets', [
                    Query::equal('identifier', [$email]),
                ]);
                $user->setAttribute('targets', [...$user->getAttribute('targets', []), $existingTarget]);
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
                    'userInternalId' => $user->getInternalId(),
                    'providerType' => 'sms',
                    'identifier' => $phone,
                ]));
                $user->setAttribute('targets', [...$user->getAttribute('targets', []), $target]);
            } catch (Duplicate) {
                $existingTarget = $dbForProject->findOne('targets', [
                    Query::equal('identifier', [$phone]),
                ]);
                $user->setAttribute('targets', [...$user->getAttribute('targets', []), $existingTarget]);
            }
        }

        $dbForProject->purgeCachedDocument('users', $user->getId());
    } catch (Duplicate $th) {
        throw new Exception(Exception::USER_ALREADY_EXISTS);
    }

    $queueForEvents->setParam('userId', $user->getId());

    return $user;
}

App::post('/v1/users')
    ->desc('Create user')
    ->groups(['api', 'users'])
    ->label('event', 'users.[userId].create')
    ->label('scope', 'users.write')
    ->label('audits.event', 'user.create')
    ->label('audits.resource', 'user/{response.$id}')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'create')
    ->label('sdk.description', '/docs/references/users/create-user.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USER)
    ->param('userId', '', new CustomId(), 'User ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('email', null, new Email(), 'User email.', true)
    ->param('phone', null, new Phone(), 'Phone number. Format this number with a leading \'+\' and a country code, e.g., +16175551212.', true)
    ->param('password', '', fn ($project, $passwordsDictionary) => new PasswordDictionary($passwordsDictionary, $project->getAttribute('auths', [])['passwordDictionary'] ?? false), 'Plain text user password. Must be at least 8 chars.', true, ['project', 'passwordsDictionary'])
    ->param('name', '', new Text(128), 'User name. Max length: 128 chars.', true)
    ->inject('response')
    ->inject('project')
    ->inject('dbForProject')
    ->inject('queueForEvents')
    ->inject('hooks')
    ->action(function (string $userId, ?string $email, ?string $phone, ?string $password, string $name, Response $response, Document $project, Database $dbForProject, Event $queueForEvents, Hooks $hooks) {
        $user = createUser('plaintext', '{}', $userId, $email, $password, $phone, $name, $project, $dbForProject, $queueForEvents, $hooks);
        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($user, Response::MODEL_USER);
    });

App::post('/v1/users/bcrypt')
    ->desc('Create user with bcrypt password')
    ->groups(['api', 'users'])
    ->label('event', 'users.[userId].create')
    ->label('scope', 'users.write')
    ->label('audits.event', 'user.create')
    ->label('audits.resource', 'user/{response.$id}')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'createBcryptUser')
    ->label('sdk.description', '/docs/references/users/create-bcrypt-user.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USER)
    ->param('userId', '', new CustomId(), 'User ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('email', '', new Email(), 'User email.')
    ->param('password', '', new Password(), 'User password hashed using Bcrypt.')
    ->param('name', '', new Text(128), 'User name. Max length: 128 chars.', true)
    ->inject('response')
    ->inject('project')
    ->inject('dbForProject')
    ->inject('queueForEvents')
    ->inject('hooks')
    ->action(function (string $userId, string $email, string $password, string $name, Response $response, Document $project, Database $dbForProject, Event $queueForEvents, Hooks $hooks) {
        $user = createUser('bcrypt', '{}', $userId, $email, $password, null, $name, $project, $dbForProject, $queueForEvents, $hooks);

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($user, Response::MODEL_USER);
    });

App::post('/v1/users/md5')
    ->desc('Create user with MD5 password')
    ->groups(['api', 'users'])
    ->label('event', 'users.[userId].create')
    ->label('scope', 'users.write')
    ->label('audits.event', 'user.create')
    ->label('audits.resource', 'user/{response.$id}')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'createMD5User')
    ->label('sdk.description', '/docs/references/users/create-md5-user.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USER)
    ->param('userId', '', new CustomId(), 'User ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('email', '', new Email(), 'User email.')
    ->param('password', '', new Password(), 'User password hashed using MD5.')
    ->param('name', '', new Text(128), 'User name. Max length: 128 chars.', true)
    ->inject('response')
    ->inject('project')
    ->inject('dbForProject')
    ->inject('queueForEvents')
    ->inject('hooks')
    ->action(function (string $userId, string $email, string $password, string $name, Response $response, Document $project, Database $dbForProject, Event $queueForEvents, Hooks $hooks) {
        $user = createUser('md5', '{}', $userId, $email, $password, null, $name, $project, $dbForProject, $queueForEvents, $hooks);

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($user, Response::MODEL_USER);
    });

App::post('/v1/users/argon2')
    ->desc('Create user with Argon2 password')
    ->groups(['api', 'users'])
    ->label('event', 'users.[userId].create')
    ->label('scope', 'users.write')
    ->label('audits.event', 'user.create')
    ->label('audits.resource', 'user/{response.$id}')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'createArgon2User')
    ->label('sdk.description', '/docs/references/users/create-argon2-user.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USER)
    ->param('userId', '', new CustomId(), 'User ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('email', '', new Email(), 'User email.')
    ->param('password', '', new Password(), 'User password hashed using Argon2.')
    ->param('name', '', new Text(128), 'User name. Max length: 128 chars.', true)
    ->inject('response')
    ->inject('project')
    ->inject('dbForProject')
    ->inject('queueForEvents')
    ->inject('hooks')
    ->action(function (string $userId, string $email, string $password, string $name, Response $response, Document $project, Database $dbForProject, Event $queueForEvents, Hooks $hooks) {
        $user = createUser('argon2', '{}', $userId, $email, $password, null, $name, $project, $dbForProject, $queueForEvents, $hooks);

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($user, Response::MODEL_USER);
    });

App::post('/v1/users/sha')
    ->desc('Create user with SHA password')
    ->groups(['api', 'users'])
    ->label('event', 'users.[userId].create')
    ->label('scope', 'users.write')
    ->label('audits.event', 'user.create')
    ->label('audits.resource', 'user/{response.$id}')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'createSHAUser')
    ->label('sdk.description', '/docs/references/users/create-sha-user.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USER)
    ->param('userId', '', new CustomId(), 'User ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('email', '', new Email(), 'User email.')
    ->param('password', '', new Password(), 'User password hashed using SHA.')
    ->param('passwordVersion', '', new WhiteList(['sha1', 'sha224', 'sha256', 'sha384', 'sha512/224', 'sha512/256', 'sha512', 'sha3-224', 'sha3-256', 'sha3-384', 'sha3-512']), "Optional SHA version used to hash password. Allowed values are: 'sha1', 'sha224', 'sha256', 'sha384', 'sha512/224', 'sha512/256', 'sha512', 'sha3-224', 'sha3-256', 'sha3-384', 'sha3-512'", true)
    ->param('name', '', new Text(128), 'User name. Max length: 128 chars.', true)
    ->inject('response')
    ->inject('project')
    ->inject('dbForProject')
    ->inject('queueForEvents')
    ->inject('hooks')
    ->action(function (string $userId, string $email, string $password, string $passwordVersion, string $name, Response $response, Document $project, Database $dbForProject, Event $queueForEvents, Hooks $hooks) {
        $options = '{}';

        if (!empty($passwordVersion)) {
            $options = '{"version":"' . $passwordVersion . '"}';
        }

        $user = createUser('sha', $options, $userId, $email, $password, null, $name, $project, $dbForProject, $queueForEvents, $hooks);

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($user, Response::MODEL_USER);
    });

App::post('/v1/users/phpass')
    ->desc('Create user with PHPass password')
    ->groups(['api', 'users'])
    ->label('event', 'users.[userId].create')
    ->label('scope', 'users.write')
    ->label('audits.event', 'user.create')
    ->label('audits.resource', 'user/{response.$id}')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'createPHPassUser')
    ->label('sdk.description', '/docs/references/users/create-phpass-user.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USER)
    ->param('userId', '', new CustomId(), 'User ID. Choose a custom ID or pass the string `ID.unique()`to auto generate it. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('email', '', new Email(), 'User email.')
    ->param('password', '', new Password(), 'User password hashed using PHPass.')
    ->param('name', '', new Text(128), 'User name. Max length: 128 chars.', true)
    ->inject('response')
    ->inject('project')
    ->inject('dbForProject')
    ->inject('queueForEvents')
    ->inject('hooks')
    ->action(function (string $userId, string $email, string $password, string $name, Response $response, Document $project, Database $dbForProject, Event $queueForEvents, Hooks $hooks) {
        $user = createUser('phpass', '{}', $userId, $email, $password, null, $name, $project, $dbForProject, $queueForEvents, $hooks);

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($user, Response::MODEL_USER);
    });

App::post('/v1/users/scrypt')
    ->desc('Create user with Scrypt password')
    ->groups(['api', 'users'])
    ->label('event', 'users.[userId].create')
    ->label('scope', 'users.write')
    ->label('audits.event', 'user.create')
    ->label('audits.resource', 'user/{response.$id}')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'createScryptUser')
    ->label('sdk.description', '/docs/references/users/create-scrypt-user.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USER)
    ->param('userId', '', new CustomId(), 'User ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('email', '', new Email(), 'User email.')
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
    ->inject('queueForEvents')
    ->inject('hooks')
    ->action(function (string $userId, string $email, string $password, string $passwordSalt, int $passwordCpu, int $passwordMemory, int $passwordParallel, int $passwordLength, string $name, Response $response, Document $project, Database $dbForProject, Event $queueForEvents, Hooks $hooks) {
        $options = [
            'salt' => $passwordSalt,
            'costCpu' => $passwordCpu,
            'costMemory' => $passwordMemory,
            'costParallel' => $passwordParallel,
            'length' => $passwordLength
        ];

        $user = createUser('scrypt', \json_encode($options), $userId, $email, $password, null, $name, $project, $dbForProject, $queueForEvents, $hooks);

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($user, Response::MODEL_USER);
    });

App::post('/v1/users/scrypt-modified')
    ->desc('Create user with Scrypt modified password')
    ->groups(['api', 'users'])
    ->label('event', 'users.[userId].create')
    ->label('scope', 'users.write')
    ->label('audits.event', 'user.create')
    ->label('audits.resource', 'user/{response.$id}')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'createScryptModifiedUser')
    ->label('sdk.description', '/docs/references/users/create-scrypt-modified-user.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USER)
    ->param('userId', '', new CustomId(), 'User ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('email', '', new Email(), 'User email.')
    ->param('password', '', new Password(), 'User password hashed using Scrypt Modified.')
    ->param('passwordSalt', '', new Text(128), 'Salt used to hash password.')
    ->param('passwordSaltSeparator', '', new Text(128), 'Salt separator used to hash password.')
    ->param('passwordSignerKey', '', new Text(128), 'Signer key used to hash password.')
    ->param('name', '', new Text(128), 'User name. Max length: 128 chars.', true)
    ->inject('response')
    ->inject('project')
    ->inject('dbForProject')
    ->inject('queueForEvents')
    ->inject('hooks')
    ->action(function (string $userId, string $email, string $password, string $passwordSalt, string $passwordSaltSeparator, string $passwordSignerKey, string $name, Response $response, Document $project, Database $dbForProject, Event $queueForEvents, Hooks $hooks) {
        $user = createUser('scryptMod', '{"signerKey":"' . $passwordSignerKey . '","saltSeparator":"' . $passwordSaltSeparator . '","salt":"' . $passwordSalt . '"}', $userId, $email, $password, null, $name, $project, $dbForProject, $queueForEvents, $hooks);

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($user, Response::MODEL_USER);
    });

App::post('/v1/users/:userId/targets')
    ->desc('Create User Target')
    ->groups(['api', 'users'])
    ->label('audits.event', 'target.create')
    ->label('audits.resource', 'target/response.$id')
    ->label('event', 'users.[userId].targets.[targetId].create')
    ->label('scope', 'targets.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'createTarget')
    ->label('sdk.description', '/docs/references/users/create-target.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_TARGET)
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
                $validator = new Email();
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
                'providerInternalId' => $provider->isEmpty() ? null : $provider->getInternalId(),
                'providerType' =>  $providerType,
                'userId' => $userId,
                'userInternalId' => $user->getInternalId(),
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
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'list')
    ->label('sdk.description', '/docs/references/users/list-users.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USER_LIST)
    ->param('queries', [], new Users(), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long. You may filter on the following attributes: ' . implode(', ', Users::ALLOWED_ATTRIBUTES), true)
    ->param('search', '', new Text(256), 'Search term to filter your list results. Max length: 256 chars.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (array $queries, string $search, Response $response, Database $dbForProject) {

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
            $userId = $cursor->getValue();
            $cursorDocument = $dbForProject->getDocument('users', $userId);

            if ($cursorDocument->isEmpty()) {
                throw new Exception(Exception::GENERAL_CURSOR_NOT_FOUND, "User '{$userId}' for the 'cursor' value not found.");
            }

            $cursor->setValue($cursorDocument);
        }

        $filterQueries = Query::groupByType($queries)['filters'];

        $response->dynamic(new Document([
            'users' => $dbForProject->find('users', $queries),
            'total' => $dbForProject->count('users', $filterQueries, APP_LIMIT_COUNT),
        ]), Response::MODEL_USER_LIST);
    });

App::get('/v1/users/:userId')
    ->desc('Get user')
    ->groups(['api', 'users'])
    ->label('scope', 'users.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'get')
    ->label('sdk.description', '/docs/references/users/get-user.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USER)
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
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'getPrefs')
    ->label('sdk.description', '/docs/references/users/get-user-prefs.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PREFERENCES)
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
    ->desc('Get User Target')
    ->groups(['api', 'users'])
    ->label('scope', 'targets.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'getTarget')
    ->label('sdk.description', '/docs/references/users/get-user-target.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_TARGET)
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
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'listSessions')
    ->label('sdk.description', '/docs/references/users/list-user-sessions.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_SESSION_LIST)
    ->param('userId', '', new UID(), 'User ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('locale')
    ->action(function (string $userId, Response $response, Database $dbForProject, Locale $locale) {

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
            'total' => count($sessions),
        ]), Response::MODEL_SESSION_LIST);
    });

App::get('/v1/users/:userId/memberships')
    ->desc('List user memberships')
    ->groups(['api', 'users'])
    ->label('scope', 'users.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'listMemberships')
    ->label('sdk.description', '/docs/references/users/list-user-memberships.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_MEMBERSHIP_LIST)
    ->param('userId', '', new UID(), 'User ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (string $userId, Response $response, Database $dbForProject) {

        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        $memberships = array_map(function ($membership) use ($dbForProject, $user) {
            $team = $dbForProject->getDocument('teams', $membership->getAttribute('teamId'));

            $membership
                ->setAttribute('teamName', $team->getAttribute('name'))
                ->setAttribute('userName', $user->getAttribute('name'))
                ->setAttribute('userEmail', $user->getAttribute('email'));

            return $membership;
        }, $user->getAttribute('memberships', []));

        $response->dynamic(new Document([
            'memberships' => $memberships,
            'total' => count($memberships),
        ]), Response::MODEL_MEMBERSHIP_LIST);
    });

App::get('/v1/users/:userId/logs')
    ->desc('List user logs')
    ->groups(['api', 'users'])
    ->label('scope', 'users.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'listLogs')
    ->label('sdk.description', '/docs/references/users/list-user-logs.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_LOG_LIST)
    ->param('userId', '', new UID(), 'User ID.')
    ->param('queries', [], new Queries([new Limit(), new Offset()]), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Only supported methods are limit and offset', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('locale')
    ->inject('geodb')
    ->action(function (string $userId, array $queries, Response $response, Database $dbForProject, Locale $locale, Reader $geodb) {

        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        try {
            $queries = Query::parseQueries($queries);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        $grouped = Query::groupByType($queries);
        $limit = $grouped['limit'] ?? APP_LIMIT_COUNT;
        $offset = $grouped['offset'] ?? 0;

        $audit = new Audit($dbForProject);

        $logs = $audit->getLogsByUser($user->getInternalId(), $limit, $offset);

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
            'total' => $audit->countLogsByUser($user->getInternalId()),
            'logs' => $output,
        ]), Response::MODEL_LOG_LIST);
    });

App::get('/v1/users/:userId/targets')
    ->desc('List User Targets')
    ->groups(['api', 'users'])
    ->label('scope', 'targets.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'listTargets')
    ->label('sdk.description', '/docs/references/users/list-user-targets.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_TARGET_LIST)
    ->param('userId', '', new UID(), 'User ID.')
    ->param('queries', [], new Targets(), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long. You may filter on the following attributes: ' . implode(', ', Users::ALLOWED_ATTRIBUTES), true)
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (string $userId, array $queries, Response $response, Database $dbForProject) {
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
            $targetId = $cursor->getValue();
            $cursorDocument = $dbForProject->getDocument('targets', $targetId);

            if ($cursorDocument->isEmpty()) {
                throw new Exception(Exception::GENERAL_CURSOR_NOT_FOUND, "Target '{$targetId}' for the 'cursor' value not found.");
            }

            $cursor->setValue($cursorDocument);
        }

        $response->dynamic(new Document([
            'targets' => $dbForProject->find('targets', $queries),
            'total' => $dbForProject->count('targets', $queries, APP_LIMIT_COUNT),
        ]), Response::MODEL_TARGET_LIST);
    });

App::get('/v1/users/identities')
    ->desc('List Identities')
    ->groups(['api', 'users'])
    ->label('scope', 'users.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'listIdentities')
    ->label('sdk.description', '/docs/references/users/list-identities.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_IDENTITY_LIST)
    ->param('queries', [], new Identities(), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long. You may filter on the following attributes: ' . implode(', ', Identities::ALLOWED_ATTRIBUTES), true)
    ->param('search', '', new Text(256), 'Search term to filter your list results. Max length: 256 chars.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (array $queries, string $search, Response $response, Database $dbForProject) {

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
            $identityId = $cursor->getValue();
            $cursorDocument = $dbForProject->getDocument('identities', $identityId);

            if ($cursorDocument->isEmpty()) {
                throw new Exception(Exception::GENERAL_CURSOR_NOT_FOUND, "User '{$identityId}' for the 'cursor' value not found.");
            }

            $cursor->setValue($cursorDocument);
        }

        $filterQueries = Query::groupByType($queries)['filters'];

        $response->dynamic(new Document([
            'identities' => $dbForProject->find('identities', $queries),
            'total' => $dbForProject->count('identities', $filterQueries, APP_LIMIT_COUNT),
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
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'updateStatus')
    ->label('sdk.description', '/docs/references/users/update-user-status.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USER)
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
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'updateLabels')
    ->label('sdk.description', '/docs/references/users/update-user-labels.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USER)
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
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'updatePhoneVerification')
    ->label('sdk.description', '/docs/references/users/update-user-phone-verification.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USER)
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
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'updateName')
    ->label('sdk.description', '/docs/references/users/update-user-name.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USER)
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
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'updatePassword')
    ->label('sdk.description', '/docs/references/users/update-user-password.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USER)
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

        $newPassword = Auth::passwordHash($password, Auth::DEFAULT_ALGO, Auth::DEFAULT_ALGO_OPTIONS);

        $historyLimit = $project->getAttribute('auths', [])['passwordHistory'] ?? 0;
        $history = $user->getAttribute('passwordHistory', []);
        if ($historyLimit > 0) {
            $validator = new PasswordHistory($history, $user->getAttribute('hash'), $user->getAttribute('hashOptions'));
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
            ->setAttribute('hash', Auth::DEFAULT_ALGO)
            ->setAttribute('hashOptions', Auth::DEFAULT_ALGO_OPTIONS);

        $user = $dbForProject->updateDocument('users', $user->getId(), $user);

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
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'updateEmail')
    ->label('sdk.description', '/docs/references/users/update-user-email.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USER)
    ->param('userId', '', new UID(), 'User ID.')
    ->param('email', '', new Email(allowEmpty: true), 'User email.')
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
                Query::notEqual('userInternalId', $user->getInternalId()),
            ]);
            if ($identityWithMatchingEmail !== false && !$identityWithMatchingEmail->isEmpty()) {
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

        $user
            ->setAttribute('email', $email)
            ->setAttribute('emailVerification', false)
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
                        'userInternalId' => $user->getInternalId(),
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
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'updatePhone')
    ->label('sdk.description', '/docs/references/users/update-user-phone.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USER)
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
                        'userInternalId' => $user->getInternalId(),
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
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'updateEmailVerification')
    ->label('sdk.description', '/docs/references/users/update-user-email-verification.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USER)
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
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'updatePrefs')
    ->label('sdk.description', '/docs/references/users/update-user-prefs.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USER)
    ->param('userId', '', new UID(), 'User ID.')
    ->param('prefs', [], new Assoc(), 'Prefs key-value JSON object.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForEvents')
    ->action(function (string $userId, array $prefs, Response $response, Database $dbForProject, Event $queueForEvents) {

        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        $user->setAttribute('prefs', $prefs);

        $user = $dbForProject->updateDocument('users', $user->getId(), $user);

        $queueForEvents->setParam('userId', $user->getId());

        $response->dynamic($user, Response::MODEL_USER);
    });

App::patch('/v1/users/:userId/targets/:targetId')
    ->desc('Update User target')
    ->groups(['api', 'users'])
    ->label('audits.event', 'target.update')
    ->label('audits.resource', 'target/{response.$id}')
    ->label('event', 'users.[userId].targets.[targetId].update')
    ->label('scope', 'targets.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'updateTarget')
    ->label('sdk.description', '/docs/references/users/update-target.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_TARGET)
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
                    $validator = new Email();
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

            $target->setAttribute('identifier', $identifier);
        }

        if ($providerId) {
            $provider = $dbForProject->getDocument('providers', $providerId);

            if ($provider->isEmpty()) {
                throw new Exception(Exception::PROVIDER_NOT_FOUND);
            }

            if ($provider->getAttribute('type') !== $target->getAttribute('providerType')) {
                throw new Exception(Exception::PROVIDER_INCORRECT_TYPE);
            }

            $target->setAttribute('providerId', $provider->getId());
            $target->setAttribute('providerInternalId', $provider->getInternalId());
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
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'updateMfa')
    ->label('sdk.description', '/docs/references/users/update-user-mfa.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USER)
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
    ->desc('List Factors')
    ->groups(['api', 'users'])
    ->label('scope', 'users.read')
    ->label('usage.metric', 'users.{scope}.requests.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'listMfaFactors')
    ->label('sdk.description', '/docs/references/users/list-mfa-factors.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_MFA_FACTORS)
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
    ->desc('Get MFA Recovery Codes')
    ->groups(['api', 'users'])
    ->label('scope', 'users.read')
    ->label('usage.metric', 'users.{scope}.requests.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'getMfaRecoveryCodes')
    ->label('sdk.description', '/docs/references/users/get-mfa-recovery-codes.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_MFA_RECOVERY_CODES)
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
    ->desc('Create MFA Recovery Codes')
    ->groups(['api', 'users'])
    ->label('event', 'users.[userId].create.mfa.recovery-codes')
    ->label('scope', 'users.write')
    ->label('audits.event', 'user.update')
    ->label('audits.resource', 'user/{response.$id}')
    ->label('audits.userId', '{response.$id}')
    ->label('usage.metric', 'users.{scope}.requests.update')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'createMfaRecoveryCodes')
    ->label('sdk.description', '/docs/references/users/create-mfa-recovery-codes.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_MFA_RECOVERY_CODES)
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
    ->desc('Regenerate MFA Recovery Codes')
    ->groups(['api', 'users'])
    ->label('event', 'users.[userId].update.mfa.recovery-codes')
    ->label('scope', 'users.write')
    ->label('audits.event', 'user.update')
    ->label('audits.resource', 'user/{response.$id}')
    ->label('audits.userId', '{response.$id}')
    ->label('usage.metric', 'users.{scope}.requests.update')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'updateMfaRecoveryCodes')
    ->label('sdk.description', '/docs/references/users/update-mfa-recovery-codes.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_MFA_RECOVERY_CODES)
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
    ->desc('Delete Authenticator')
    ->groups(['api', 'users'])
    ->label('event', 'users.[userId].delete.mfa')
    ->label('scope', 'users.write')
    ->label('audits.event', 'user.update')
    ->label('audits.resource', 'user/{response.$id}')
    ->label('audits.userId', '{response.$id}')
    ->label('usage.metric', 'users.{scope}.requests.update')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'deleteMfaAuthenticator')
    ->label('sdk.description', '/docs/references/users/delete-mfa-authenticator.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USER)
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
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'createSession')
    ->label('sdk.description', '/docs/references/users/create-session.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_SESSION)
    ->param('userId', '', new CustomId(), 'User ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->inject('request')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('project')
    ->inject('locale')
    ->inject('geodb')
    ->inject('queueForEvents')
    ->action(function (string $userId, Request $request, Response $response, Database $dbForProject, Document $project, Locale $locale, Reader $geodb, Event $queueForEvents) {
        $user = $dbForProject->getDocument('users', $userId);
        if ($user->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        $secret = Auth::codeGenerator();
        $detector = new Detector($request->getUserAgent('UNKNOWN'));
        $record = $geodb->get($request->getIP());

        $duration = $project->getAttribute('auths', [])['duration'] ?? Auth::TOKEN_EXPIRATION_LOGIN_LONG;
        $expire = DateTime::formatTz(DateTime::addSeconds(new \DateTime(), $duration));

        $session = new Document(array_merge(
            [
                '$id' => ID::unique(),
                'userId' => $user->getId(),
                'userInternalId' => $user->getInternalId(),
                'provider' => Auth::SESSION_PROVIDER_SERVER,
                'secret' => Auth::hash($secret), // One way hash encryption to protect DB leak
                'userAgent' => $request->getUserAgent('UNKNOWN'),
                'ip' => $request->getIP(),
                'countryCode' => ($record) ? \strtolower($record['country']['iso_code']) : '--',
            ],
            $detector->getOS(),
            $detector->getClient(),
            $detector->getDevice()
        ));

        $countryName = $locale->getText('countries.' . strtolower($session->getAttribute('countryCode')), $locale->getText('locale.country.unknown'));

        $session = $dbForProject->createDocument('sessions', $session);
        $session
            ->setAttribute('secret', $secret)
            ->setAttribute('expire', $expire)
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
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'createToken')
    ->label('sdk.description', '/docs/references/users/create-token.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_TOKEN)
    ->param('userId', '', new UID(), 'User ID.')
    ->param('length', 6, new Range(4, 128), 'Token length in characters. The default length is 6 characters', true)
    ->param('expire', Auth::TOKEN_EXPIRATION_GENERIC, new Range(60, Auth::TOKEN_EXPIRATION_LOGIN_LONG), 'Token expiration period in seconds. The default expiration is 15 minutes.', true)
    ->inject('request')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForEvents')
    ->action(function (string $userId, int $length, int $expire, Request $request, Response $response, Database $dbForProject, Event $queueForEvents) {
        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        $secret = Auth::tokenGenerator($length);
        $expire = DateTime::formatTz(DateTime::addSeconds(new \DateTime(), $expire));

        $token = new Document([
            '$id' => ID::unique(),
            'userId' => $user->getId(),
            'userInternalId' => $user->getInternalId(),
            'type' => Auth::TOKEN_TYPE_GENERIC,
            'secret' => Auth::hash($secret),
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
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'deleteSession')
    ->label('sdk.description', '/docs/references/users/delete-user-session.md')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
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
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'deleteSessions')
    ->label('sdk.description', '/docs/references/users/delete-user-sessions.md')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
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
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'delete')
    ->label('sdk.description', '/docs/references/users/delete.md')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
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
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'deleteTarget')
    ->label('sdk.description', '/docs/references/users/delete-target.md')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_NONE)
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
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'deleteIdentity')
    ->label('sdk.description', '/docs/references/users/delete-identity.md')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
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

App::get('/v1/users/usage')
    ->desc('Get users usage stats')
    ->groups(['api', 'users'])
    ->label('scope', 'users.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'getUsage')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USAGE_USERS)
    ->param('range', '30d', new WhiteList(['24h', '30d', '90d'], true), 'Date range.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('register')
    ->action(function (string $range, Response $response, Database $dbForProject) {

        $periods = Config::getParam('usage', []);
        $stats = $usage = [];
        $days = $periods[$range];
        $metrics = [
            METRIC_USERS,
            METRIC_SESSIONS,
        ];

        Authorization::skip(function () use ($dbForProject, $days, $metrics, &$stats) {
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

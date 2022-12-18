<?php

use Appwrite\Auth\Auth;
use Appwrite\Auth\Validator\Password;
use Appwrite\Auth\Validator\Phone;
use Appwrite\Detector\Detector;
use Appwrite\Event\Delete;
use Appwrite\Event\Event;
use Appwrite\Network\Validator\Email;
use Appwrite\Utopia\Database\Validator\CustomId;
use Appwrite\Utopia\Database\Validator\Queries;
use Appwrite\Utopia\Database\Validator\Queries\Users;
use Appwrite\Utopia\Database\Validator\Query\Limit;
use Appwrite\Utopia\Database\Validator\Query\Offset;
use Appwrite\Utopia\Response;
use Utopia\App;
use Utopia\Audit\Audit;
use Utopia\Config\Config;
use Utopia\Database\ID;
use Utopia\Database\Permission;
use Utopia\Database\Role;
use Utopia\Locale\Locale;
use Appwrite\Extend\Exception;
use Utopia\Database\Document;
use Utopia\Database\DateTime;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Validator\UID;
use Utopia\Database\Database;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Validator\Assoc;
use Utopia\Validator\WhiteList;
use Utopia\Validator\Text;
use Utopia\Validator\Boolean;
use MaxMind\Db\Reader;
use Utopia\Validator\Integer;

/** TODO: Remove function when we move to using utopia/platform */
function createUser(string $hash, mixed $hashOptions, string $userId, ?string $email, ?string $password, ?string $phone, string $name, Document $project, Database $dbForProject, Event $events): Document
{
    $hashOptionsObject = (\is_string($hashOptions)) ? \json_decode($hashOptions, true) : $hashOptions; // Cast to JSON array
    $passwordHistory = $project->getAttribute('auths', [])['passwordHistory'] ?? 0;

    if (!empty($email)) {
        $email = \strtolower($email);
    }

    try {
        $userId = $userId == 'unique()'
            ? ID::unique()
            : ID::custom($userId);

        $password = (!empty($password)) ? ($hash === 'plaintext' ? Auth::passwordHash($password, $hash, $hashOptionsObject) : $password) : null;
        $user = $dbForProject->createDocument('users', new Document([
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
            'passwordHistory' => is_null($password) && $passwordHistory === 0 ? [] : [$password],
            'password' => $password,
            'hash' => $hash === 'plaintext' ? Auth::DEFAULT_ALGO : $hash,
            'hashOptions' => $hash === 'plaintext' ? Auth::DEFAULT_ALGO_OPTIONS : $hashOptions,
            'passwordUpdate' => (!empty($password)) ? DateTime::now() : null,
            'registration' => DateTime::now(),
            'reset' => false,
            'name' => $name,
            'prefs' => new \stdClass(),
            'sessions' => null,
            'tokens' => null,
            'memberships' => null,
            'search' => implode(' ', [$userId, $email, $phone, $name])
        ]));
    } catch (Duplicate $th) {
        throw new Exception(Exception::USER_ALREADY_EXISTS);
    }

    $events->setParam('userId', $user->getId());

    return $user;
}

App::post('/v1/users')
    ->desc('Create User')
    ->groups(['api', 'users'])
    ->label('event', 'users.[userId].create')
    ->label('scope', 'users.write')
    ->label('audits.event', 'user.create')
    ->label('audits.resource', 'user/{response.$id}')
    ->label('usage.metric', 'users.{scope}.requests.create')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'create')
    ->label('sdk.description', '/docs/references/users/create-user.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USER)
    ->param('userId', '', new CustomId(), 'User ID. Choose your own unique ID or pass the string `ID.unique()` to auto generate it. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('email', null, new Email(), 'User email.', true)
    ->param('phone', null, new Phone(), 'Phone number. Format this number with a leading \'+\' and a country code, e.g., +16175551212.', true)
    ->param('password', null, new Password(), 'Plain text user password. Must be at least 8 chars.', true)
    ->param('name', '', new Text(128), 'User name. Max length: 128 chars.', true)
    ->inject('response')
    ->inject('project')
    ->inject('dbForProject')
    ->inject('events')
    ->action(function (string $userId, ?string $email, ?string $phone, ?string $password, string $name, Response $response, Document $project, Database $dbForProject, Event $events) {
        $user = createUser('plaintext', '{}', $userId, $email, $password, $phone, $name, $project, $dbForProject, $events);

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($user, Response::MODEL_USER);
    });

App::post('/v1/users/bcrypt')
    ->desc('Create User with Bcrypt Password')
    ->groups(['api', 'users'])
    ->label('event', 'users.[userId].create')
    ->label('scope', 'users.write')
    ->label('audits.event', 'user.create')
    ->label('audits.resource', 'user/{response.$id}')
    ->label('usage.metric', 'users.{scope}.requests.create')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'createBcryptUser')
    ->label('sdk.description', '/docs/references/users/create-bcrypt-user.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USER)
    ->param('userId', '', new CustomId(), 'User ID. Choose your own unique ID or pass the string `ID.unique()` to auto generate it. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('email', '', new Email(), 'User email.')
    ->param('password', '', new Password(), 'User password hashed using Bcrypt.')
    ->param('name', '', new Text(128), 'User name. Max length: 128 chars.', true)
    ->inject('response')
    ->inject('project')
    ->inject('dbForProject')
    ->inject('events')
    ->action(function (string $userId, string $email, string $password, string $name, Response $response, Document $project, Database $dbForProject, Event $events) {
        $user = createUser('bcrypt', '{}', $userId, $email, $password, null, $name, $project, $dbForProject, $events);

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($user, Response::MODEL_USER);
    });

App::post('/v1/users/md5')
    ->desc('Create User with MD5 Password')
    ->groups(['api', 'users'])
    ->label('event', 'users.[userId].create')
    ->label('scope', 'users.write')
    ->label('audits.event', 'user.create')
    ->label('audits.resource', 'user/{response.$id}')
    ->label('usage.metric', 'users.{scope}.requests.create')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'createMD5User')
    ->label('sdk.description', '/docs/references/users/create-md5-user.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USER)
    ->param('userId', '', new CustomId(), 'User ID. Choose your own unique ID or pass the string `ID.unique()` to auto generate it. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('email', '', new Email(), 'User email.')
    ->param('password', '', new Password(), 'User password hashed using MD5.')
    ->param('name', '', new Text(128), 'User name. Max length: 128 chars.', true)
    ->inject('response')
    ->inject('project')
    ->inject('dbForProject')
    ->inject('events')
    ->action(function (string $userId, string $email, string $password, string $name, Response $response, Document $project, Database $dbForProject, Event $events) {
        $user = createUser('md5', '{}', $userId, $email, $password, null, $name, $project, $dbForProject, $events);

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($user, Response::MODEL_USER);
    });

App::post('/v1/users/argon2')
    ->desc('Create User with Argon2 Password')
    ->groups(['api', 'users'])
    ->label('event', 'users.[userId].create')
    ->label('scope', 'users.write')
    ->label('audits.event', 'user.create')
    ->label('audits.resource', 'user/{response.$id}')
    ->label('usage.metric', 'users.{scope}.requests.create')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'createArgon2User')
    ->label('sdk.description', '/docs/references/users/create-argon2-user.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USER)
    ->param('userId', '', new CustomId(), 'User ID. Choose your own unique ID or pass the string `ID.unique()` to auto generate it. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('email', '', new Email(), 'User email.')
    ->param('password', '', new Password(), 'User password hashed using Argon2.')
    ->param('name', '', new Text(128), 'User name. Max length: 128 chars.', true)
    ->inject('response')
    ->inject('project')
    ->inject('dbForProject')
    ->inject('events')
    ->action(function (string $userId, string $email, string $password, string $name, Response $response, Document $project, Database $dbForProject, Event $events) {
        $user = createUser('argon2', '{}', $userId, $email, $password, null, $name, $project, $dbForProject, $events);

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($user, Response::MODEL_USER);
    });

App::post('/v1/users/sha')
    ->desc('Create User with SHA Password')
    ->groups(['api', 'users'])
    ->label('event', 'users.[userId].create')
    ->label('scope', 'users.write')
    ->label('audits.event', 'user.create')
    ->label('audits.resource', 'user/{response.$id}')
    ->label('usage.metric', 'users.{scope}.requests.create')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'createSHAUser')
    ->label('sdk.description', '/docs/references/users/create-sha-user.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USER)
    ->param('userId', '', new CustomId(), 'User ID. Choose your own unique ID or pass the string `ID.unique()` to auto generate it. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('email', '', new Email(), 'User email.')
    ->param('password', '', new Password(), 'User password hashed using SHA.')
    ->param('passwordVersion', '', new WhiteList(['sha1', 'sha224', 'sha256', 'sha384', 'sha512/224', 'sha512/256', 'sha512', 'sha3-224', 'sha3-256', 'sha3-384', 'sha3-512']), "Optional SHA version used to hash password. Allowed values are: 'sha1', 'sha224', 'sha256', 'sha384', 'sha512/224', 'sha512/256', 'sha512', 'sha3-224', 'sha3-256', 'sha3-384', 'sha3-512'", true)
    ->param('name', '', new Text(128), 'User name. Max length: 128 chars.', true)
    ->inject('response')
    ->inject('project')
    ->inject('dbForProject')
    ->inject('events')
    ->action(function (string $userId, string $email, string $password, string $passwordVersion, string $name, Response $response, Document $project, Database $dbForProject, Event $events) {
        $options = '{}';

        if (!empty($passwordVersion)) {
            $options = '{"version":"' . $passwordVersion . '"}';
        }

        $user = createUser('sha', $options, $userId, $email, $password, null, $name, $project, $dbForProject, $events);

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($user, Response::MODEL_USER);
    });

App::post('/v1/users/phpass')
    ->desc('Create User with PHPass Password')
    ->groups(['api', 'users'])
    ->label('event', 'users.[userId].create')
    ->label('scope', 'users.write')
    ->label('audits.event', 'user.create')
    ->label('audits.resource', 'user/{response.$id}')
    ->label('usage.metric', 'users.{scope}.requests.create')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'createPHPassUser')
    ->label('sdk.description', '/docs/references/users/create-phpass-user.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USER)
    ->param('userId', '', new CustomId(), 'User ID. Choose your own unique ID or pass the string `ID.unique()`to auto generate it. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('email', '', new Email(), 'User email.')
    ->param('password', '', new Password(), 'User password hashed using PHPass.')
    ->param('name', '', new Text(128), 'User name. Max length: 128 chars.', true)
    ->inject('response')
    ->inject('project')
    ->inject('dbForProject')
    ->inject('events')
    ->action(function (string $userId, string $email, string $password, string $name, Response $response, Document $project, Database $dbForProject, Event $events) {
        $user = createUser('phpass', '{}', $userId, $email, $password, null, $name, $project, $dbForProject, $events);

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($user, Response::MODEL_USER);
    });

App::post('/v1/users/scrypt')
    ->desc('Create User with Scrypt Password')
    ->groups(['api', 'users'])
    ->label('event', 'users.[userId].create')
    ->label('scope', 'users.write')
    ->label('audits.event', 'user.create')
    ->label('audits.resource', 'user/{response.$id}')
    ->label('usage.metric', 'users.{scope}.requests.create')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'createScryptUser')
    ->label('sdk.description', '/docs/references/users/create-scrypt-user.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USER)
    ->param('userId', '', new CustomId(), 'User ID. Choose your own unique ID or pass the string `ID.unique()` to auto generate it. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
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
    ->inject('events')
    ->action(function (string $userId, string $email, string $password, string $passwordSalt, int $passwordCpu, int $passwordMemory, int $passwordParallel, int $passwordLength, string $name, Response $response, Document $project, Database $dbForProject, Event $events) {
        $options = [
            'salt' => $passwordSalt,
            'costCpu' => $passwordCpu,
            'costMemory' => $passwordMemory,
            'costParallel' => $passwordParallel,
            'length' => $passwordLength
        ];

        $user = createUser('scrypt', \json_encode($options), $userId, $email, $password, null, $name, $project, $dbForProject, $events);

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($user, Response::MODEL_USER);
    });

App::post('/v1/users/scrypt-modified')
    ->desc('Create User with Scrypt Modified Password')
    ->groups(['api', 'users'])
    ->label('event', 'users.[userId].create')
    ->label('scope', 'users.write')
    ->label('audits.event', 'user.create')
    ->label('audits.resource', 'user/{response.$id}')
    ->label('usage.metric', 'users.{scope}.requests.create')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'createScryptModifiedUser')
    ->label('sdk.description', '/docs/references/users/create-scrypt-modified-user.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USER)
    ->param('userId', '', new CustomId(), 'User ID. Choose your own unique ID or pass the string `ID.unique()` to auto generate it. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('email', '', new Email(), 'User email.')
    ->param('password', '', new Password(), 'User password hashed using Scrypt Modified.')
    ->param('passwordSalt', '', new Text(128), 'Salt used to hash password.')
    ->param('passwordSaltSeparator', '', new Text(128), 'Salt separator used to hash password.')
    ->param('passwordSignerKey', '', new Text(128), 'Signer key used to hash password.')
    ->param('name', '', new Text(128), 'User name. Max length: 128 chars.', true)
    ->inject('response')
    ->inject('project')
    ->inject('dbForProject')
    ->inject('events')
    ->action(function (string $userId, string $email, string $password, string $passwordSalt, string $passwordSaltSeparator, string $passwordSignerKey, string $name, Response $response, Document $project, Database $dbForProject, Event $events) {
        $user = createUser('scryptMod', '{"signerKey":"' . $passwordSignerKey . '","saltSeparator":"' . $passwordSaltSeparator . '","salt":"' . $passwordSalt . '"}', $userId, $email, $password, null, $name, $project, $dbForProject, $events);

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($user, Response::MODEL_USER);
    });

App::get('/v1/users')
    ->desc('List Users')
    ->groups(['api', 'users'])
    ->label('scope', 'users.read')
    ->label('usage.metric', 'users.{scope}.requests.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'list')
    ->label('sdk.description', '/docs/references/users/list-users.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USER_LIST)
    ->param('queries', [], new Users(), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/databases#querying-documents). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long. You may filter on the following attributes: ' . implode(', ', Users::ALLOWED_ATTRIBUTES), true)
    ->param('search', '', new Text(256), 'Search term to filter your list results. Max length: 256 chars.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (array $queries, string $search, Response $response, Database $dbForProject) {

        $queries = Query::parseQueries($queries);

        if (!empty($search)) {
            $queries[] = Query::search('search', $search);
        }

        // Get cursor document if there was a cursor query
        $cursor = Query::getByType($queries, Query::TYPE_CURSORAFTER, Query::TYPE_CURSORBEFORE);
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
    ->desc('Get User')
    ->groups(['api', 'users'])
    ->label('scope', 'users.read')
    ->label('usage.metric', 'users.{scope}.requests.read')
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
            throw new Exception('User not found', 404, Exception::USER_NOT_FOUND);
        }

        $response->dynamic($user, Response::MODEL_USER);
    });

App::get('/v1/users/:userId/prefs')
    ->desc('Get User Preferences')
    ->groups(['api', 'users'])
    ->label('scope', 'users.read')
    ->label('usage.metric', 'users.{scope}.requests.read')
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

        $prefs = $user->getAttribute('prefs', new \stdClass());

        $response->dynamic(new Document($prefs), Response::MODEL_PREFERENCES);
    });

App::get('/v1/users/:userId/sessions')
    ->desc('List User Sessions')
    ->groups(['api', 'users'])
    ->label('scope', 'users.read')
    ->label('usage.metric', 'users.{scope}.requests.read')
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
    ->desc('List User Memberships')
    ->groups(['api', 'users'])
    ->label('scope', 'users.read')
    ->label('usage.metric', 'users.{scope}.requests.read')
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
    ->desc('List User Logs')
    ->groups(['api', 'users'])
    ->label('scope', 'users.read')
    ->label('usage.metric', 'users.{scope}.requests.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'listLogs')
    ->label('sdk.description', '/docs/references/users/list-user-logs.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_LOG_LIST)
    ->param('userId', '', new UID(), 'User ID.')
    ->param('queries', [], new Queries(new Limit(), new Offset()), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/databases#querying-documents). Only supported methods are limit and offset', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('locale')
    ->inject('geodb')
    ->action(function (string $userId, array $queries, Response $response, Database $dbForProject, Locale $locale, Reader $geodb) {

        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        $queries = Query::parseQueries($queries);
        $grouped = Query::groupByType($queries);
        $limit = $grouped['limit'] ?? APP_LIMIT_COUNT;
        $offset = $grouped['offset'] ?? 0;

        $audit = new Audit($dbForProject);

        $logs = $audit->getLogsByUser($user->getId(), $limit, $offset);

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
            'total' => $audit->countLogsByUser($user->getId()),
            'logs' => $output,
        ]), Response::MODEL_LOG_LIST);
    });

App::patch('/v1/users/:userId/status')
    ->desc('Update User Status')
    ->groups(['api', 'users'])
    ->label('event', 'users.[userId].update.status')
    ->label('scope', 'users.write')
    ->label('audits.event', 'user.update')
    ->label('audits.resource', 'user/{response.$id}')
    ->label('audits.userId', '{response.$id}')
    ->label('usage.metric', 'users.{scope}.requests.update')
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
    ->inject('events')
    ->action(function (string $userId, bool $status, Response $response, Database $dbForProject, Event $events) {

        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        $user = $dbForProject->updateDocument('users', $user->getId(), $user->setAttribute('status', (bool) $status));

        $events
            ->setParam('userId', $user->getId());

        $response->dynamic($user, Response::MODEL_USER);
    });

App::patch('/v1/users/:userId/verification')
    ->desc('Update Email Verification')
    ->groups(['api', 'users'])
    ->label('event', 'users.[userId].update.verification')
    ->label('scope', 'users.write')
    ->label('audits.event', 'verification.update')
    ->label('audits.resource', 'user/{response.$id}')
    ->label('usage.metric', 'users.{scope}.requests.update')
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
    ->inject('events')
    ->action(function (string $userId, bool $emailVerification, Response $response, Database $dbForProject, Event $events) {

        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        $user = $dbForProject->updateDocument('users', $user->getId(), $user->setAttribute('emailVerification', $emailVerification));

        $events
            ->setParam('userId', $user->getId());

        $response->dynamic($user, Response::MODEL_USER);
    });

App::patch('/v1/users/:userId/verification/phone')
    ->desc('Update Phone Verification')
    ->groups(['api', 'users'])
    ->label('event', 'users.[userId].update.verification')
    ->label('scope', 'users.write')
    ->label('audits.event', 'verification.update')
    ->label('audits.resource', 'user/{response.$id}')
    ->label('usage.metric', 'users.{scope}.requests.update')
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
    ->inject('events')
    ->action(function (string $userId, bool $phoneVerification, Response $response, Database $dbForProject, Event $events) {

        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        $user = $dbForProject->updateDocument('users', $user->getId(), $user->setAttribute('phoneVerification', $phoneVerification));

        $events
            ->setParam('userId', $user->getId());

        $response->dynamic($user, Response::MODEL_USER);
    });

App::patch('/v1/users/:userId/name')
    ->desc('Update Name')
    ->groups(['api', 'users'])
    ->label('event', 'users.[userId].update.name')
    ->label('scope', 'users.write')
    ->label('audits.event', 'user.update')
    ->label('audits.resource', 'user/{response.$id}')
    ->label('audits.userId', '{response.$id}')
    ->label('usage.metric', 'users.{scope}.requests.update')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'updateName')
    ->label('sdk.description', '/docs/references/users/update-user-name.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USER)
    ->param('userId', '', new UID(), 'User ID.')
    ->param('name', '', new Text(128), 'User name. Max length: 128 chars.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('events')
    ->action(function (string $userId, string $name, Response $response, Database $dbForProject, Event $events) {

        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        $user
            ->setAttribute('name', $name)
            ->setAttribute('search', \implode(' ', [$user->getId(), $user->getAttribute('email', ''), $name, $user->getAttribute('phone', '')]));
        ;

        $user = $dbForProject->updateDocument('users', $user->getId(), $user);

        $events->setParam('userId', $user->getId());

        $response->dynamic($user, Response::MODEL_USER);
    });

App::patch('/v1/users/:userId/password')
    ->desc('Update Password')
    ->groups(['api', 'users'])
    ->label('event', 'users.[userId].update.password')
    ->label('scope', 'users.write')
    ->label('audits.event', 'user.update')
    ->label('audits.resource', 'user/{response.$id}')
    ->label('audits.userId', '{response.$id}')
    ->label('usage.metric', 'users.{scope}.requests.update')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'updatePassword')
    ->label('sdk.description', '/docs/references/users/update-user-password.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USER)
    ->param('userId', '', new UID(), 'User ID.')
    ->param('password', '', new Password(), 'New user password. Must be at least 8 chars.')
    ->inject('response')
    ->inject('project')
    ->inject('dbForProject')
    ->inject('events')
    ->action(function (string $userId, string $password, Response $response, Document $project, Database $dbForProject, Event $events) {

        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        $historyLimit = $project->getAttribute('auths', [])['passwordHistory'] ?? 0;

        $history = [];
        if($historyLimit > 0) {
            $history = $user->getAttribute('passwordHistory', []);
            $newPassword = Auth::passwordHash($password, Auth::DEFAULT_ALGO, Auth::DEFAULT_ALGO_OPTIONS);
    
            if(in_array($newPassword, $history)) {
                throw new Exception(Exception::USER_PASSWORD_RECENTLY_USED, 'The password was recently used', 409);
            }
    
            $history[] = $newPassword;
            while(count($history) > $historyLimit) {
                array_pop($history);
            }
        }

        $user
            ->setAttribute('passwordHistory', $history)
            ->setAttribute('password', $newPassword)
            ->setAttribute('hash', Auth::DEFAULT_ALGO)
            ->setAttribute('hashOptions', Auth::DEFAULT_ALGO_OPTIONS)
            ->setAttribute('passwordUpdate', DateTime::now());

        $user = $dbForProject->updateDocument('users', $user->getId(), $user);

        $events->setParam('userId', $user->getId());

        $response->dynamic($user, Response::MODEL_USER);
    });

App::patch('/v1/users/:userId/email')
    ->desc('Update Email')
    ->groups(['api', 'users'])
    ->label('event', 'users.[userId].update.email')
    ->label('scope', 'users.write')
    ->label('audits.event', 'user.update')
    ->label('audits.resource', 'user/{response.$id}')
    ->label('audits.userId', '{response.$id}')
    ->label('usage.metric', 'users.{scope}.requests.update')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'updateEmail')
    ->label('sdk.description', '/docs/references/users/update-user-email.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USER)
    ->param('userId', '', new UID(), 'User ID.')
    ->param('email', '', new Email(), 'User email.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('events')
    ->action(function (string $userId, string $email, Response $response, Database $dbForProject, Event $events) {

        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        $email = \strtolower($email);

        $user
            ->setAttribute('email', $email)
            ->setAttribute('emailVerification', false)
            ->setAttribute('search', \implode(' ', [$user->getId(), $email, $user->getAttribute('name', ''), $user->getAttribute('phone', '')]));

        try {
            $user = $dbForProject->updateDocument('users', $user->getId(), $user);
        } catch (Duplicate $th) {
            throw new Exception(Exception::USER_EMAIL_ALREADY_EXISTS);
        }

        $events->setParam('userId', $user->getId());

        $response->dynamic($user, Response::MODEL_USER);
    });

App::patch('/v1/users/:userId/phone')
    ->desc('Update Phone')
    ->groups(['api', 'users'])
    ->label('event', 'users.[userId].update.phone')
    ->label('scope', 'users.write')
    ->label('audits.event', 'user.update')
    ->label('audits.resource', 'user/{response.$id}')
    ->label('usage.metric', 'users.{scope}.requests.update')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'updatePhone')
    ->label('sdk.description', '/docs/references/users/update-user-phone.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USER)
    ->param('userId', '', new UID(), 'User ID.')
    ->param('number', '', new Phone(), 'User phone number.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('events')
    ->action(function (string $userId, string $number, Response $response, Database $dbForProject, Event $events) {

        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        $user
            ->setAttribute('phone', $number)
            ->setAttribute('phoneVerification', false)
            ->setAttribute('search', implode(' ', [$user->getId(), $user->getAttribute('name', ''), $user->getAttribute('email', ''), $number]));
        ;

        try {
            $user = $dbForProject->updateDocument('users', $user->getId(), $user);
        } catch (Duplicate $th) {
            throw new Exception(Exception::USER_PHONE_ALREADY_EXISTS);
        }

        $events->setParam('userId', $user->getId());

        $response->dynamic($user, Response::MODEL_USER);
    });

App::patch('/v1/users/:userId/verification')
    ->desc('Update Email Verification')
    ->groups(['api', 'users'])
    ->label('event', 'users.[userId].update.verification')
    ->label('scope', 'users.write')
    ->label('audits.event', 'verification.update')
    ->label('audits.resource', 'user/{request.userId}')
    ->label('audits.userId', '{request.userId}')
    ->label('usage.metric', 'users.{scope}.requests.update')
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
    ->inject('events')
    ->action(function (string $userId, bool $emailVerification, Response $response, Database $dbForProject, Event $events) {

        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        $user = $dbForProject->updateDocument('users', $user->getId(), $user->setAttribute('emailVerification', $emailVerification));

        $events->setParam('userId', $user->getId());

        $response->dynamic($user, Response::MODEL_USER);
    });

App::patch('/v1/users/:userId/prefs')
    ->desc('Update User Preferences')
    ->groups(['api', 'users'])
    ->label('event', 'users.[userId].update.prefs')
    ->label('scope', 'users.write')
    ->label('usage.metric', 'users.{scope}.requests.update')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'updatePrefs')
    ->label('sdk.description', '/docs/references/users/update-user-prefs.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PREFERENCES)
    ->param('userId', '', new UID(), 'User ID.')
    ->param('prefs', '', new Assoc(), 'Prefs key-value JSON object.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('events')
    ->action(function (string $userId, array $prefs, Response $response, Database $dbForProject, Event $events) {

        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        $user = $dbForProject->updateDocument('users', $user->getId(), $user->setAttribute('prefs', $prefs));

        $events
            ->setParam('userId', $user->getId());

        $response->dynamic(new Document($prefs), Response::MODEL_PREFERENCES);
    });

App::delete('/v1/users/:userId/sessions/:sessionId')
    ->desc('Delete User Session')
    ->groups(['api', 'users'])
    ->label('event', 'users.[userId].sessions.[sessionId].delete')
    ->label('scope', 'users.write')
    ->label('audits.event', 'session.delete')
    ->label('audits.resource', 'user/{request.userId}')
    ->label('usage.metric', 'sessions.{scope}.requests.delete')
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
    ->inject('events')
    ->action(function (string $userId, string $sessionId, Response $response, Database $dbForProject, Event $events) {

        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        $session = $dbForProject->getDocument('sessions', $sessionId);

        if ($session->isEmpty()) {
            throw new Exception(Exception::USER_SESSION_NOT_FOUND);
        }

        $dbForProject->deleteDocument('sessions', $session->getId());
        $dbForProject->deleteCachedDocument('users', $user->getId());

        $events
            ->setParam('userId', $user->getId())
            ->setParam('sessionId', $sessionId);

        $response->noContent();
    });

App::delete('/v1/users/:userId/sessions')
    ->desc('Delete User Sessions')
    ->groups(['api', 'users'])
    ->label('event', 'users.[userId].sessions.[sessionId].delete')
    ->label('scope', 'users.write')
    ->label('audits.event', 'session.delete')
    ->label('audits.resource', 'user/{user.$id}')
    ->label('usage.metric', 'sessions.{scope}.requests.delete')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'deleteSessions')
    ->label('sdk.description', '/docs/references/users/delete-user-sessions.md')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->param('userId', '', new UID(), 'User ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('events')
    ->action(function (string $userId, Response $response, Database $dbForProject, Event $events) {

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

        $dbForProject->deleteCachedDocument('users', $user->getId());

        $events
            ->setParam('userId', $user->getId())
            ->setPayload($response->output($user, Response::MODEL_USER));

        $response->noContent();
    });

App::delete('/v1/users/:userId')
    ->desc('Delete User')
    ->groups(['api', 'users'])
    ->label('event', 'users.[userId].delete')
    ->label('scope', 'users.write')
    ->label('audits.event', 'user.delete')
    ->label('audits.resource', 'user/{request.userId}')
    ->label('usage.metric', 'users.{scope}.requests.delete')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'delete')
    ->label('sdk.description', '/docs/references/users/delete.md')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->param('userId', '', new UID(), 'User ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('events')
    ->inject('deletes')
    ->action(function (string $userId, Response $response, Database $dbForProject, Event $events, Delete $deletes) {

        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        // clone user object to send to workers
        $clone = clone $user;

        $dbForProject->deleteDocument('users', $userId);

        $deletes
            ->setType(DELETE_TYPE_DOCUMENT)
            ->setDocument($clone);

        $events
            ->setParam('userId', $user->getId())
            ->setPayload($response->output($clone, Response::MODEL_USER));

        $response->noContent();
    });

App::get('/v1/users/usage')
    ->desc('Get usage stats for the users API')
    ->groups(['api', 'users'])
    ->label('scope', 'users.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'getUsage')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USAGE_USERS)
    ->param('range', '30d', new WhiteList(['24h', '7d', '30d', '90d'], true), 'Date range.', true)
    ->param('provider', '', new WhiteList(\array_merge(['email', 'anonymous'], \array_map(fn ($value) => "oauth-" . $value, \array_keys(Config::getParam('providers', [])))), true), 'Provider Name.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('register')
    ->action(function (string $range, string $provider, Response $response, Database $dbForProject) {

        $usage = [];
        if (App::getEnv('_APP_USAGE_STATS', 'enabled') == 'enabled') {
            $periods = [
                '24h' => [
                    'period' => '1h',
                    'limit' => 24,
                ],
                '7d' => [
                    'period' => '1d',
                    'limit' => 7,
                ],
                '30d' => [
                    'period' => '1d',
                    'limit' => 30,
                ],
                '90d' => [
                    'period' => '1d',
                    'limit' => 90,
                ],
            ];

            $metrics = [
                'users.$all.count.total',
                'users.$all.requests.create',
                'users.$all.requests.read',
                'users.$all.requests.update',
                'users.$all.requests.delete',
                'sessions.$all.requests.create',
                'sessions.$all.requests.delete',
                "sessions.$provider.requests.create",
            ];

            $stats = [];

            Authorization::skip(function () use ($dbForProject, $periods, $range, $metrics, &$stats) {
                foreach ($metrics as $metric) {
                    $limit = $periods[$range]['limit'];
                    $period = $periods[$range]['period'];

                    $requestDocs = $dbForProject->find('stats', [
                        Query::equal('period', [$period]),
                        Query::equal('metric', [$metric]),
                        Query::limit($limit),
                        Query::orderDesc('time'),
                    ]);

                    $stats[$metric] = [];
                    foreach ($requestDocs as $requestDoc) {
                        $stats[$metric][] = [
                            'value' => $requestDoc->getAttribute('value'),
                            'date' => $requestDoc->getAttribute('time'),
                        ];
                    }

                    // backfill metrics with empty values for graphs
                    $backfill = $limit - \count($requestDocs);
                    while ($backfill > 0) {
                        $last = $limit - $backfill - 1; // array index of last added metric
                        $diff = match ($period) { // convert period to seconds for unix timestamp math
                            '1h' => 3600,
                            '1d' => 86400,
                        };
                        $stats[$metric][] = [
                            'value' => 0,
                            'date' => DateTime::formatTz(DateTime::addSeconds(new \DateTime($stats[$metric][$last]['date'] ?? null), -1 * $diff)),
                        ];
                        $backfill--;
                    }
                    $stats[$metric] = array_reverse($stats[$metric]);
                }
            });

            $usage = new Document([
                'range' => $range,
                'usersCount' => $stats['users.$all.count.total'] ?? [],
                'usersCreate' => $stats['users.$all.requests.create'] ?? [],
                'usersRead' => $stats['users.$all.requests.read'] ?? [],
                'usersUpdate' => $stats['users.$all.requests.update'] ?? [],
                'usersDelete' => $stats['users.$all.requests.delete'] ?? [],
                'sessionsCreate' => $stats['sessions.$all.requests.create'] ?? [],
                'sessionsProviderCreate' => $stats["sessions.$provider.requests.create"] ?? [],
                'sessionsDelete' => $stats['sessions.$all.requests.delete' ?? []]
            ]);
        }

        $response->dynamic($usage, Response::MODEL_USAGE_USERS);
    });

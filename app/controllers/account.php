<?php

global $utopia, $register, $response, $user, $audit, $project, $projectDB, $providers;

use Utopia\Exception;
use Utopia\Validator\Text;
use Utopia\Validator\Email;
use Auth\Auth;
use Auth\Validator\Password;
use Database\Database;
use Database\Validator\Authorization;
use DeviceDetector\DeviceDetector;
use GeoIp2\Database\Reader;
use Utopia\Locale\Locale;

$utopia->get('/v1/account')
    ->desc('Get Account')
    ->label('scope', 'account')
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'get')
    ->label('sdk.description', '/docs/references/account/get.md')
    ->action(
        function () use ($response, &$user, $providers) {
            $oauthKeys = [];

            foreach ($providers as $key => $provider) {
                if (!$provider['enabled']) {
                    continue;
                }

                $oauthKeys[] = 'oauth'.ucfirst($key);
                $oauthKeys[] = 'oauth'.ucfirst($key).'AccessToken';
            }

            $response->json(array_merge($user->getArrayCopy(array_merge(
                [
                    '$uid',
                    'email',
                    'registration',
                    'confirm',
                    'name',
                ],
                $oauthKeys
            )), ['roles' => Authorization::getRoles()]));
        }
    );

$utopia->get('/v1/account/prefs')
    ->desc('Get Account Preferences')
    ->label('scope', 'account')
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'getPrefs')
    ->label('sdk.description', '/docs/references/account/get-prefs.md')
    ->action(
        function () use ($response, $user) {
            $prefs = $user->getAttribute('prefs', '{}');

            if (empty($prefs)) {
                $prefs = '[]';
            }

            try {
                $prefs = json_decode($prefs, true);
            } catch (\Exception $error) {
                throw new Exception('Failed to parse prefs', 500);
            }

            $response->json($prefs);
        }
    );

$utopia->get('/v1/account/sessions')
    ->desc('Get Account Active Sessions')
    ->label('scope', 'account')
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'getSessions')
    ->label('sdk.description', '/docs/references/account/get-sessions.md')
    ->action(
        function () use ($response, $user) {
            $tokens = $user->getAttribute('tokens', []);
            $reader = new Reader(__DIR__.'/../db/GeoLite2/GeoLite2-Country.mmdb');
            $sessions = [];
            $current = Auth::tokenVerify($tokens, Auth::TOKEN_TYPE_LOGIN, Auth::$secret);
            $index = 0;
            $countries = Locale::getText('countries');

            foreach ($tokens as $token) { /* @var $token Document */
                if (Auth::TOKEN_TYPE_LOGIN != $token->getAttribute('type')) {
                    continue;
                }

                $userAgent = (!empty($token->getAttribute('userAgent'))) ? $token->getAttribute('userAgent') : 'UNKNOWN';

                $dd = new DeviceDetector($userAgent);

                // OPTIONAL: If called, bot detection will completely be skipped (bots will be detected as regular devices then)
                // $dd->skipBotDetection();

                $dd->parse();

                $sessions[$index] = [
                    'id' => $token->getUid(),
                    'OS' => $dd->getOs(),
                    'client' => $dd->getClient(),
                    'device' => $dd->getDevice(),
                    'brand' => $dd->getBrand(),
                    'model' => $dd->getModel(),
                    'ip' => $token->getAttribute('ip', ''),
                    'geo' => [],
                    'current' => ($current == $token->getUid()) ? true : false,
                ];

                try {
                    $record = $reader->country($token->getAttribute('ip', ''));
                    $sessions[$index]['geo']['isoCode'] = strtolower($record->country->isoCode);
                    $sessions[$index]['geo']['country'] = (isset($countries[$record->country->isoCode])) ? $countries[$record->country->isoCode] : Locale::getText('locale.country.unknown');
                } catch (\Exception $e) {
                    $sessions[$index]['geo']['isoCode'] = '--';
                    $sessions[$index]['geo']['country'] = Locale::getText('locale.country.unknown');
                }

                ++$index;
            }

            $response->json($sessions);
        }
    );

$utopia->get('/v1/account/security')
    ->desc('Get Account Security Log')
    ->label('scope', 'account')
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'getSecurity')
    ->label('sdk.description', '/docs/references/account/get-security.md')
    ->action(
        function () use ($response, $register, $project, $user) {
            $ad = new \Audit\Adapter\MySQL($register->get('db'));
            $ad->setNamespace('app_'.$project->getUid());
            $au = new \Audit\Audit($ad, $user->getUid(), $user->getAttribute('type'), '', '', '');
            $countries = Locale::getText('countries');

            $logs = $au->getLogsByUserAndActions($user->getUid(), $user->getAttribute('type', 0), [
                'auth.register',
                'auth.confirm',
                'auth.login',
                'auth.logout',
                'auth.recovery',
                'auth.recovery.reset',
                'auth.oauth.login',
                'auth.invite',
                'auth.join',
                'auth.leave',
                'account.delete',
                'account.update.name',
                'account.update.email',
                'account.update.password',
            ]);

            $reader = new Reader(__DIR__.'/../db/GeoLite2/GeoLite2-Country.mmdb');
            $output = [];

            foreach ($logs as $i => &$log) {
                $log['userAgent'] = (!empty($log['userAgent'])) ? $log['userAgent'] : 'UNKNOWN';

                $dd = new DeviceDetector($log['userAgent']);

                $dd->skipBotDetection(); // OPTIONAL: If called, bot detection will completely be skipped (bots will be detected as regular devices then)

                $dd->parse();

                $output[$i] = [
                    'event' => $log['event'],
                    'ip' => $log['ip'],
                    'time' => strtotime($log['time']),
                    'OS' => $dd->getOs(),
                    'client' => $dd->getClient(),
                    'device' => $dd->getDevice(),
                    'brand' => $dd->getBrand(),
                    'model' => $dd->getModel(),
                    'geo' => [],
                ];

                try {
                    $record = $reader->country($log['ip']);
                    $output[$i]['geo']['isoCode'] = strtolower($record->country->isoCode);
                    $output[$i]['geo']['country'] = $record->country->name;
                    $output[$i]['geo']['country'] = (isset($countries[$record->country->isoCode])) ? $countries[$record->country->isoCode] : Locale::getText('locale.country.unknown');
                } catch (\Exception $e) {
                    $output[$i]['geo']['isoCode'] = '--';
                    $output[$i]['geo']['country'] = Locale::getText('locale.country.unknown');
                }
            }

            $response->json($output);
        }
    );

$utopia->patch('/v1/account/name')
    ->desc('Update Account Name')
    ->label('webhook', 'account.update-name')
    ->label('scope', 'account')
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'updateName')
    ->label('sdk.description', '/docs/references/account/update-name.md')
    ->param('name', '', function () { return new Text(100); }, 'User name')
    ->action(
        function ($name) use ($response, $user, $projectDB, $audit) {
            $user = $projectDB->updateDocument(array_merge($user->getArrayCopy(), [
                'name' => $name,
            ]));

            if (false === $user) {
                throw new Exception('Failed saving user to DB', 500);
            }

            $audit->setParam('event', 'account.update.name');

            $response->json(array('result' => 'success'));
        }
    );

$utopia->patch('/v1/account/password')
    ->desc('Update Account Password')
    ->label('webhook', 'account.update-password')
    ->label('scope', 'account')
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'updatePassword')
    ->label('sdk.description', '/docs/references/account/update-password.md')
    ->param('password', '', function () { return new Password(); }, 'New password')
    ->param('old-password', '', function () { return new Password(); }, 'Old password')
    ->action(
        function ($password, $oldPassword) use ($response, $user, $projectDB, $audit) {
            if (!Auth::passwordVerify($oldPassword, $user->getAttribute('password'))) { // Double check user password
                throw new Exception('Invalid credentials', 401);
            }

            $user = $projectDB->updateDocument(array_merge($user->getArrayCopy(), [
                'password' => Auth::passwordHash($password),
            ]));

            if (false === $user) {
                throw new Exception('Failed saving user to DB', 500);
            }

            $audit->setParam('event', 'account.update.password');

            $response->json(array('result' => 'success'));
        }
    );

$utopia->patch('/v1/account/email')
    ->desc('Update Account Email')
    ->label('webhook', 'account.update-email')
    ->label('scope', 'account')
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'updateEmail')
    ->label('sdk.description', '/docs/references/account/update-email.md')
    ->param('email', '', function () { return new Email(); }, 'Email Address')
    ->param('password', '', function () { return new Password(); }, 'User Password')
    ->action(
        function ($email, $password) use ($response, $user, $projectDB, $audit) {
            if (!Auth::passwordVerify($password, $user->getAttribute('password'))) { // Double check user password
                throw new Exception('Invalid credentials', 401);
            }

            $profile = $projectDB->getCollection([ // Get user by email address
                'limit' => 1,
                'first' => true,
                'filters' => [
                    '$collection='.Database::SYSTEM_COLLECTION_USERS,
                    'email='.$email,
                ],
            ]);

            if (!empty($profile)) {
                throw new Exception('User already registered', 400);
            }

            // TODO after this user needs to confirm mail again

            $user = $projectDB->updateDocument(array_merge($user->getArrayCopy(), [
                'email' => $email,
            ]));

            if (false === $user) {
                throw new Exception('Failed saving user to DB', 500);
            }

            $audit->setParam('event', 'account.update.email');

            $response->json(array('result' => 'success'));
        }
    );

$utopia->patch('/v1/account/prefs')
    ->desc('Update Account Prefs')
    ->label('webhook', 'account')
    ->label('scope', 'account')
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'updatePrefs')
    ->param('prefs', '', function () { return new \Utopia\Validator\Mock();}, 'Prefs key-value JSON object string.')
    ->label('sdk.description', '/docs/references/account/update-prefs.md')
    ->action(
        function ($prefs) use ($response, $user, $projectDB, $audit) {
            $user = $projectDB->updateDocument(array_merge($user->getArrayCopy(), [
                'prefs' => json_encode(array_merge(json_decode($user->getAttribute('prefs', '{}'), true), $prefs)),
            ]));

            if (false === $user) {
                throw new Exception('Failed saving user to DB', 500);
            }

            $audit->setParam('event', 'account.update.prefs');

            $response->json(array('result' => 'success'));
        }
    );

$utopia->delete('/v1/account')
    ->desc('Delete Account')
    ->label('webhook', 'account.delete')
    ->label('scope', 'account')
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'delete')
    ->label('sdk.description', '/docs/references/account/delete.md')
    ->action(
        function () use ($response, $request, $user, $projectDB, $audit) {
            $user = $projectDB->updateDocument(array_merge($user->getArrayCopy(), [
                'status' => Auth::USER_STATUS_BLOCKED,
            ]));

            if (false === $user) {
                throw new Exception('Failed saving user to DB', 500);
            }

            //TODO delete all tokens or only current session?
            //TODO delete all user data according to GDPR. Make sure everything is backed up and backups are deleted later
            /*
             * Data to delete
             * * Tokens
             * * Memberships
             * * Vaults
             * * Subscriptions
             *
             * Not to Delete!!
             * * Invoices (belong to project/business not user(!) and also needed for IRS records!)
             */

            $audit
                ->setParam('event', 'account.delete')
                ->setParam('data', $user->getArrayCopy())
            ;

            $response
                ->addCookie(Auth::$cookieName, '', time() - 3600, '/', COOKIE_DOMAIN, ('https' == $request->getServer('REQUEST_SCHEME', 'https')), true)
                ->json(array('result' => 'success'));
        }
    );

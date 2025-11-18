<?php

use Appwrite\Auth\Auth;
use Appwrite\Extend\Exception;
use Appwrite\Utopia\Request;
use Utopia\App;
use Utopia\Config\Config;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\System\System;

App::init()
    ->groups(['mfaProtected'])
    ->inject('session')
    ->action(function (Document $session) {
        $isSessionFresh = false;

        $lastUpdate = $session->getAttribute('mfaUpdatedAt');
        if (!empty($lastUpdate)) {
            $now = DateTime::now();
            $maxAllowedDate = DateTime::addSeconds(new \DateTime($lastUpdate), Auth::MFA_RECENT_DURATION); // Maximum date until session is considered safe before asking for another challenge

            $isSessionFresh = DateTime::formatTz($maxAllowedDate) >= DateTime::formatTz($now);
        }

        if (!$isSessionFresh) {
            throw new Exception(Exception::USER_CHALLENGE_REQUIRED);
        }
    });

App::init()
    ->groups(['auth'])
    ->inject('utopia')
    ->inject('request')
    ->inject('project')
    ->inject('geoRecord')
    ->inject('authorization')
    ->action(function (App $utopia, Request $request, Document $project, array $geoRecord, Authorization $authorization) {
        $denylist = System::getEnv('_APP_CONSOLE_COUNTRIES_DENYLIST', '');
        if (!empty($denylist && $project->getId() === 'console')) {
            $countries = explode(',', $denylist);
            $country = $geoRecord['countryCode'] ?? '';
            if (in_array($country, $countries)) {
                throw new Exception(Exception::GENERAL_REGION_ACCESS_DENIED);
            }
        }

        $route = $utopia->match($request);

        $isPrivilegedUser = Auth::isPrivilegedUser($authorization->getRoles());
        $isAppUser = Auth::isAppUser($authorization->getRoles());

        if ($isAppUser || $isPrivilegedUser) { // Skip limits for app and console devs
            return;
        }

        $auths = $project->getAttribute('auths', []);
        switch ($route->getLabel('auth.type', '')) {
            case 'email-password':
                if (($auths[Config::getParam('auth')['email-password']['key']] ?? true) === false) {
                    throw new Exception(Exception::USER_AUTH_METHOD_UNSUPPORTED, 'Email / Password authentication is disabled for this project');
                }
                break;

            case 'magic-url':
                if (($auths[Config::getParam('auth')['magic-url']['key']] ?? true) === false) {
                    throw new Exception(Exception::USER_AUTH_METHOD_UNSUPPORTED, 'Magic URL authentication is disabled for this project');
                }
                break;

            case 'anonymous':
                if (($auths[Config::getParam('auth')['anonymous']['key']] ?? true) === false) {
                    throw new Exception(Exception::USER_AUTH_METHOD_UNSUPPORTED, 'Anonymous authentication is disabled for this project');
                }
                break;

            case 'phone':
                if (($auths[Config::getParam('auth')['phone']['key']] ?? true) === false) {
                    throw new Exception(Exception::USER_AUTH_METHOD_UNSUPPORTED, 'Phone authentication is disabled for this project');
                }
                break;

            case 'invites':
                if (($auths[Config::getParam('auth')['invites']['key']] ?? true) === false) {
                    throw new Exception(Exception::USER_AUTH_METHOD_UNSUPPORTED, 'Invites authentication is disabled for this project');
                }
                break;

            case 'jwt':
                if (($auths[Config::getParam('auth')['jwt']['key']] ?? true) === false) {
                    throw new Exception(Exception::USER_AUTH_METHOD_UNSUPPORTED, 'JWT authentication is disabled for this project');
                }
                break;

            case 'email-otp':
                if (($auths[Config::getParam('auth')['email-otp']['key']] ?? true) === false) {
                    throw new Exception(Exception::USER_AUTH_METHOD_UNSUPPORTED, 'Email OTP authentication is disabled for this project');
                }
                break;

            default:
                throw new Exception(Exception::USER_AUTH_METHOD_UNSUPPORTED, 'Unsupported authentication route');
        }
    });

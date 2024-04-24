<?php

use Appwrite\Auth\Auth;
use Appwrite\Extend\Exception;
use Appwrite\Utopia\Request;
use MaxMind\Db\Reader;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Http\Http;
use Utopia\Http\Route;
use Utopia\System\System;

Http::init()
    ->groups(['mfaProtected'])
    ->inject('session')
    ->action(function (Document $session) {
        $isSessionFresh = false;

        $lastUpdate = $session->getAttribute('mfaUpdatedAt');
        if (!empty($lastUpdate)) {
            $now = DateTime::now();
            $maxAllowedDate = DateTime::addSeconds($lastUpdate, Auth::MFA_RECENT_DURATION); // Maximum date until session is considered safe before asking for another challenge

            $isSessionFresh = DateTime::formatTz($maxAllowedDate) >= DateTime::formatTz($now);
        }

        if (!$isSessionFresh) {
            throw new Exception(Exception::USER_CHALLENGE_REQUIRED);
        }
    });

Http::init()
    ->groups(['auth'])
    ->inject('route')
    ->inject('request')
    ->inject('project')
    ->inject('geodb')
    ->inject('authorization')
    ->action(function (Route $route, Request $request, Document $project, Reader $geodb, Authorization $authorization) {
        $denylist = System::getEnv('_APP_CONSOLE_COUNTRIES_DENYLIST', '');
        if (!empty($denylist && $project->getId() === 'console')) {
            $countries = explode(',', $denylist);
            $record = $geodb->get($request->getIP()) ?? [];
            $country = $record['country']['iso_code'] ?? '';
            if (in_array($country, $countries)) {
                throw new Exception(Exception::GENERAL_REGION_ACCESS_DENIED);
            }
        }

        $isPrivilegedUser = Auth::isPrivilegedUser($authorization->getRoles());
        $isAppUser = Auth::isAppUser($authorization->getRoles());

        if ($isAppUser || $isPrivilegedUser) { // Skip limits for app and console devs
            return;
        }

        $auths = $project->getAttribute('auths', []);
        switch ($route->getLabel('auth.type', '')) {
            case 'emailPassword':
                if (($auths['emailPassword'] ?? true) === false) {
                    throw new Exception(Exception::USER_AUTH_METHOD_UNSUPPORTED, 'Email / Password authentication is disabled for this project');
                }
                break;

            case 'magic-url':
                if (($auths['usersAuthMagicURL'] ?? true) === false) {
                    throw new Exception(Exception::USER_AUTH_METHOD_UNSUPPORTED, 'Magic URL authentication is disabled for this project');
                }
                break;

            case 'anonymous':
                if (($auths['anonymous'] ?? true) === false) {
                    throw new Exception(Exception::USER_AUTH_METHOD_UNSUPPORTED, 'Anonymous authentication is disabled for this project');
                }
                break;

            case 'phone':
                if (($auths['phone'] ?? true) === false) {
                    throw new Exception(Exception::USER_AUTH_METHOD_UNSUPPORTED, 'Phone authentication is disabled for this project');
                }
                break;

            case 'invites':
                if (($auths['invites'] ?? true) === false) {
                    throw new Exception(Exception::USER_AUTH_METHOD_UNSUPPORTED, 'Invites authentication is disabled for this project');
                }
                break;

            case 'jwt':
                if (($auths['JWT'] ?? true) === false) {
                    throw new Exception(Exception::USER_AUTH_METHOD_UNSUPPORTED, 'JWT authentication is disabled for this project');
                }
                break;

            case 'email-otp':
                if (($auths['emailOTP'] ?? true) === false) {
                    throw new Exception(Exception::USER_AUTH_METHOD_UNSUPPORTED, 'Email OTP authentication is disabled for this project');
                }
                break;

            default:
                throw new Exception(Exception::USER_AUTH_METHOD_UNSUPPORTED, 'Unsupported authentication route');
        }
    });

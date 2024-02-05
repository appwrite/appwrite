<?php

use Appwrite\Auth\Auth;
use Appwrite\Utopia\Request;
use Utopia\App;
use Appwrite\Extend\Exception;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use MaxMind\Db\Reader;

App::init()
    ->groups(['auth'])
    ->inject('utopia')
    ->inject('request')
    ->inject('project')
    ->inject('geodb')
    ->action(function (App $utopia, Request $request, Document $project, Reader $geodb) {
        $denylist = App::getEnv('_APP_CONSOLE_COUNTRIES_DENYLIST', '');
        if (!empty($denylist && $project->getId() === 'console')) {
            $countries = explode(',', $denylist);
            $record = $geodb->get($request->getIP()) ?? [];
            $country = $record['country']['iso_code'] ?? '';
            if (in_array($country, $countries)) {
                throw new Exception(Exception::GENERAL_REGION_ACCESS_DENIED);
            }
        }

        $route = $utopia->match($request);

        $isPrivilegedUser = Auth::isPrivilegedUser(Authorization::getRoles());
        $isAppUser = Auth::isAppUser(Authorization::getRoles());

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
                if ($project->getAttribute('usersAuthMagicURL', true) === false) {
                    throw new Exception(Exception::USER_AUTH_METHOD_UNSUPPORTED, 'Magic URL authentication is disabled for this project');
                }
                break;

            case 'anonymous':
                if (($auths['anonymous'] ?? true) === false) {
                    throw new Exception(Exception::USER_AUTH_METHOD_UNSUPPORTED, 'Anonymous authentication is disabled for this project');
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

            case 'phone':
                if (($auths['phone'] ?? true) === false) {
                    throw new Exception(Exception::USER_AUTH_METHOD_UNSUPPORTED, 'Phone authentication is disabled for this project');
                }
                break;

            case 'email-otp':
                if (($auths['emailOTP'] ?? true) === false) {
                    throw new Exception(Exception::USER_AUTH_METHOD_UNSUPPORTED, 'Email OTP authentication is disabled for this project');
                }
                break;

            default:
                throw new Exception(Exception::USER_AUTH_METHOD_UNSUPPORTED, 'Unsupported authentication route');
                break;
        }
    });

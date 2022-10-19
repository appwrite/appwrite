<?php

use Ahc\Jwt\JWT;
use Ahc\Jwt\JWTException;
use Appwrite\Event\Delete;
use Appwrite\Event\SyncIn;
use Appwrite\Extend\Exception;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\App;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

App::post('/v1/edge')
    ->desc('Purge cache keys')
    ->label('scope', 'public')
    ->param('keys', '', new ArrayList(new Text(100), 1000), 'Cache keys')
    ->inject('request')
    ->inject('response')
    ->action(function (array $keys, Request $request, Response $response) {

        if (empty($keys)) {
            throw new Exception(Exception::KEY_NOT_FOUND);
        }

        $token = $request->getHeader('authorization');
        $token = str_replace(["Bearer"," "], "", $token);
        $jwt = new JWT(App::getEnv('_APP_OPENSSL_KEY_V1'), 'HS256', 600, 10);
        try {
            $payload = $jwt->decode($token);
        } catch (JWTException $error) {
            throw new Exception(Exception::USER_JWT_INVALID, 'Failed to verify JWT. ' . $error->getMessage());
        }

        $syncIn = new SyncIn();
        foreach ($keys as $key) {
            $syncIn
                ->addKey($key)
                ->trigger();
        }

        $response
            ->setStatusCode(Response::STATUS_CODE_OK)
            ->send();
    });

App::post('/v1/edge/notify')
    ->desc('Flush notification')
    ->label('scope', 'public')
    ->param('region', '', new WhiteList(['nyc1', 'blr1', 'fra1']), 'Cloud regions')
    ->inject('request')
    ->inject('response')
    ->action(function (string $region, Request $request, Response $response) {

        $token = $request->getHeader('authorization');
        $token = str_replace(["Bearer"," "], "", $token);
        $jwt = new JWT(App::getEnv('_APP_OPENSSL_KEY_V1'), 'HS256', 600, 10);
        try {
            $payload = $jwt->decode($token);
        } catch (JWTException $error) {
            throw new Exception(Exception::USER_JWT_INVALID, 'Failed to verify JWT. ' . $error->getMessage());
        }

        (new Delete())
            ->setRegion($region)
            ->trigger();

        $response
            ->setStatusCode(Response::STATUS_CODE_OK)
            ->send();
    });

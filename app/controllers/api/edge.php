<?php

use Ahc\Jwt\JWT;
use Ahc\Jwt\JWTException;
use Appwrite\Event\SyncIn;
use Appwrite\Extend\Exception;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\App;
use Utopia\Registry\Registry;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Text;
use Utopia\Queue\Client;
use Utopia\Queue\Connection\Redis;

App::post('/v1/edge')
    ->desc('Purge cache keys')
    ->label('scope', 'public')
    ->param('keys', '', new ArrayList(new Text(100), 1000), 'Cache keys')
    ->inject('request')
    ->inject('response')
    ->inject('register')
    ->action(function (array $keys, Request $request, Response $response, Registry $register) {

        //if (empty($keys)) {
            throw new Exception(Exception::KEY_NOT_FOUND);
        //}

        $token = $request->getHeader('authorization');
        $token = str_replace(["Bearer"," "], "", $token);
        $jwt = new JWT(App::getEnv('_APP_OPENSSL_KEY_V1'), 'HS256', 600, 10);
        try {
            $payload = $jwt->decode($token);
        } catch (JWTException $error) {
            throw new Exception(Exception::USER_JWT_INVALID, 'Failed to verify JWT. ' . $error->getMessage());
        }

        $connection = $register
            ->get('workerRedisConnection');

        $client = new Client('syncIn', $connection);
        $client->resetStats();

        foreach ($keys as $key) {
            $client->enqueue([
                'type' => 'from endpoint',
                'value' => [
                    'key' => $key
                ]
            ]);
        }

        $response
            ->setStatusCode(Response::STATUS_CODE_OK)
            ->send();
    });

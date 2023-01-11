<?php

use Ahc\Jwt\JWT;
use Ahc\Jwt\JWTException;
use Appwrite\Extend\Exception;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\App;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Queue\Client;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Text;

App::init()
    ->groups(['edge'])
    ->inject('request')
    ->action(function (Request $request) {

        $token = $request->getHeader('authorization');
        $token = str_replace(["Bearer"," "], "", $token);
        $jwt = new JWT(App::getEnv('_APP_OPENSSL_KEY_V1'), 'HS256', 600, 10);
        try {
            $payload = $jwt->decode($token);
        } catch (JWTException $error) {
            throw new Exception(Exception::USER_JWT_INVALID, 'Failed to verify JWT. ' . $error->getMessage());
        }
    });

App::post('/v1/edge/sync')
    ->desc('Purge cache keys')
    ->groups(['edge'])
    ->label('scope', 'public')
    ->param('keys', '', new ArrayList(new Text(9012), 600), 'Cache keys. an array containing alphanumerical cache keys')
    ->inject('request')
    ->inject('response')
    ->inject('queueForEdgeSyncIn')
    ->action(function (array $keys, Request $request, Response $response, Client $queueForEdgeSyncIn) {

        if (empty($keys)) {
            throw new Exception(Exception::KEY_NOT_FOUND);
        }

        $originEdgeUrl = $request->getHeader('origin-edge-url');
        $time = DateTime::now();

        var_dump('[' . $time . '] incoming request from:' . $originEdgeUrl);
        var_dump($keys);

        foreach ($keys as $parts) {
            $key = json_decode($parts);
            $queueForEdgeSyncIn
                ->enqueue([
                    'type' => $key->type,
                    'key'  => $key->key
                ]);
        }

        $response->dynamic(new Document([
            'keys' => $keys
        ]), Response::MODEL_EDGE_SYNC);
    });

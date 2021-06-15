<?php

use Appwrite\GraphQL\Builder;
use GraphQL\GraphQL;
use GraphQL\Type;
use Appwrite\Utopia\Response;
use GraphQL\Error\DebugFlag;
use Utopia\App;


App::post('/v1/graphql')
    ->desc('GraphQL Endpoint')
    ->label('scope', 'graphql')
    ->inject('request')
    ->inject('response')
    ->inject('schema')
    ->inject('utopia')
    ->inject('register')
    ->middleware(true) 
    ->action(function ($request, $response, $schema, $utopia, $register) {
        /** @var Utopia\Swoole\Request $request */
        /** @var Appwrite\Utopia\Response $response */
        /** @var Type\Schema $schema */
        /** @var Utopia\App $utopia */
        /** @var Utopia\Registry\Registry $register */

        $query = $request->getPayload('query', '');
        $variables = $request->getPayload('variables', null);
        $response->setContentType(Response::CONTENT_TYPE_NULL);
        $register->set('__app', function() use ($utopia) {
            return $utopia;
        });
        $register->set('__response', function() use ($response) {
            return $response;
        });

        $isDevelopment = App::isDevelopment();
        $version = App::getEnv('_APP_VERSION', 'UNKNOWN');

        try {
            $debug = $isDevelopment ? ( DebugFlag::INCLUDE_DEBUG_MESSAGE | DebugFlag::INCLUDE_TRACE ) : DebugFlag::NONE;
            $rootValue = [];
            $result = GraphQL::executeQuery($schema, $query, $rootValue, null, $variables)
                                ->setErrorFormatter(Builder::getErrorFormatter($isDevelopment, $version));
            $output = $result->toArray($debug);
        } catch (\Exception $error) {
            $output = [
                'errors' => [
                    [
                        'message' => $error->getMessage().'xxx',
                        'code' => $error->getCode(),
                        'file' => $error->getFile(),
                        'line' => $error->getLine(),
                        'trace' => $error->getTrace(),
                    ]
                ]
            ];
        }
        $response->json($output);
    }
);

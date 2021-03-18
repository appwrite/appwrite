<?php

use Appwrite\GraphQL\Builder;
use GraphQL\GraphQL;
use GraphQL\Type;
use Appwrite\Utopia\Response;
use GraphQL\Error\DebugFlag;
use GraphQL\Error\Error;
use GraphQL\Error\FormattedError;
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

        $myErrorFormatter = function(Error $error) {
            $formattedError = FormattedError::createFromException($error); 
            var_dump("***** IN ERROR FORMATTER ******");
            $parentError = $error->getPrevious();
            $formattedError['code'] = $parentError->getCode();
            $formattedError['file'] = $parentError->getFile();
            $formattedError['version'] = App::getEnv('_APP_VERSION', 'UNKNOWN');
            $formattedError['line'] = $parentError->getLine();
            return $formattedError;
        };

        $query = $request->getPayload('query', '');
        $variables = $request->getPayload('variables', null);
        $response->setContentType(Response::CONTENT_TYPE_NULL);
        $register->set('__app', function() use ($utopia) {
            return $utopia;
        });
        $register->set('__response', function() use ($response) {
            return $response;
        });

        try {
            $debug = DebugFlag::INCLUDE_DEBUG_MESSAGE | DebugFlag::INCLUDE_TRACE;
            // $debug = DebugFlag::INCLUDE_DEBUG_MESSAGE | DebugFlag::RETHROW_INTERNAL_EXCEPTIONS;
            $rootValue = [];
            $result = GraphQL::executeQuery($schema, $query, $rootValue, null, $variables)
                                ->setErrorFormatter($myErrorFormatter);
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

<?php

use GraphQL\GraphQL;
use GraphQL\Type;
use Appwrite\Utopia\Response;
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
            // var_dump("***** IN ERROR FORMATTER ******");
            // var_dump("{$error->getMessage()}");
            // var_dump("{$error->getCode()}");
            // var_dump("{$error->getFile()}");
            // var_dump("{$error->getLine()}");
            // var_dump("{$error->getTrace()}");

            $formattedError['code'] = $error->getCode();
            $formattedError['file'] = $error->getFile();
            $formattedError['line'] = $error->getLine();
            // $formattedError['trace'] = $error->getTrace();
            return $formattedError;
        };

        $myErrorHandler = function(array $errors, callable $formatter) {
            // $errors = array_map( function ($error) {
            //     unset($error['trace']);
            // },$errors);
            // var_dump("**** In My Error Handler *****");
            // var_dump($errors);

            return array_map($formatter, $errors);
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
            $rootValue = [];
            $result = GraphQL::executeQuery($schema, $query, $rootValue, null, $variables)->setErrorFormatter($myErrorFormatter)->setErrorsHandler($myErrorHandler);
            $output = $result->toArray();
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

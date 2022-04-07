<?php

use Appwrite\Utopia\Response;
use GraphQL\Error\DebugFlag;
use GraphQL\Executor\ExecutionResult;
use GraphQL\GraphQL;
use GraphQL\Type;
use Utopia\App;

App::post('/v1/graphql')
    ->desc('GraphQL Endpoint')
    ->groups(['api', 'grapgql'])
    ->label('scope', 'graphql')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'graphql')
    ->label('sdk.method', 'execute')
    ->label('sdk.description', '/docs/references/graphql/execute.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_ANY)
    ->label('abuse-limit', 60)
    ->inject('request')
    ->inject('response')
    ->inject('schema')
    ->inject('utopia')
    ->inject('register')
    ->inject('dbForProject')
    ->inject('promiseAdapter')
    ->middleware(true)
    ->action(function ($request, $response, $schema, $utopia, $register, $dbForProject, $promiseAdapter) {
        /** @var Utopia\Swoole\Request $request */
        /** @var Appwrite\Utopia\Response $response */
        /** @var Type\Schema $schema */
        /** @var Utopia\App $utopia */
        /** @var Utopia\Registry\Registry $register */
        /** @var \Utopia\Database\Database $dbForProject */

        $query = $request->getPayload('query', '');
        $variables = $request->getPayload('variables');
        $response->setContentType(Response::CONTENT_TYPE_NULL);

        $register->set('__app', function () use ($utopia) {
            return $utopia;
        });
        $register->set('__response', function () use ($response) {
            return $response;
        });

        $isDevelopment = App::isDevelopment();

        $debugFlags = $isDevelopment
            ? DebugFlag::INCLUDE_DEBUG_MESSAGE | DebugFlag::INCLUDE_TRACE
            : DebugFlag::NONE;
        $rootValue = [];

        GraphQL::promiseToExecute(
        $validations = array_merge(
            GraphQL::getStandardValidationRules(),
            [
                new QueryComplexity(App::getEnv('_APP_GRAPHQL_MAX_QUERY_COMPLEXITY', 200)),
                new QueryDepth(App::getEnv('_APP_GRAPHQL_MAX_QUERY_DEPTH', 3)),
                new DisableIntrospection(),
            ]
        );
            $promiseAdapter,
            $schema,
            $query,
            $rootValue,
            null,
            $variables
        )->then(function (ExecutionResult $result) use ($response, $debugFlags) {
            $response->json($result->toArray($debugFlags));
        });
            validationRules: $validations
    });

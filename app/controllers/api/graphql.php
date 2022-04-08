<?php

use Appwrite\GraphQL\Builder;
use Appwrite\Utopia\Response;
use GraphQL\Error\DebugFlag;
use GraphQL\GraphQL;
use GraphQL\Type;
use GraphQL\Validator\Rules\QueryComplexity;
use GraphQL\Validator\Rules\QueryDepth;
use Swoole\Coroutine\WaitGroup;
use Utopia\App;
use Utopia\CLI\Console;
use Utopia\Validator\JSON;
use Utopia\Validator\Text;

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
    ->label('abuse-time', 60)
    ->param('query', '', new Text(1024), 'The query to execute. Max 1024 chars.')
    ->param('operationName', null, new Text(256), 'Name of the operation to execute', true)
    ->param('variables', [], new JSON(), 'Variables to use in the operation', true)
    ->inject('request')
    ->inject('response')
    ->inject('utopia')
    ->inject('register')
    ->inject('dbForProject')
    ->inject('promiseAdapter')
    ->inject('apiSchema')
    ->action(function ($query, $operationName, $variables, $request, $response, $utopia, $register, $dbForProject, $promiseAdapter, $apiSchema) {
        /** @var Utopia\Swoole\Request $request */
        /** @var Appwrite\Utopia\Response $response */
        /** @var Type\Schema $schema */
        /** @var Utopia\App $utopia */
        /** @var Utopia\Registry\Registry $register */
        /** @var \Utopia\Database\Database $dbForProject */

        $start = microtime(true);

        // Should allow accepting entire body as query if content-type is application/graphql
        if ($request->getHeader('content-type') === 'application/graphql') {
            $query = \implode("\r\n", $request->getParams());
        }

        $debugFlags = App::isDevelopment()
            ? DebugFlag::INCLUDE_DEBUG_MESSAGE | DebugFlag::INCLUDE_TRACE | DebugFlag::RETHROW_INTERNAL_EXCEPTIONS
            : DebugFlag::NONE;

        $validations = array_merge(
            GraphQL::getStandardValidationRules(),
            [
                new QueryComplexity(App::getEnv('_APP_GRAPHQL_MAX_QUERY_COMPLEXITY', 200)),
                new QueryDepth(App::getEnv('_APP_GRAPHQL_MAX_QUERY_DEPTH', 3)),
                //new DisableIntrospection(),
            ]
        );

        $schema = Builder::appendProjectSchema(
            $apiSchema,
            $register,
            $dbForProject
        );

        $promise = GraphQL::promiseToExecute(
            $promiseAdapter,
            $schema,
            $query,
            variableValues: $variables,
            operationName: $operationName,
            validationRules: $validations
        );

        // Blocking wait while queries resolve asynchronously
        $wg = new WaitGroup();
        $wg->add();
        $promise->then(
            function ($result) use ($response, $debugFlags, $wg) {
                $response->json($result->toArray($debugFlags));
                $wg->done();
            },
            function ($error) use ($response, $wg) {
                $response->text(\json_encode(['errors' => [\json_encode($error)]]));
                $wg->done();
            }
        );
        $wg->wait(App::getEnv('_APP_GRAPHQL_REQUEST_TIMEOUT', 30));

        $time_elapsed_secs = (microtime(true) - $start) * 1000;
        Console::info("[DEBUG] GraphQL Action Time: {$time_elapsed_secs}ms");
    });

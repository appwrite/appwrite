<?php

use Appwrite\Extend\Exception;
use Appwrite\GraphQL\CoroutinePromiseAdapter;
use Appwrite\Utopia\Response;
use GraphQL\Error\DebugFlag;
use GraphQL\GraphQL;
use GraphQL\Type;
use GraphQL\Validator\Rules\DisableIntrospection;
use GraphQL\Validator\Rules\QueryComplexity;
use GraphQL\Validator\Rules\QueryDepth;
use Swoole\Coroutine\WaitGroup;
use Utopia\App;
use Utopia\Validator\JSON;
use Utopia\Validator\Text;

App::get('/v1/graphql')
    ->desc('GraphQL Endpoint')
    ->groups(['grapgql'])
    ->label('scope', 'graphql')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'graphql')
    ->label('sdk.method', 'query')
    ->label('sdk.description', '/docs/references/graphql/query.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_ANY)
    ->label('abuse-limit', 60)
    ->label('abuse-time', 60)
    ->param('query', '', new Text(4096), 'The query to execute. Max 1024 chars.')
    ->param('operationName', null, new Text(256), 'Name of the operation to execute', true)
    ->param('variables', [], new JSON(), 'Variables to use in the operation', true)
    ->inject('request')
    ->inject('response')
    ->inject('utopia')
    ->inject('register')
    ->inject('dbForProject')
    ->inject('promiseAdapter')
    ->inject('gqlSchema')
    ->action(Closure::fromCallable('graphqlRequest'));

App::post('/v1/graphql')
    ->desc('GraphQL Endpoint')
    ->groups(['grapgql'])
    ->label('scope', 'graphql')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'graphql')
    ->label('sdk.method', 'mutate')
    ->label('sdk.description', '/docs/references/graphql/mutate.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_ANY)
    ->label('abuse-limit', 60)
    ->label('abuse-time', 60)
    ->param('query', '', new Text(4096), 'The query to execute. Max 1024 chars.', true)
    ->param('operationName', null, new Text(256), 'Name of the operation to execute', true)
    ->param('variables', [], new JSON(), 'Variables to use in the operation', true)
    ->param('operations', '', new Text(4096), 'Variables to use in the operation', true)
    ->param('map', '', new Text(1024), 'Variables to use in the operation', true)
    ->inject('request')
    ->inject('response')
    ->inject('promiseAdapter')
    ->inject('gqlSchema')
    ->action(Closure::fromCallable('graphqlRequest'));

/**
 * @throws Exception
 * @throws \Exception
 */
function graphqlRequest(
    string $query,
    ?string $operationName,
    ?array $variables,
    ?string $operations,
    ?string $map,
    Appwrite\Utopia\Request $request,
    Appwrite\Utopia\Response $response,
    CoroutinePromiseAdapter $promiseAdapter,
    Type\Schema $gqlSchema
): void {
    $contentType = $request->getHeader('content-type');

    if ($contentType === 'application/graphql') {
        $query = $request->getSwoole()->rawContent();
    }

    if (\str_starts_with($contentType, 'multipart/form-data')) {
        $map = \json_decode($map, true);
        $operations = \json_decode($operations, true);
        foreach ($map as $fileKey => $locations) {
            foreach ($locations as $location) {
                $items = &$operations;
                foreach (explode('.', $location) as $key) {
                    if (!isset($items[$key]) || !is_array($items[$key])) {
                        $items[$key] = [];
                    }
                    $items = &$items[$key];
                }
                $items = $request->getFiles($fileKey);
            }
        }
        $query = $operations['query'];
        $variables = $operations['variables'];
    }

    if (empty($query)) {
        throw new Exception('No query supplied.', 400, Exception::GRAPHQL_NO_QUERY);
    }

    $maxComplexity = App::getEnv('_APP_GRAPHQL_MAX_QUERY_COMPLEXITY', 200);
    $maxDepth = App::getEnv('_APP_GRAPHQL_MAX_QUERY_DEPTH', 3);

    $validations = GraphQL::getStandardValidationRules();
    $validations[] = new QueryComplexity($maxComplexity);
    $validations[] = new QueryDepth($maxDepth);

    if (App::isProduction()) {
        $validations[] = new DisableIntrospection();
        $debugFlags = DebugFlag::INCLUDE_DEBUG_MESSAGE | DebugFlag::INCLUDE_TRACE;
    } else {
        $debugFlags = DebugFlag::NONE;
    }

    $promise = GraphQL::promiseToExecute(
        $promiseAdapter,
        $gqlSchema,
        $query,
        variableValues: $variables,
        operationName: $operationName,
        validationRules: $validations
    );

    $output = [];
    $wg = new WaitGroup();
    $wg->add();
    $promise->then(
        function ($result) use ($response, &$output, $wg, $debugFlags) {
            $output = $result->toArray($debugFlags);
            $wg->done();
        },
        function ($error) use ($response, &$output, $wg) {
            $output = ['errors' => [$error]];
            $wg->done();
        }
    );
    $wg->wait();

    $response->json($output);
}

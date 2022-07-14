<?php

use Appwrite\Extend\Exception;
use Appwrite\GraphQL\Promises\CoroutinePromiseAdapter;
use Appwrite\Utopia\Request;
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
    ->param('query', '', new JSON(), 'The query or queries to execute.', fullBody: true)
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
    ->param('query', '', new JSON(), 'The query or queries to execute.', fullBody: true)
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
    array $query,
    Appwrite\Utopia\Request $request,
    Appwrite\Utopia\Response $response,
    CoroutinePromiseAdapter $promiseAdapter,
    Type\Schema $gqlSchema
): void {
    $contentType = $request->getHeader('content-type');
    $maxBatchSize = App::getEnv('_APP_GRAPHQL_MAX_BATCH_SIZE', 50);
    $maxComplexity = App::getEnv('_APP_GRAPHQL_MAX_QUERY_COMPLEXITY', 200);
    $maxDepth = App::getEnv('_APP_GRAPHQL_MAX_QUERY_DEPTH', 3);

    if (\str_starts_with($contentType, 'application/graphql')) {
        $query = parseGraphqlRequest($request);
    }
    if (\str_starts_with($contentType, 'multipart/form-data')) {
        $query = parseMultipartRequest($query, $request);
    }
    if (!\isset($query[0])) {
        $query = [$query];
    }
    if (\empty($query)) {
        throw new Exception('No query supplied.', 400, Exception::GRAPHQL_NO_QUERY);
    }
    if (\count($query) > $maxBatchSize) {
        throw new Exception('Too many queries in batch.', 400, Exception::GRAPHQL_TOO_MANY_QUERIES);
    }

    $debugFlags = DebugFlag::INCLUDE_DEBUG_MESSAGE | DebugFlag::INCLUDE_TRACE;
    $validations = GraphQL::getStandardValidationRules();
    $validations[] = new QueryComplexity($maxComplexity);
    $validations[] = new QueryDepth($maxDepth);
    
    if (App::isProduction()) {
        $validations[] = new DisableIntrospection();
        $debugFlags = DebugFlag::NONE;
    }

    $promises = [];
    foreach ($query as $indexed) {
        $promises[] = GraphQL::promiseToExecute(
            $promiseAdapter,
            $gqlSchema,
            $indexed['query'],
            variableValues: $indexed['variables'] ?? null,
            operationName: $indexed['operationName'] ?? null,
            validationRules: $validations
        );
    }

    $output = [];
    $wg = new WaitGroup();
    $wg->add();
    $promiseAdapter->all($promises)->then(
        function (array $results) use (&$output, &$wg, $debugFlags) {
            processResult($results, $output, $debugFlags);
            $wg->done();
        },
        function ($error) use (&$output, $wg) {
            $output = ['errors' => [$error]];
            $wg->done();
        }
    );
    $wg->wait();

    $response->json($output);
}

/**
 * Parse an application/graphql request
 *
 * @param Request $request
 * @return array
 */
function parseGraphqlRequest(Request $request): array
{
    return [ 'query' => $request->getSwoole()->rawContent() ];
}

/**
 * Parse a multipart/form-data request
 *
 * @param array $query
 * @param Request $request
 * @return array
 */
function parseMultipartRequest(array $query, Request $request): array
{
    $operations = \json_decode($query['operations'], true);
    $map = \json_decode($query['map'], true);
    foreach ($map as $fileKey => $locations) {
        foreach ($locations as $location) {
            $items = &$operations;
            foreach (\explode('.', $location) as $key) {
                if (!isset($items[$key]) || !\is_array($items[$key])) {
                    $items[$key] = [];
                }
                $items = &$items[$key];
            }
            $items = $request->getFiles($fileKey);
        }
    }
    $query['query'] = $operations['query'];
    $query['variables'] = $operations['variables'];

    return $query;
}

/**
 * Process an array of results for output
 *
 * @param $result
 * @param $output
 * @param $debugFlags
 * @return void
 */
function processResult($result, &$output, $debugFlags): void
{
    if (!isset($result[1])) {
        $output = $result[0]->toArray($debugFlags);
    } else {
        $output = \array_merge_recursive(...\array_map(
            fn ($item) => $item->toArray($debugFlags),
            $result
        ));
    }
}

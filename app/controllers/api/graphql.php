<?php

use Appwrite\Extend\Exception;
use Appwrite\GraphQL\Promises\Adapter;
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
    ->groups(['graphql'])
    ->label('scope', 'graphql')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'graphql')
    ->label('sdk.method', 'query')
    ->label('sdk.methodType', 'graphql')
    ->label('sdk.description', '/docs/references/graphql/query.md')
    ->label('sdk.parameters', [
        'query' => ['default' => '', 'validator' => new JSON(), 'description' => 'The query or queries to execute.', 'optional' => false],
    ])
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_ANY)
    ->label('abuse-limit', 60)
    ->label('abuse-time', 60)
    ->inject('request')
    ->inject('response')
    ->inject('promiseAdapter')
    ->inject('schema')
    ->action(Closure::fromCallable('executeRequest'));

App::post('/v1/graphql')
    ->desc('GraphQL Endpoint')
    ->groups(['graphql'])
    ->label('scope', 'graphql')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'graphql')
    ->label('sdk.method', 'mutate')
    ->label('sdk.methodType', 'graphql')
    ->label('sdk.description', '/docs/references/graphql/mutate.md')
    ->label('sdk.parameters', [
        'query' => ['default' => '', 'validator' => new JSON(), 'description' => 'The query or queries to execute.', 'optional' => false],
    ])
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_ANY)
    ->label('abuse-limit', 60)
    ->label('abuse-time', 60)
    ->inject('request')
    ->inject('response')
    ->inject('promiseAdapter')
    ->inject('schema')
    ->action(Closure::fromCallable('executeRequest'));

/**
 * Execute a GraphQL request
 *
 * @param Request $request
 * @param Response $response
 * @param Adapter $promiseAdapter
 * @param Type\Schema $schema
 * @return void
 * @throws Exception
 */
function executeRequest(
    Request $request,
    Response $response,
    Adapter $promiseAdapter,
    Type\Schema $schema
): void {
    $query = $request->getParams();
    $contentType = $request->getHeader('content-type');

    if ($request->getHeader('x-sdk-graphql') == 'true') {
        $query = $query['query'];
    }

    $maxBatchSize = App::getEnv('_APP_GRAPHQL_MAX_BATCH_SIZE', 10);
    $maxComplexity = App::getEnv('_APP_GRAPHQL_MAX_COMPLEXITY', 250);
    $maxDepth = App::getEnv('_APP_GRAPHQL_MAX_DEPTH', 3);

    if (\str_starts_with($contentType, 'application/graphql')) {
        $query = parseGraphql($request);
    }
    if (\str_starts_with($contentType, 'multipart/form-data')) {
        $query = parseMultipart($query, $request);
    }
    if (!empty($query) && !isset($query[0])) {
        $query = [$query];
    }
    if (empty($query)) {
        throw new Exception(Exception::GRAPHQL_NO_QUERY);
    }
    if (\count($query) > $maxBatchSize) {
        throw new Exception(Exception::GRAPHQL_TOO_MANY_QUERIES);
    }
    foreach ($query as $item) {
        if (empty($item['query'])) {
            throw new Exception(Exception::GRAPHQL_NO_QUERY);
        }
    }

    $flags = DebugFlag::INCLUDE_DEBUG_MESSAGE | DebugFlag::INCLUDE_TRACE;
    $validations = GraphQL::getStandardValidationRules();

    if (App::isProduction()) {
        $validations[] = new DisableIntrospection();
        $validations[] = new QueryComplexity($maxComplexity);
        $validations[] = new QueryDepth($maxDepth);
        $flags = DebugFlag::NONE;
    }

    $promises = [];
    foreach ($query as $indexed) {
        $promises[] = GraphQL::promiseToExecute(
            $promiseAdapter,
            $schema,
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
        function (array $results) use (&$output, &$wg, $flags) {
            try {
                $output = processResult($results, $flags);
            } finally {
                $wg->done();
            }
        }
    );
    $wg->wait();

    $response
        ->setStatusCode(Response::STATUS_CODE_OK)
        ->json($output);
}

/**
 * Parse an application/graphql request
 *
 * @param Request $request
 * @return array
 */
function parseGraphql(Request $request): array
{
    return [ 'query' => $request->getRawPayload() ];
}

/**
 * Parse a multipart/form-data request
 *
 * @param array $query
 * @param Request $request
 * @return array
 */
function parseMultipart(array $query, Request $request): array
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
 * Process an array of results for output.
 *
 * @param $result
 * @param $debugFlags
 * @return array
 */
function processResult($result, $debugFlags): array
{
    if (!isset($result[1])) {
        return $result[0]->toArray($debugFlags);
    }

    return \array_merge_recursive(...\array_map(
        static fn ($item) => $item->toArray($debugFlags),
        $result
    ));
}

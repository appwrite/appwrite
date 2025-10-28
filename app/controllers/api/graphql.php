<?php

use Appwrite\Auth\Auth;
use Appwrite\Extend\Exception;
use Appwrite\Extend\Exception as AppwriteException;
use Appwrite\GraphQL\Promises\Adapter;
use Appwrite\GraphQL\Schema;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\MethodType;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use GraphQL\Error\DebugFlag;
use GraphQL\GraphQL;
use GraphQL\Type\Schema as GQLSchema;
use GraphQL\Validator\Rules\DisableIntrospection;
use GraphQL\Validator\Rules\QueryComplexity;
use GraphQL\Validator\Rules\QueryDepth;
use Swoole\Coroutine\WaitGroup;
use Utopia\App;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\System\System;
use Utopia\Validator\JSON;
use Utopia\Validator\Text;

App::init()
    ->groups(['graphql'])
    ->inject('project')
    ->action(function (Document $project) {
        if (
            array_key_exists('graphql', $project->getAttribute('apis', []))
            && !$project->getAttribute('apis', [])['graphql']
            && !(Auth::isPrivilegedUser(Authorization::getRoles()) || Auth::isAppUser(Authorization::getRoles()))
        ) {
            throw new AppwriteException(AppwriteException::GENERAL_API_DISABLED);
        }
    });

App::get('/v1/graphql')
    ->desc('GraphQL endpoint')
    ->groups(['graphql'])
    ->label('scope', 'graphql')
    ->label('sdk', new Method(
        namespace: 'graphql',
        group: 'graphql',
        name: 'get',
        auth: [AuthType::KEY, AuthType::SESSION, AuthType::JWT],
        hide: true,
        description: '/docs/references/graphql/get.md',
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_ANY,
            )
        ]
    ))
    ->label('abuse-limit', 60)
    ->label('abuse-time', 60)
    ->param('query', '', new Text(0, 0), 'The query to execute.')
    ->param('operationName', '', new Text(256), 'The name of the operation to execute.', true)
    ->param('variables', '', new Text(0), 'The JSON encoded variables to use in the query.', true)
    ->inject('request')
    ->inject('response')
    ->inject('schema')
    ->inject('promiseAdapter')
    ->action(function (string $query, string $operationName, string $variables, Request $request, Response $response, GQLSchema $schema, Adapter $promiseAdapter) {
        $query = [
            'query' => $query,
        ];

        if (!empty($operationName)) {
            $query['operationName'] = $operationName;
        }

        if (!empty($variables)) {
            $query['variables'] = \json_decode($variables, true);
        }

        $output = execute($schema, $promiseAdapter, $query);

        $response
            ->setStatusCode(Response::STATUS_CODE_OK)
            ->json($output);
    });

App::post('/v1/graphql/mutation')
    ->desc('GraphQL endpoint')
    ->groups(['graphql'])
    ->label('scope', 'graphql')
    ->label('sdk', new Method(
        namespace: 'graphql',
        group: 'graphql',
        name: 'mutation',
        auth: [AuthType::KEY, AuthType::SESSION, AuthType::JWT],
        description: '/docs/references/graphql/post.md',
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_ANY,
            )
        ],
        type: MethodType::GRAPHQL,
        additionalParameters: [
            'query' => ['default' => [], 'validator' => new JSON(), 'description' => 'The query or queries to execute.', 'optional' => false],
        ],
    ))
    ->label('abuse-limit', 60)
    ->label('abuse-time', 60)
    ->inject('request')
    ->inject('response')
    ->inject('schema')
    ->inject('promiseAdapter')
    ->action(function (Request $request, Response $response, GQLSchema $schema, Adapter $promiseAdapter) {
        $query = $request->getParams();

        if ($request->getHeader('x-sdk-graphql') == 'true') {
            $query = $query['query'];
        }

        $type = $request->getHeader('content-type');

        if (\str_starts_with($type, 'application/graphql')) {
            $query = parseGraphql($request);
        }

        if (\str_starts_with($type, 'multipart/form-data')) {
            $query = parseMultipart($query, $request);
        }

        $output = execute($schema, $promiseAdapter, $query);

        $response
            ->setStatusCode(Response::STATUS_CODE_OK)
            ->json($output);
    });

App::post('/v1/graphql')
    ->desc('GraphQL endpoint')
    ->groups(['graphql'])
    ->label('scope', 'graphql')
    ->label('sdk', new Method(
        namespace: 'graphql',
        group: 'graphql',
        name: 'query',
        auth: [AuthType::KEY, AuthType::SESSION, AuthType::JWT],
        description: '/docs/references/graphql/post.md',
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_ANY,
            )
        ],
        type: MethodType::GRAPHQL,
        additionalParameters: [
            'query' => ['default' => [], 'validator' => new JSON(), 'description' => 'The query or queries to execute.', 'optional' => false],
        ],
    ))
    ->label('abuse-limit', 60)
    ->label('abuse-time', 60)
    ->inject('request')
    ->inject('response')
    ->inject('schema')
    ->inject('promiseAdapter')
    ->action(function (Request $request, Response $response, GQLSchema $schema, Adapter $promiseAdapter) {
        $query = $request->getParams();

        if ($request->getHeader('x-sdk-graphql') == 'true') {
            $query = $query['query'];
        }

        $type = $request->getHeader('content-type');

        if (\str_starts_with($type, 'application/graphql')) {
            $query = parseGraphql($request);
        }

        if (\str_starts_with($type, 'multipart/form-data')) {
            $query = parseMultipart($query, $request);
        }

        $output = execute($schema, $promiseAdapter, $query);

        $response
            ->setStatusCode(Response::STATUS_CODE_OK)
            ->json($output);
    });

/**
 * Execute a GraphQL request
 *
 * @param GQLSchema $schema
 * @param Adapter $promiseAdapter
 * @param array $query
 * @return array
 * @throws Exception
 */
function execute(
    GQLSchema $schema,
    Adapter $promiseAdapter,
    array $query
): array {
    $maxBatchSize = System::getEnv('_APP_GRAPHQL_MAX_BATCH_SIZE', 10);
    $maxComplexity = System::getEnv('_APP_GRAPHQL_MAX_COMPLEXITY', 250);
    $maxDepth = System::getEnv('_APP_GRAPHQL_MAX_DEPTH', 3);

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

    if (System::getEnv('_APP_OPTIONS_ABUSE', 'enabled') !== 'disabled') {
        $validations[] = new DisableIntrospection();
        $validations[] = new QueryComplexity($maxComplexity);
        $validations[] = new QueryDepth($maxDepth);
    }
    if (App::getMode() === App::MODE_TYPE_PRODUCTION) {
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

    return $output;
}

/**
 * Parse an "application/graphql" type request
 *
 * @param Request $request
 * @return array
 */
function parseGraphql(Request $request): array
{
    return ['query' => $request->getRawPayload()];
}

/**
 * Parse an "multipart/form-data" type request
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

    unset($query['operations']);
    unset($query['map']);

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
    // Only one query, return the result
    if (!isset($result[1])) {
        return $result[0]->toArray($debugFlags);
    }

    // Batched queries, return an array of results
    return \array_map(
        static function ($item) use ($debugFlags) {
            return $item->toArray($debugFlags);
        },
        $result
    );
}

App::shutdown()
    ->groups(['schema'])
    ->inject('project')
    ->action(function (Document $project) {
        Schema::setDirty($project->getId());
    });

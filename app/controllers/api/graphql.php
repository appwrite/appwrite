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
use Utopia\Validator\ArrayList;
use Utopia\Validator\JSON;
use Utopia\Validator\Text;
use Utopia\Storage\Validator\File;

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
    ->param('query', '', new Text(1024), 'The query to execute. Max 1024 chars.')
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
    ->param('query', '', new Text(1024), 'The query to execute. Max 1024 chars.', true)
    ->param('operationName', null, new Text(256), 'Name of the operation to execute', true)
    ->param('variables', [], new JSON(), 'Variables to use in the operation', true)
    ->param('operations', '', new Text(1024), 'Variables to use in the operation', true)
    ->param('map', '', new Text(1024), 'Variables to use in the operation', true)
    ->param('files', [], new ArrayList(new File()), 'Files to upload. Use a path that is relative to the current directory.', true)
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
    ?array $files,
    Appwrite\Utopia\Request $request,
    Appwrite\Utopia\Response $response,
    CoroutinePromiseAdapter $promiseAdapter,
    Type\Schema $gqlSchema
): void
{
    if ($request->getHeader('content-type') === 'application/graphql') {
        // TODO: Add getRawContent() method to Request
        $query = $request->getSwoole()->rawContent();
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

    $map = \json_decode($map, true);
    $result = \json_decode($operations, true);
    if ($request->getHeader('content-type') === 'multipart/form-data') {
        foreach ($map as $fileKey => $locations) {
            foreach ($locations as $location) {
                $items = &$result;
                foreach (explode('.', $location) as $key) {
                    if (!isset($items[$key]) || !is_array($items[$key])) {
                        $items[$key] = [];
                    }
                    $items = &$items[$key];
                }
                $items = $request->getFiles($fileKey);
            }
        }
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

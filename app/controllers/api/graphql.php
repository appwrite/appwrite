<?php

use Appwrite\GraphQL\Builder;
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
    ->groups(['api', 'grapgql'])
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
    ->groups(['api', 'grapgql'])
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
    ->inject('request')
    ->inject('response')
    ->inject('utopia')
    ->inject('dbForProject')
    ->inject('promiseAdapter')
    ->inject('gqlSchema')
    ->action(Closure::fromCallable('graphqlRequest'));

/**
 * @throws Exception
 */
function graphqlRequest(
    $query,
    $operationName,
    $variables,
    $request,
    $response,
    $utopia,
    $dbForProject,
    $promiseAdapter,
    $gqlSchema
)
{
    /** @var Appwrite\Utopia\Request $request */
    /** @var Appwrite\Utopia\Response $response */
    /** @var Type\Schema $schema */
    /** @var Utopia\App $utopia */
    /** @var Utopia\Registry\Registry $register */
    /** @var \Utopia\Database\Database $dbForProject */

    if ($request->getHeader('content-type') === 'application/graphql') {
        // TODO: Add getRawContent() method to Request
        $query = $request->getSwoole()->rawContent();
    }
    if (empty($query)) {
        throw new Exception('Query is empty', Response::STATUS_CODE_BAD_REQUEST);
    }

    $debugFlags = App::isDevelopment()
        ? DebugFlag::INCLUDE_DEBUG_MESSAGE | DebugFlag::INCLUDE_TRACE | DebugFlag::RETHROW_INTERNAL_EXCEPTIONS
        : DebugFlag::NONE;

    $validations = array_merge(
        GraphQL::getStandardValidationRules(),
        [
            new QueryComplexity(App::getEnv('_APP_GRAPHQL_MAX_QUERY_COMPLEXITY', 200)),
            new QueryDepth(App::getEnv('_APP_GRAPHQL_MAX_QUERY_DEPTH', 3)),
        ]
    );

    if (App::isProduction()) {
        $validations[] = new DisableIntrospection();
    }

    $promise = GraphQL::promiseToExecute(
        $promiseAdapter,
        $gqlSchema,
        $query,
        variableValues: $variables,
        operationName: $operationName,
        validationRules: $validations
    );

    // Blocking wait while queries resolve asynchronously
    $wg = new WaitGroup();
    $wg->add();
    $promise->then(function ($result) use ($response, $debugFlags, $wg) {
        $result = $result->toArray($debugFlags);
        \var_dump("Result:" . $result);
        if (isset($result['errors'])) {
            $response->json(['data' => [], ...$result]);
            $wg->done();
            return;
        }
        $response->json(['data' => $result]);
        $wg->done();
    },
    function ($error) use ($response, $wg) {
        $response->text(\json_encode(['errors' => [\json_encode($error)]]));
        $wg->done();
    });
    $wg->wait();
}

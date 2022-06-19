<?php

use Appwrite\Extend\Exception;
use Appwrite\Utopia\Response;
use GraphQL\Error\DebugFlag;
use GraphQL\Executor\ExecutionResult;
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

use Appwrite\Extend\Exception;
use Utopia\App;

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
    ->inject('promiseAdapter')
    ->inject('gqlSchema')
    ->action(Closure::fromCallable('graphqlRequest'));

/**
 * @throws Exception
 * @throws \Exception
 */
function graphqlRequest(
    $query,
    $operationName,
    $variables,
    $request,
    $response,
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
        throw new Exception('No query supplied.', 400, Exception::GRAPHQL_NO_QUERY);
    }

    $maxComplexity = App::getEnv('_APP_GRAPHQL_MAX_QUERY_COMPLEXITY', 200);
    $maxDepth = App::getEnv('_APP_GRAPHQL_MAX_QUERY_DEPTH', 3);

    $validations = GraphQL::getStandardValidationRules();
    $validations[] = new QueryComplexity($maxComplexity);
    $validations[] = new QueryDepth($maxDepth);
    $validations[] = new DisableIntrospection();

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
        function ($result) use ($response, &$output, $wg) {
            $output = $result->toArray();
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

<?php

namespace Appwrite\Platform\Modules\GraphQL\Http\GraphQL\Mutation;

use Appwrite\Extend\Exception;
use Appwrite\GraphQL\Promises\Adapter;
use Appwrite\Platform\Action;
use Appwrite\Platform\Modules\GraphQL\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\MethodType;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use GraphQL\Type\Schema as GQLSchema;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\JSON;

class Create extends Base
{
    use HTTP;

    public static function getName(): string
    {
        return 'createMutation';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/graphql/mutation')
            ->desc('GraphQL endpoint')
            ->groups(['graphql'])
            ->label('scope', 'graphql')
            ->label('sdk', new Method(
                namespace: 'graphql',
                group: 'graphql',
                name: 'mutation',
                auth: [AuthType::ADMIN, AuthType::KEY, AuthType::SESSION, AuthType::JWT],
                description: '/docs/references/graphql/post.md',
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_ANY,
                    )
                ],
                type: MethodType::GRAPHQL,
                additionalParameters: [
                    'query' => ['default' => [], 'validator' => new JSON(), 'description' => 'The query or queries to execute.', 'optional' => false, 'example' => '{"query":"mutation { accountUpdateName(name: \"Walter\") { name } }"}'],
                ],
            ))
            ->label('abuse-limit', 60)
            ->label('abuse-time', 60)
            ->inject('request')
            ->inject('response')
            ->inject('schema')
            ->inject('promiseAdapter')
            ->callback($this->action(...));
    }

    public function action(Request $request, Response $response, GQLSchema $schema, Adapter $promiseAdapter): void
    {
        $query = $request->getParams();

        if ($request->getHeaderLine('x-sdk-graphql') == 'true') {
            $query = $query['query'] ?? [];

            // JSON `{}` is decoded as stdClass; the executor requires an array.
            if ($query instanceof \stdClass) {
                $query = \get_object_vars($query);
            }
            if (!\is_array($query)) {
                throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'The query must be a JSON object or an array of JSON objects, such as {"query": "...", "variables": {}}.');
            }
        }

        $type = $request->getHeaderLine('content-type');

        if (\str_starts_with($type, 'application/graphql')) {
            $query = $this->parseGraphql($request);
        }

        if (\str_starts_with($type, 'multipart/form-data')) {
            $query = $this->parseMultipart($query, $request);
        }

        $output = $this->execute($schema, $promiseAdapter, $query);

        $response
            ->setStatusCode(Response::STATUS_CODE_OK)
            ->json($output);
    }
}

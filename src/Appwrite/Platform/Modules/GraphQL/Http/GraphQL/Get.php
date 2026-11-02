<?php

namespace Appwrite\Platform\Modules\GraphQL\Http\GraphQL;

use Appwrite\Extend\Exception;
use Appwrite\GraphQL\Promises\Adapter;
use Appwrite\Platform\Action;
use Appwrite\Platform\Modules\GraphQL\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use GraphQL\Type\Schema as GQLSchema;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;
use Utopia\Validator\Text;

class Get extends Base
{
    use HTTP;

    public static function getName(): string
    {
        return 'get';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/graphql')
            ->desc('GraphQL endpoint')
            ->groups(['graphql'])
            ->label('scope', 'graphql')
            ->label('sdk', new Method(
                namespace: 'graphql',
                group: 'graphql',
                name: 'get',
                auth: [AuthType::ADMIN, AuthType::KEY, AuthType::SESSION, AuthType::JWT],
                // Preview SDK builds show the whole surface, so they do not hide.
                hide: System::getEnv('_APP_SDK_PREVIEW', 'disabled') !== 'enabled',
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
            ->param('query', '', new Text(0, 0), 'The query to execute.', example: 'query { localeGet { ip } }')
            ->param('operationName', '', new Text(256), 'The name of the operation to execute.', true)
            ->param('variables', '', new Text(0), 'The JSON encoded variables to use in the query.', true)
            ->inject('request')
            ->inject('response')
            ->inject('schema')
            ->inject('promiseAdapter')
            ->callback($this->action(...));
    }

    public function action(string $query, string $operationName, string $variables, Request $request, Response $response, GQLSchema $schema, Adapter $promiseAdapter): void
    {
        $query = [
            'query' => $query,
        ];

        if (!empty($operationName)) {
            $query['operationName'] = $operationName;
        }

        if (!empty($variables)) {
            $query['variables'] = \json_decode($variables, true);
        }

        try {
            $output = $this->execute($schema, $promiseAdapter, $query, readOnly: true);
        } catch (Exception $exception) {
            if ($exception->getType() === Exception::GRAPHQL_METHOD_UNSUPPORTED) {
                $response->addHeader('Allow', 'POST');
            }

            throw $exception;
        }

        $response
            ->setStatusCode(Response::STATUS_CODE_OK)
            ->json($output);
    }
}

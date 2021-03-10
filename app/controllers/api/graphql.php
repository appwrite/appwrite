<?php

use Appwrite\GraphQL\GraphQLBuilder;
use GraphQL\GraphQL;
use GraphQL\Type\Schema;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;
use Appwrite\GraphQL\Types\JsonType;
use GraphQL\Error\Error;
use GraphQL\Error\ClientAware;
use GraphQL\Error\FormattedError;
use GraphQL\Type\Definition\ListOfType;
use Utopia\App;

/**
 * TODO:
 *  1. Map all objects, object-params, object-fields
 *  2. Parse GraphQL request payload (use: https://github.com/webonyx/graphql-php)
 *  3. Route request to relevant controllers (of REST API?) / resolvers and aggergate data
 *  4. Handle scope authentication
 *  5. Handle errors
 *  6. Return response
 *  7. Write tests!
 * 
 * Demo
 *  curl -H "Content-Type: application/json" http://localhost/v1/graphql -d '{"query": "query { echo(message: \"Hello World\") }" }'
 *  
 * Explorers:
 *  - https://shopify.dev/tools/graphiql-admin-api
 *  - https://developer.github.com/v4/explorer/
 *  - http://localhost:4000
 * 
 * Docs
 *  - Overview
 *  - Clients
 * 
 *  - Queries
 *  - Mutations
 * 
 *  - Objects
 */

class MySafeException extends \Exception implements ClientAware
{
    public function isClientSafe()
    {
        return true;
    }

    public function getCategory()
    {
        return 'businessLogic';
    }
}

App::post('/v1/graphql')
    ->desc('GraphQL Endpoint')
    ->label('scope', 'graphql')
    ->inject('request')
    ->inject('response')
    ->inject('schema')
    ->middleware(false) 
    ->action(function ($request, $response, $schema) {

        // $myErrorFormatter = function(Error $error) {
        //     $formattedError = FormattedError::createFromException($error); 
        //     var_dump("***** IN ERROR FORMATTER ******");
        //     return $formattedError;
        // };

        $query = $request->getPayload('query', '');
        $variables = $request->getPayload('variables', null);
        $response->setContentType(Response::CONTENT_TYPE_NULL);

        try {
            $rootValue = [];
            $result = GraphQL::executeQuery($schema, $query, $rootValue, null, $variables);
            $output = $result->toArray();
        } catch (\Exception $error) {
            $output = [
                'errors' => [
                    [
                        'message' => $error->getMessage().'xxx',
                        'code' => $error->getCode(),
                        'file' => $error->getFile(),
                        'line' => $error->getLine(),
                        'trace' => $error->getTrace(),
                    ]
                ]
            ];
        }
        $response->json($output);
        }
    );

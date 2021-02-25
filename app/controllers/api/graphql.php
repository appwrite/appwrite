<?php

use GraphQL\GraphQL;
use GraphQL\Type\Schema;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;
use GraphQL\Type\Definition\NonNull;
use Utopia\App;
use Utopia\Validator;

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

global $typeMapping;

$typeMapping = [
    Model::TYPE_BOOLEAN => Type::boolean(),
    Model::TYPE_STRING => Type::string(),
    Model::TYPE_INTEGER => Type::int(),
    Model::TYPE_FLOAT => Type::float(),

    // Outliers 
    Model::TYPE_JSON => Type::string(),
    Response::MODEL_ANY => Type::string(),
];


function createTypeMapping(Model $model, Response $response) {

    global $typeMapping; 

    // If map already contains this complex type, then simply return 
    if (isset($typeMapping[$model->getType()])) return;


    $rules = $model->getRules();
    $name = $model->getType();
    $fields = [];
    foreach ($rules as $key => $props) {
        // Replace this with php regex
        $key = str_replace('$', '', $key);
        if (isset( $typeMapping[$props['type']])) {
            $type = $typeMapping[$props['type']];
        } else {
            try {
                $complexModel = $response->getModel($props['type']);
                createTypeMapping($complexModel, $response);
                $type = $typeMapping[$props['type']];
            } catch (Exception $e) {
                var_dump("Could Not find model for : {$props['type']}");
            }
        }

        if ($props['array']) {
            $type = Type::listOf($type);
        }

        $fields[$key] = [
            'type' => $type,
            'description' => $props['description'],
        ];
    }

    $objectType = [
        'name' => $name, 
        'fields' => $fields
    ];
   
    $typeMapping[$name] = new ObjectType($objectType);
}


function getArgType(Validator $validator, bool $required) {

    $type = [];
    switch ((!empty($validator)) ? \get_class($validator) : '') {
        case 'Utopia\Validator\Text':
            $type = Type::string();
            break;
        case 'Utopia\Validator\Boolean':
            $type = Type::boolean();
            break;
        case 'Appwrite\Database\Validator\UID':
            $type = Type::string();
            break;
        case 'Utopia\Validator\Email':
            $type = Type::string();
            break;
        case 'Utopia\Validator\URL':
            $type = Type::string();
            break;
        case 'Utopia\Validator\JSON':
        case 'Utopia\Validator\Mock':
        case 'Utopia\Validator\Assoc':
            $type = Type::string();
            break;
        case 'Appwrite\Storage\Validator\File':
            $type = Type::string();
        case 'Utopia\Validator\ArrayList':
            $type = Type::listOf(Type::string());
            break;
        case 'Appwrite\Auth\Validator\Password':
            $type = Type::string();
            break;
        case 'Utopia\Validator\Range': /* @var $validator \Utopia\Validator\Range */
            $type = Type::int();
            break;
        case 'Utopia\Validator\Numeric':
            $type = Type::int();
            break;
        case 'Utopia\Validator\Length':
            $type = Type::string();
            break;
        case 'Utopia\Validator\Host':
            $type = Type::string();
            break;
        case 'Utopia\Validator\WhiteList': /* @var $validator \Utopia\Validator\WhiteList */
            $type = Type::string();
            break;
        default:
            $type = 'string';
            break;
    }

    if ($required) {
        $type = Type::nonNull($type);
    }

    return $type;
}

function getArgs(array $params) {
    $args = [];
    foreach ($params as $key => $value) {
        $args[$key] = [
            'type' => getArgType($value['validator'],!$value['optional']),
            'description' => $value['description'],
            'defaultValue' => $value['default']
        ];
    }
    return $args;
}

function buildSchema($utopia, $response) {
    $start = microtime(true);

    global $typeMapping;
    $fields = [];
    foreach($utopia->getRoutes() as $method => $routes ){
        if ($method == "GET") {
            foreach($routes as $route) {
                $namespace = $route->getLabel('sdk.namespace', '');
                if( true ) {
                    $methodName = $namespace.'_'.$route->getLabel('sdk.method', '');
                    $responseModelName = $route->getLabel('sdk.response.model', Response::MODEL_NONE);
                    
                    // var_dump("******************************************");
                    // var_dump("Model Name : ${responseModelName}");

                    if ( $responseModelName !== Response::MODEL_NONE && $responseModelName !== Response::MODEL_ANY ) {
                        $responseModel = $response->getModel($responseModelName);
                        createTypeMapping($responseModel, $response);

                        $args = getArgs($route->getParams());
                        $fields[$methodName] = [
                            'type' => $typeMapping[$responseModel->getType()],
                            'description' => $route->getDesc(), 
                            'args' => $args,
                            'resolve' => function ($args) use (&$utopia, $route, $response) {
                                var_dump("************* REACHED RESOLVE *****************");
                                var_dump($route);
                                $utopia->execute($route, $args);
                                var_dump("********************** ARGS *******************");
                                var_dump($args);
                                var_dump("**************** OUTPUT ************");
                                var_dump($response->getPayload());
                                return $response->getPayload();
                            }
                        ];

                        // var_dump("Processed route : {$route->getURL()}");
                    } else {
                        // var_dump("Skipping route : {$route->getURL()}");
                    }
                }
            }
        }
    }

    ksort($fields);

    $queryType = new ObjectType([
        'name' => 'Query',
        'description' => 'The root of all your queries',
        'fields' => $fields
    ]);

    $schema = new Schema([
        'query' => $queryType
    ]);

    $time_elapsed_secs = microtime(true) - $start;

    var_dump("Time Taken To Build Schema : ${time_elapsed_secs}s"); 

    return $schema; 
}


App::post('/v1/graphql')
    ->desc('GraphQL Endpoint')
    ->groups(['api', 'graphql'])
    ->label('scope', 'public')
    ->inject('request')
    ->inject('response')
    ->inject('utopia')
    ->action(function ($request, $response, $utopia) {
            // Generate the Schema of the server on startup. 
            // Use the routes from utopia and get the params then construct the queries and mutations.

            $schema = buildSchema($utopia, $response);
            $query = $request->getPayload('query', '');
            $variables = $request->getPayload('variables', null);


            try {
                $rootValue = [];
                $result = GraphQL::executeQuery($schema, $query, $rootValue, null, $variables);
                $output = $result->toArray();
                var_dump("********** OUTPUT *********");
                var_dump($output);
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
            echo "\n"; //TODO REMOVE THIS
        }
    );
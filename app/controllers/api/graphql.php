<?php

use Appwrite\Auth\Auth;
use Appwrite\Database\Database;
use GraphQL\GraphQL;
use GraphQL\Type\Schema;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;
use GraphQL\Error\ClientAware;
use GraphQL\Error\DebugFlag;
use GraphQL\Language\Parser;
use GraphQL\Type\Definition\ListOfType;
use GraphQL\Type\Definition\NonNull;
use Utopia\App;
use Utopia\Config\Config;
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
    Response::MODEL_NONE => Type::string(),
];


function createTypeMapping(Model $model, Response $response) {

    global $typeMapping; 

    // If map already contains this complex type, then simply return 
    if (isset($typeMapping[$model->getType()])) return;

    $rules = $model->getRules();
    $name = $model->getType();
    $fields = [];
    $type = null;
    foreach ($rules as $key => $props) {
        // Replace this with php regex
        $keyWithoutSpecialChars = str_replace('$', '', $key);
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

        $fields[$keyWithoutSpecialChars] = [
            'type' => $type,
            'description' => $props['description'],
            'resolve' => function ($object, $args, $context, $info) use ($key, $type) {
                var_dump("************* RESOLVING FIELD {$info->fieldName} *************");
                // var_dump($info->returnType->getWrappedType());

                var_dump("isListType : ", $info->returnType instanceof ListOfType);
                var_dump("isBuiltinType : ", Type::isBuiltInType($info->returnType));
                var_dump("isCompositeType : ", Type::isCompositeType($info->returnType));
                var_dump("isLeafType : ", Type::isLeafType($info->returnType));
                var_dump("isOutputType : ", Type::isOutputType($info->returnType));

                var_dump("PHP Type of object: " . gettype($object[$key]));
                switch(gettype($object[$key])) {
                    case 'array': 
                        $isAssoc = count(array_filter(array_keys($object[$key]), 'is_string')) > 0 ;
                        if ($isAssoc) {
                            return json_encode($object[$key]);
                        } else {
                            return array_map('json_encode', $object[$key]);
                        }
                    case 'object': 
                        return json_encode($object[$key]);
                    default: 
                        return $object[$key];
                }


                
                $isListType = $info->returnType instanceof ListOfType;

                if ($isListType) {
                    $isStringType = $info->returnType->getWrappedType() === Type::string(); 
                } else {

                }

                // $isString = $info->returnType->getWrappedType();
            }
        ];

        // if ($keyWithoutSpecialChars !== $key) {
        //     $fields[$keyWithoutSpecialChars]['resolve'] = function ($value, $args, $context, $info) use ($key) {
        //         var_dump("************* RESOLVING FIELD {$info->fieldName} *************");
        //         var_dump($value);
        //         return $value[$key];
        //     };
        // } 
    }

    $objectType = [
        'name' => $name, 
        'fields' => $fields
    ];
   
    $typeMapping[$name] = new ObjectType($objectType);
}


function getArgType($validator, bool $required, $utopia, $injections) {
    $validator = (\is_callable($validator)) ? call_user_func_array($validator, $utopia->getResources($injections)) : $validator;
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
            $type = Type::string();
            break;
    }

    if ($required) {
        $type = Type::nonNull($type);
    }

    return $type;
}

function getArgs(array $params, $utopia) {
    $args = [];
    foreach ($params as $key => $value) {
        $args[$key] = [
            'type' => getArgType($value['validator'],!$value['optional'], $utopia, $value['injections']),
            'description' => $value['description'],
            'defaultValue' => $value['default']
        ];
    }
    return $args;
}

function isModel($response, Model $model) {

    foreach ($model->getRules() as $key => $rule) {
        if (!isset($response[$key])) {
            return false;
        }
    }
    return true;
}

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

function buildSchema($utopia, $response) {
    $start = microtime(true);

    global $typeMapping;
    $queryFields = [];
    $mutationFields = [];
    foreach($utopia->getRoutes() as $method => $routes ){
        foreach($routes as $route) {
            $namespace = $route->getLabel('sdk.namespace', '');

            if ($namespace == 'database' || true) {
                $methodName = $namespace.'_'.$route->getLabel('sdk.method', '');
                $responseModelName = $route->getLabel('sdk.response.model', "");
                // var_dump("******************************************");
                // var_dump("Processing route : ${method} : {$route->getURL()}");
                // var_dump("Model Name : ${responseModelName}");
                if ( $responseModelName !== "" && $responseModelName !== Response::MODEL_NONE && $responseModelName !== Response::MODEL_ANY ) {
                    $responseModel = $response->getModel($responseModelName);
                    createTypeMapping($responseModel, $response);
                    $type = $typeMapping[$responseModel->getType()];
                    // var_dump("Type Created : ${type}");
                    $args = getArgs($route->getParams(), $utopia);
                    // var_dump("Args Generated :");
                    // var_dump($args);
                    
                    $field = [
                        'type' => $type,
                        'description' => $route->getDesc(), 
                        'args' => $args,
                        'resolve' => function ($type, $args, $context, $info) use (&$utopia, $route, $response) {
                            var_dump("************* REACHED RESOLVE FOR  {$info->fieldName} *****************");
                            // var_dump($route);

                            // var_dump("************* CONTEXT *****************");
                            // var_dump($context);


                            var_dump("********************** ARGS *******************");
                            var_dump($args);

                            $utopia->setRoute($route);
                            $utopia->execute($route, $args);
                            
                            var_dump("**************** OUTPUT ************");
                            // var_dump($response->getPayload());
                            $result = $response->getPayload();

                            if (isModel($result, $response->getModel(Response::MODEL_ERROR)) || isModel($result, $response->getModel(Response::MODEL_ERROR_DEV))) {
                                throw new MySafeException($result['message'], $result['code']);
                            }
                            
                            return $result;
                        }
                    ];
                    
                    if ($method == 'GET') {
                        $queryFields[$methodName] = $field;
                    } else if ($method == 'POST' || $method == 'PUT' || $method == 'PATCH' || $method == 'DELETE') {
                        $mutationFields[$methodName] = $field;
                    }
                    
                    // var_dump("Processed route : ${method} : {$route->getURL()}");
                } else {
                    // var_dump("Skipping route : {$route->getURL()}");
                }
            }
        }
    }

    ksort($queryFields);
    ksort($mutationFields);

    $queryType = new ObjectType([
        'name' => 'Query',
        'description' => 'The root of all your queries',
        'fields' => $queryFields
    ]);

    $mutationType = new ObjectType([
        'name' => 'Mutation',
        'description' => 'The root of all your mutations',
        'fields' => $mutationFields
    ]);

    $schema = new Schema([
        'query' => $queryType,
        'mutation' => $mutationType
    ]);

    $time_elapsed_secs = microtime(true) - $start;

    var_dump("Time Taken To Build Schema : ${time_elapsed_secs}s"); 

    return $schema; 
}


App::post('/v1/graphql')
    ->desc('GraphQL Endpoint')
    ->label('scope', 'graphql')
    ->inject('request')
    ->inject('response')
    ->inject('utopia')
    ->inject('user')
    ->inject('project')
    ->middleware(true)
    ->action(function ($request, $response, $utopia, $user, $project) {


            // Generate the Schema of the server on startup. 
            // Use the routes from utopia and get the params then construct the queries and mutations.
            $schema = buildSchema($utopia, $response, $request);
            $query = $request->getPayload('query', '');
            $variables = $request->getPayload('variables', null);
            $response->setContentType(Response::CONTENT_TYPE_NULL);

            try {
                $rootValue = [];
                $result = GraphQL::executeQuery($schema, $query, $rootValue, null, $variables);
                $output = $result->toArray();
                // var_dump("********** OUTPUT *********");
                // var_dump($output);
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

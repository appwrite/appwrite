<?php

namespace Appwrite\GraphQL;

use Appwrite\GraphQL\Types\JsonType;
use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;
use Exception;
use GraphQL\Type\Definition\ListOfType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema;
use MySafeException;

class GraphQLBuilder {

    public static $jsonParser;

    public static $typeMapping;

    private static function init() {
        self::$jsonParser = new JsonType();
        self::$typeMapping = [
            Model::TYPE_BOOLEAN => Type::boolean(),
            Model::TYPE_STRING => Type::string(),
            Model::TYPE_INTEGER => Type::int(),
            Model::TYPE_FLOAT => Type::float(),
            Response::MODEL_NONE => Type::string(),
            Model::TYPE_JSON => self::$jsonParser,
            Response::MODEL_ANY => self::$jsonParser,
        ];
    }

    static function createTypeMapping(Model $model, Response $response) {

        /* 
            If the map already contains the type, end the recursion 
            and return.
        */
        if (isset(self::$typeMapping[$model->getType()])) return;
    
        $rules = $model->getRules();
        $name = $model->getType();
        $fields = [];
        $type = null;
        /*
            Iterate through all the rules in the response model. Each rule is of the form 
            [
                [KEY 1] => [
                    'type' => A string from Appwrite/Utopia/Response
                    'description' => A description of the type 
                    'default' => A default value for this type 
                    'example' => An example of this type
                    'require' => a boolean representing whether this field is required 
                    'array' => a boolean representing whether this field is an array 
                ],
                [KEY 2] => [
                ],
                [KEY 3] => [
                ] .....
            ]
        */
        foreach ($rules as $key => $props) {
            /* 
                If there are any field names containing characters other than a-z, A-Z, 0-9, _ , 
                we need to remove all those characters. Currently Appwrite's Response model has only the 
                $ sign which is prohibited. So we're only replacing that. We need to replace this with a regex
                based approach.
            */
            $keyWithoutSpecialChars = str_replace('$', '', $key);
            if (isset(self::$typeMapping[$props['type']])) {
                $type = self::$typeMapping[$props['type']];
            } else {
                try {
                    $complexModel = $response->getModel($props['type']);
                    self::createTypeMapping($complexModel, $response);
                    $type = self::$typeMapping[$props['type']];
                } catch (Exception $e) {
                    var_dump("Could Not find model for : {$props['type']}");
                }
            }
    
            /* 
                If any of the rules is a list, 
                Wrap the base type with a listOf Type
            */ 
            if ($props['array']) {
                $type = Type::listOf($type);
            }
    
            $fields[$keyWithoutSpecialChars] = [
                'type' => $type,
                'description' => $props['description'],
                'resolve' => function ($object, $args, $context, $info) use ($key, $type) {
                    
                    // var_dump("************* RESOLVING FIELD {$info->fieldName} *************");
                    // var_dump($info->returnType->getWrappedType());
                    // var_dump("isListType : ", $info->returnType instanceof ListOfType);
                    // var_dump("isCompositeType : ", Type::isCompositeType($info->returnType));
                    // var_dump("isBuiltinType : ", Type::isBuiltInType($info->returnType));
                    // var_dump("isLeafType : ", Type::isLeafType($info->returnType));
                    // var_dump("isOutputType : ", Type::isOutputType($info->returnType));
                    // var_dump("PHP Type of object: " . gettype($object[$key]));

                    return $object[$key];
                }
            ];
        }
    
        $objectType = [
            'name' => $name, 
            'fields' => $fields
        ];
       
        self::$typeMapping[$name] = new ObjectType($objectType);
    }

    private static function getArgType($validator, bool $required, $utopia, $injections) {
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
    
    private static function getArgs(array $params, $utopia) {
        $args = [];
        foreach ($params as $key => $value) {
            $args[$key] = [
                'type' => self::getArgType($value['validator'],!$value['optional'], $utopia, $value['injections']),
                'description' => $value['description'],
                'defaultValue' => $value['default']
            ];
        }
        return $args;
    }
    
    private static function isModel($response, Model $model) {
    
        foreach ($model->getRules() as $key => $rule) {
            if (!isset($response[$key])) {
                return false;
            }
        }
        return true;
    }

    public static function buildSchema($utopia, $response) {
        
        self::init();

        var_dump("[INFO] Building GraphQL Schema...");
        $start = microtime(true);

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
                    if ( $responseModelName !== "" && $responseModelName !== Response::MODEL_NONE ) {
                        $responseModel = $response->getModel($responseModelName);
                        self::createTypeMapping($responseModel, $response);
                        $type = self::$typeMapping[$responseModel->getType()];
                        // var_dump("Type Created : ${type}");
                        $args = self::getArgs($route->getParams(), $utopia);
                        // var_dump("Args Generated :");
                        // var_dump($args);
                        
                        $field = [
                            'type' => $type,
                            'description' => $route->getDesc(), 
                            'args' => $args,
                            'resolve' => function ($type, $args, $context, $info) use (&$utopia, $route, $response) {
                                // var_dump("************* REACHED RESOLVE FOR  {$info->fieldName} *****************");
                                // var_dump($route);
                                // var_dump("************* CONTEXT *****************");
                                // var_dump($context);
                                // var_dump("********************** ARGS *******************");
                                // var_dump($args);
    
                                $utopia->setRoute($route);
                                $utopia->execute($route, $args);
                                
                                // var_dump("**************** OUTPUT ************");
                                // var_dump($response->getPayload());
                                
                                $result = $response->getPayload();
    
                                if (self::isModel($result, $response->getModel(Response::MODEL_ERROR)) || self::isModel($result, $response->getModel(Response::MODEL_ERROR_DEV))) {
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
        var_dump("[INFO] Time Taken To Build Schema : ${time_elapsed_secs}s");

        return $schema; 
    }
}

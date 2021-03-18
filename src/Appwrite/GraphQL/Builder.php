<?php

namespace Appwrite\GraphQL;

use Appwrite\GraphQL\Types\JsonType;
use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Schema;
use Appwrite\GraphQL\Exception;
use GraphQL\Error\Error;
use GraphQL\Error\FormattedError;
use Utopia\App;

class Builder {

    /** @var JsonType $jsonParser */
    protected static $jsonParser = null;

    /** @var array $typeMapping */
    protected static $typeMapping = null;

    /**
    * Function to initialise the typeMapping array with the base cases of the recursion
    *
    * @return   void
    */
    public static function init() 
    {
        self::$typeMapping = [
            Model::TYPE_BOOLEAN => Type::boolean(),
            Model::TYPE_STRING => Type::string(),
            Model::TYPE_INTEGER => Type::int(),
            Model::TYPE_FLOAT => Type::float(),
            Model::TYPE_JSON => self::json(),
            Response::MODEL_NONE => self::json(),
            Response::MODEL_ANY => self::json(),
        ];
    }

    /**
    * Function to create a singleton for $jsonParser
    *
    * @return JsonType
    */
    public static function json() 
    {
        if (is_null(self::$jsonParser)) {
            self::$jsonParser = new JsonType();
        }
        return self::$jsonParser;
    }

    /**
    * Function to initialise the typeMapping array with the base cases of the recursion
    * If the map already contains the type, end the recursion and return.
    * Iterate through all the rules in the response model. Each rule is of the form 
    *        [
    *            [KEY 1] => [
    *                'type' => A string from Appwrite/Utopia/Response
    *                'description' => A description of the type 
    *                'default' => A default value for this type 
    *                'example' => An example of this type
    *                'require' => a boolean representing whether this field is required 
    *                'array' => a boolean representing whether this field is an array 
    *            ],
    *            [KEY 2] => [
    *            ],
    *            [KEY 3] => [
    *            ] .....
    *        ]
    *   If there are any field names containing characters other than a-z, A-Z, 0-9, _ , 
    *   we need to remove all those characters. Currently Appwrite's Response model has only the 
    *   $ sign which is prohibited. So we're only replacing that. We need to replace this with a regex
    *   based approach.
    *
    * @param Model $model
    * @param Response $response
    * @return Type
    */
    static function getTypeMapping(Model $model, Response $response): Type
    {
        if (isset(self::$typeMapping[$model->getType()])) {
            return self::$typeMapping[$model->getType()];
        }

        $rules = $model->getRules();
        $name = $model->getType();
        $fields = [];
        $type = null;
        foreach ($rules as $key => $props) {
            $keyWithoutSpecialChars = str_replace('$', '', $key);
            if (isset(self::$typeMapping[$props['type']])) {
                $type = self::$typeMapping[$props['type']];
            } else {
                try {
                    $complexModel = $response->getModel($props['type']);
                    $type = self::getTypeMapping($complexModel, $response);
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
                'resolve' => function ($object, $args, $context, $info) use ($key) {
                    return $object[$key];
                }
            ];
        }
        $objectType = [
            'name' => $name, 
            'fields' => $fields
        ];
        self::$typeMapping[$name] = new ObjectType($objectType); 
        return self::$typeMapping[$name];
    }

    /**
    * Function to initialise the typeMapping array with the base cases of the recursion
    *
    * @param $validator
    * @param bool $required
    * @param $utopia
    * @param bool $injections
    * @return void
    */
    protected static function getArgType($validator, bool $required, $utopia, $injections) {
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
                $type = self::json();
                break;
            case 'Appwrite\Storage\Validator\File':
                $type = Type::string();
            case 'Utopia\Validator\ArrayList':
                $type = Type::listOf(self::json());
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
                $type = self::json();
                break;
        }
    
        if ($required) {
            $type = Type::nonNull($type);
        }
    
        return $type;
    }
    
    /**
    * Function to initialise the typeMapping array with the base cases of the recursion
    *
    * @param string $a
    * @return void
    */
    protected static function getArgs(array $params, $utopia) {
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

    /**
    * This function goes through all the REST endpoints in the API and builds a 
    * GraphQL schema for all those routes whose response model is neither empty nor NONE
    *
    * @param $utopia
    * @param $response
    * @param $register
    * @return Schema
    */
    public static function buildSchema($utopia, $response, $register) {
        var_dump("[INFO] Building GraphQL Schema...");
        $start = microtime(true);

        self::init();
        $queryFields = [];
        $mutationFields = [];

        foreach($utopia->getRoutes() as $method => $routes ){
            foreach($routes as $route) {
                $namespace = $route->getLabel('sdk.namespace', '');
                $methodName = $namespace.'_'.$route->getLabel('sdk.method', '');
                $responseModelName = $route->getLabel('sdk.response.model', "");
                if ( $responseModelName !== "" ) {
                    $responseModel = $response->getModel($responseModelName);
                    $type = self::getTypeMapping($responseModel, $response);
                    $description = $route->getDesc();
                    $args = self::getArgs($route->getParams(), $utopia);
                    $resolve = function ($type, $args, $context, $info) use (&$register, $route) {
                        $utopia = $register->get('__app');
                        $utopia->setRoute($route)->execute($route, $args);
                        $response = $register->get('__response');
                        $result = $response->getPayload();
                        if ( $response->getCurrentModel() == Response::MODEL_ERROR_DEV ) {
                            throw new ExceptionDev($result['message'], $result['code'], $result['version'], $result['file'], $result['line'], $result['trace']);
                        } else if ( $response->getCurrentModel() == Response::MODEL_ERROR ) {
                            throw new Exception($result['message'], $result['code']);
                        }
                        return $result;
                    };
                    $field = [
                        'type' => $type,
                        'description' => $description, 
                        'args' => $args,
                        'resolve' => $resolve
                    ];
                    if ($method == 'GET') {
                        $queryFields[$methodName] = $field;
                    } else if ($method == 'POST' || $method == 'PUT' || $method == 'PATCH' || $method == 'DELETE') {
                        $mutationFields[$methodName] = $field;
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

    public static function errorFormatter(Error $error) {
        $formattedError = FormattedError::createFromException($error); 
        var_dump("***** IN ERROR FORMATTER ******");
        $parentError = $error->getPrevious();
        $formattedError['code'] = $parentError->getCode();
        $formattedError['file'] = $parentError->getFile();
        $formattedError['version'] = App::getEnv('_APP_VERSION', 'UNKNOWN');
        $formattedError['line'] = $parentError->getLine();
        return $formattedError;
    }
}

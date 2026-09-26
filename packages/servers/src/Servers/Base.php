<?php

namespace Utopia\Servers;

use Utopia\DI\Container;
use Utopia\Validator;

abstract class Base
{
    /**
     * Mode Type
     */
    public const MODE_TYPE_DEVELOPMENT = 'development';
    public const MODE_TYPE_STAGE = 'stage';
    public const MODE_TYPE_PRODUCTION = 'production';

    /**
     * Current running mode
     */
    protected static string $mode = '';

    /**
     * Errors
     *
     * Errors callbacks
     *
     * @var Hook[]
     */
    protected static array $errors = [];

    /**
     * Init
     *
     * A callback function that is initialized on application start
     *
     * @var Hook[]
     */
    protected static array $init = [];

    /**
     * Shutdown
     *
     * A callback function that is initialized on application end
     *
     * @var Hook[]
     */
    protected static array $shutdown = [];

    /**
     * Server start hooks
     *
     * @var Hook[]
     */
    protected static array $start = [];

    /**
     * Server end hooks
     *
     * @var Hook[]
     */
    protected static array $end = [];

    protected Container $container;

    /**
     * Base
     *
     * @param Adapter $server
     * @param  string  $timezone
     */
    // public function __construct(Adapter $server, Container $container, string $timezone)
    // {
    //     \date_default_timezone_set($timezone);
    //     $this->files = new Files();
    //     $this->server = $server;
    //     $this->container = $container;
    // }
    /**
     * Init
     *
     * Set a callback function that will be initialized on application start
     */
    public static function init(): Hook
    {
        $hook = new Hook();
        $hook->groups(['*']);

        self::$init[] = $hook;

        return $hook;
    }

    /**
     * Shutdown
     *
     * Set a callback function that will be initialized on application end
     */
    public static function shutdown(): Hook
    {
        $hook = new Hook();
        $hook->groups(['*']);

        self::$shutdown[] = $hook;

        return $hook;
    }

    /**
     * Error
     *
     * An error callback for failed or no matched requests
     */
    public static function error(): Hook
    {
        $hook = new Hook();
        $hook->groups(['*']);

        self::$errors[] = $hook;

        return $hook;
    }

    /**
     * Get Mode
     *
     * Get current mode
     */
    public static function getMode(): string
    {
        return self::$mode;
    }

    /**
     * Set Mode
     *
     * Set current mode
     */
    public static function setMode(string $value): void
    {
        self::$mode = $value;
    }

    /**
     * Get Container
     */
    public function getContainer(): Container
    {
        return $this->container;
    }

    /**
     * Set Container
     */
    public function setContainer(Container $container): self
    {
        $this->container = $container;
        return $this;
    }

    /**
     * Is http in production mode?
     */
    public static function isProduction(): bool
    {
        return self::MODE_TYPE_PRODUCTION === self::$mode;
    }

    /**
     * Is http in development mode?
     */
    public static function isDevelopment(): bool
    {
        return self::MODE_TYPE_DEVELOPMENT === self::$mode;
    }

    /**
     * Is http in stage mode?
     */
    public static function isStage(): bool
    {
        return self::MODE_TYPE_STAGE === self::$mode;
    }

    public static function onStart(): Hook
    {
        $hook = new Hook();
        self::$start[] = $hook;
        return $hook;
    }

    public static function onEnd(): Hook
    {
        $hook = new Hook();
        self::$end[] = $hook;
        return $hook;
    }

    abstract public function start();

    /**
     * Prepare hook for injection, add dependencies, run validation.
     *
     *
     * @throws Exception
     */
    protected function prepare(Container $context, Hook $hook, array $values = [], array $requestParams = []): Container
    {
        $scope = new Container($context);

        foreach ($hook->getParams() as $key => $param) { // Get value from route or request object
            $requestKey = $key;
            if (!\array_key_exists($key, $requestParams) && !empty($param['aliases'])) {
                foreach ($param['aliases'] as $alias) {
                    if (\array_key_exists($alias, $requestParams)) {
                        $requestKey = $alias;
                        break;
                    }
                }
            }

            $valuesKey = $key;
            if (!\array_key_exists($key, $values) && !empty($param['aliases'])) {
                foreach ($param['aliases'] as $alias) {
                    if (\array_key_exists($alias, $values)) {
                        $valuesKey = $alias;
                        break;
                    }
                }
            }

            $existsInRequest = \array_key_exists($requestKey, $requestParams);
            $existsInValues = \array_key_exists($valuesKey, $values);
            $paramExists = $existsInRequest || $existsInValues;
            $arg = $existsInRequest ? $requestParams[$requestKey] : $param['default'];

            // Adding is string to avoid PHP built-in functions
            if (!\is_string($arg) && \is_callable($arg)) {
                $injections = array_map($scope->get(...), $param['injections']);
                $arg = \call_user_func_array($arg, $injections);
            }
            $value = $existsInValues ? $values[$valuesKey] : $arg;

            /**
             * Validation
             */
            if (!$param['skipValidation']) {
                if (!$paramExists && !$param['optional']) {
                    throw new Exception('Param "' . $key . '" is not optional.', 400);
                }

                if ($paramExists) {
                    $this->validate($key, $param, $value, $scope);
                }
            }

            $hook->setParamValue($key, $value);

            $scope->set($key, fn() => $value);
        }

        return $scope;
    }

    /**
     * Validate Param
     *
     * Creates an validator instance and validate given value with given rules.
     *
     *
     * @throws Exception
     */
    protected function validate(string $key, array $param, mixed $value, Container $context): void
    {
        if ($param['optional'] && \is_null($value)) {
            return;
        }

        $validator = $param['validator']; // checking whether the class exists

        if (\is_callable($validator)) {
            $validatorKey = '_validator:' . $key;
            $context->set($validatorKey, $validator, $param['injections']);
            $validator = $context->get($validatorKey);
        }

        if (!$validator instanceof Validator) { // is the validator object an instance of the Validator class
            throw new Exception('Validator object is not an instance of the Validator class', 500);
        }

        if (!$validator->isValid($value)) {
            throw new Exception('Invalid `' . $key . '` param: ' . $validator->getDescription(), 400);
        }
    }

    /**
     * Reset all the static variables
     */
    public static function reset(): void
    {
        self::$mode = '';
        self::$errors = [];
        self::$init = [];
        self::$shutdown = [];
        self::$start = [];
        self::$end = [];
    }
}

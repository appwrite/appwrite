<?php

namespace Utopia\CLI;

use Exception;
use Utopia\CLI\Adapters\Generic;
use Utopia\DI\Container;
use Utopia\Servers\Hook;
use Utopia\Validator;
use Utopia\Validator\Boolean;
use Utopia\Validator\Nullable;

class CLI
{
    /**
     * Adapter
     *
     * Type of adapter to pass the callable to
     */
    protected Adapter $adapter;

    /**
     * Command
     *
     * The name of the command requested for this process
     */
    protected string $command = '';

    protected Container $container;

    protected ?Container $parentContainer = null;

    /**
     * Args
     *
     * List of arguments passed to this process
     */
    protected array $args = [];

    /**
     * Tasks
     *
     * List of commands tasks for this CLI process
     */
    protected array $tasks = [];

    /**
     * Error
     *
     * An error callback
     *
     * @var Hook[]
     */
    protected $errors = [];

    /**
     * Init
     *
     * A callback function that is initialized on application start
     *
     * @var Hook[]
     */
    protected array $init = [];

    /**
     * Shutdown
     *
     * A callback function that is initialized on application end
     *
     * @var Hook[]
     */
    protected array $shutdown = [];

    /**
     * CLI constructor.
     *
     *
     * @throws Exception
     */
    public function __construct(?Adapter $adapter = null, array $args = [], ?Container $container = null)
    {
        if (php_sapi_name() !== 'cli') {
            throw new Exception('CLI tasks can only work from the command line');
        }

        $this->args = $this->parse(($args !== [] || ! isset($_SERVER['argv'])) ? $args : $_SERVER['argv']);

        @cli_set_process_title($this->command);

        $this->adapter = $adapter ?? new Generic();
        $this->parentContainer = $container;
        $this->container = new Container($container);
    }

    /**
     * Init
     *
     * Set a callback function that will be initialized on application start
     */
    public function init(): Hook
    {
        $hook = new Hook();
        $this->init[] = $hook;

        return $hook;
    }

    /**
     * Shutdown
     *
     * Set a callback function that will be initialized on application end
     */
    public function shutdown(): Hook
    {
        $hook = new Hook();
        $this->shutdown[] = $hook;

        return $hook;
    }

    /**
     * Error
     *
     * An error callback for failed or no matched requests
     */
    public function error(): Hook
    {
        $hook = new Hook();
        $this->errors[] = $hook;

        return $hook;
    }

    /**
     * Task
     *
     * Add a new command task
     */
    public function task(string $name): Task
    {
        $task = new Task($name);

        $this->tasks[$name] = $task;

        return $task;
    }

    /**
     * If a resource has been created return it, otherwise create it and then return it
     *
     *
     * @throws Exception
     */
    public function getResource(string $name): mixed
    {
        if (! $this->container->has($name)) {
            throw new Exception('Failed to find resource: "' . $name . '"');
        }

        return $this->container->get($name);
    }

    /**
     * Get Resources By List
     *
     *
     * @throws Exception
     */
    public function getResources(array $list): array
    {
        $resources = [];

        foreach ($list as $name) {
            $resources[$name] = $this->getResource($name);
        }

        return $resources;
    }

    /**
     * Set a new resource callback
     *
     *
     * @throws Exception
     */
    public function setResource(string $name, callable $callback, array $dependencies = []): void
    {
        $this->container->set($name, $callback, $dependencies);
    }

    public function getContainer(): Container
    {
        return $this->container;
    }

    /**
     * task-name --foo=test
     *
     *
     * @throws Exception
     */
    public function parse(array $args): array
    {
        array_shift($args); // Remove script path from args

        if (isset($args[0])) {
            $this->command = array_shift($args);
        } else {
            throw new Exception('Missing command');
        }

        $output = [];

        foreach ($args as &$arg) {
            if (substr($arg, 0, 2) === '--') {
                $arg = substr($arg, 2);
            }
        }

        /**
         * Refer to this answer
         * https://stackoverflow.com/questions/18669499/php-issue-with-looping-over-an-array-twice-using-foreach-and-passing-value-by-re/18669732
         */
        unset($arg);

        foreach ($args as $arg) {
            $pair = explode('=', $arg, 2);
            $key = $pair[0];
            $value = $pair[1];
            $output[$key][] = $value;
        }

        foreach ($output as $key => $value) {
            /**
             * If there is only one element in a particular key
             * unshift the value out of the array
             */
            if (\count($value) === 1) {
                $output[$key] = array_shift($output[$key]);
            }
        }

        return $output;
    }

    /**
     * Find the command that should be triggered
     */
    public function match(): ?Task
    {
        return $this->tasks[$this->command] ?? null;
    }

    /**
     * Get Params
     * Get runtime params for the provided Hook
     *
     *
     * @throws Exception
     */
    protected function getParams(Hook $hook): array
    {
        $params = [];

        foreach ($hook->getParams() as $key => $param) {
            $value = $param['default'];

            if (isset($this->args[$key])) {
                $value = $this->args[$key];
            } else {
                foreach ($param['aliases'] ?? [] as $alias) {
                    if (isset($this->args[$alias])) {
                        $value = $this->args[$alias];
                        break;
                    }
                }
            }

            $this->validate($key, $param, $value);

            $params[$this->camelCaseIt($key)] = $this->coerce($param['validator'], $value);
        }

        foreach ($hook->getDependencies() as $dependency) {
            if (\array_key_exists($this->camelCaseIt($dependency), $params)) {
                continue;
            }

            $params[$this->camelCaseIt($dependency)] = $this->getResource($dependency);
        }

        return $params;
    }

    /**
     * Run
     *
     * @return $this
     *
     * @throws Exception
     */
    public function run(): self
    {
        $this->adapter->start(function (): void {
            $command = $this->match();

            try {
                if ($command instanceof \Utopia\CLI\Task) {
                    foreach ($this->init as $hook) {
                        \call_user_func_array($hook->getAction(), $this->getParams($hook));
                    }

                    // Call the callback with the matched positions as params
                    \call_user_func_array($command->getAction(), $this->getParams($command));

                    foreach ($this->shutdown as $hook) {
                        \call_user_func_array($hook->getAction(), $this->getParams($hook));
                    }
                } else {
                    throw new Exception('No command found');
                }
            } catch (Exception $e) {
                foreach ($this->errors as $hook) {
                    $this->setResource('error', fn(): \Exception => $e);
                    \call_user_func_array($hook->getAction(), $this->getParams($hook));
                }
            }
        });

        return $this;
    }

    /**
     * Get list of all tasks
     *
     * @return Task[]
     */
    public function getTasks(): array
    {
        return $this->tasks;
    }

    /**
     * Get list of all args
     */
    public function getArgs(): array
    {
        return $this->args;
    }

    /**
     * Validate Param
     *
     * Creates an validator instance and validate given value with given rules.
     *
     * @param  mixed  $value
     *
     * @throws Exception
     */
    protected function validate(string $key, array $param, $value): void
    {
        if ('' !== $value) {
            // checking whether the class exists
            $validator = $param['validator'];
            if (\is_callable($validator)) {
                $validator = $validator();
            }
            // is the validator object an instance of the Validator class
            if (! $validator instanceof Validator) {
                throw new Exception('Validator object is not an instance of the Validator class', 500);
            }
            if (! $validator->isValid($value)) {
                throw new Exception('Invalid ' . $key . ': ' . $validator->getDescription(), 400);
            }
        } elseif (! $param['optional']) {
            throw new Exception('Param "' . $key . '" is not optional.', 400);
        }
    }

    /**
     * Coerce string CLI inputs to native PHP types based on the param's validator.
     *
     * CLI args arrive as strings via getopt. When the validator is `Boolean`
     * (loose mode), strings like "true"/"false"/"1"/"0" pass validation but
     * remain strings. If the action callback declares a `bool` parameter, PHP's
     * implicit string-to-bool cast turns any non-empty string except "0" into
     * `true` -- so `--commit=false` silently becomes `true`. Validators in
     * utopia-php are pure (validate only, never mutate), so the coercion has
     * to happen here at the dispatch boundary.
     *
     * Only string inputs are coerced; bool defaults are passed through
     * untouched. Strings that `filter_var` doesn't recognise (including the
     * empty string, which bypasses `validate()` for optional params) are
     * passed through unchanged so callers that use `''` as a sentinel for
     * "not set" keep working.
     */
    protected function coerce(Validator|callable $validator, mixed $value): mixed
    {
        if (! \is_string($value) || $value === '') {
            return $value;
        }

        if (\is_callable($validator)) {
            $validator = $validator();
        }

        while ($validator instanceof Nullable) {
            $validator = $validator->getValidator();
        }

        if (! ($validator instanceof Boolean)) {
            return $value;
        }

        $coerced = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return $coerced ?? $value;
    }

    public function setContainer(Container $container): self
    {
        $this->parentContainer = $container;
        $this->container = new Container($container);

        return $this;
    }

    public function reset(): void
    {
        $this->container = new Container($this->parentContainer);
    }

    private function camelCaseIt($key): string
    {
        $key = str_replace('-', '_', $key);

        return  lcfirst(str_replace('_', '', ucwords($key, '_')));
    }
}

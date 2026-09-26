<?php

namespace Utopia\Servers;

use Utopia\Validator;

class Hook
{
    /**
     * Description
     */
    protected string $desc = '';

    /**
     * Parameters
     *
     * List of route params names and validators
     */
    protected array $params = [];

    /**
     * Group
     */
    protected array $groups = [];

    /**
     * Labels
     *
     * List of route label names
     */
    protected array $labels = [];

    /**
     * Action Callback
     *
     * @var callable
     */
    protected $action;

    /**
     * Injections
     *
     * List of route required injections for action callback
     */
    protected array $injections = [];

    public function __construct()
    {
        $this->action = function (): void {};
    }

    /**
     * Add Description
     */
    public function desc(string $desc): static
    {
        $this->desc = $desc;

        return $this;
    }

    /**
     * Get Description
     */
    public function getDesc(): string
    {
        return $this->desc;
    }

    /**
     * Add Group
     */
    public function groups(array $groups): static
    {
        $this->groups = $groups;

        return $this;
    }

    /**
     * Get Groups
     */
    public function getGroups(): array
    {
        return $this->groups;
    }

    /**
     * Add Label
     */
    public function label(string $key, mixed $value): static
    {
        $this->labels[$key] = $value;

        return $this;
    }

    /**
     * Get Label
     *
     * Return given label value or default value if label doesn't exists
     */
    public function getLabel(string $key, mixed $default): mixed
    {
        return $this->labels[$key] ?? $default;
    }

    /**
     * Add Action
     */
    public function action(callable $action): static
    {
        $this->action = $action;

        return $this;
    }

    /**
     * Get Action
     *
     * @return callable
     */
    public function getAction()
    {
        return $this->action;
    }

    /**
     * Get Injections
     */
    public function getInjections(): array
    {
        return $this->injections;
    }

    /**
     * Get Dependencies
     */
    public function getDependencies(): array
    {
        if ($this->injections === []) {
            return [];
        }

        $injections = array_values($this->injections);

        usort($injections, static fn(array $left, array $right): int => $left['order'] <=> $right['order']);

        return array_column($injections, 'name');
    }

    /**
     * Inject
     *
     *
     * @throws \Exception
     */
    public function inject(string $injection): static
    {
        if (\array_key_exists($injection, $this->injections)) {
            throw new \Exception('Injection already declared for ' . $injection);
        }

        $this->injections[$injection] = [
            'name' => $injection,
            'order' => \count($this->params) + \count($this->injections),
        ];

        return $this;
    }

    /**
     * Add Param
     */
    public function param(string $key, mixed $default, Validator|callable $validator, string $description = '', bool $optional = false, array $injections = [], bool $skipValidation = false, bool $deprecated = false, string $example = '', ?string $model = null, array $aliases = [], ?object $enum = null): static
    {
        $this->params[$key] = [
            'default' => $default,
            'validator' => $validator,
            'description' => $description,
            'optional' => $optional,
            'injections' => $injections,
            'skipValidation' => $skipValidation,
            'deprecated' => $deprecated,
            'example' => $example,
            'model' => $model,
            'aliases' => $aliases,
            'enum' => $enum,
            'value' => null,
            'order' => \count($this->params) + \count($this->injections),
        ];

        return $this;
    }

    /**
     * Get Params
     */
    public function getParams(): array
    {
        return $this->params;
    }

    /**
     * Get Param Values
     */
    public function getParamsValues(): array
    {
        $values = [];

        foreach ($this->params as $key => $param) {
            $values[$key] = $param['value'];
        }

        return $values;
    }

    /**
     * Set Param Value
     *
     *
     * @throws Exception
     */
    public function setParamValue(string $key, mixed $value): static
    {
        if (!isset($this->params[$key])) {
            throw new \Exception('Unknown key');
        }

        $this->params[$key]['value'] = $value;

        return $this;
    }

    /**
     * Get Param Value
     *
     *
     * @throws \Exception
     */
    public function getParamValue(string $key): mixed
    {
        if (!isset($this->params[$key])) {
            throw new \Exception('Unknown key');
        }

        return $this->params[$key]['value'];
    }
}

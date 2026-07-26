<?php

namespace Appwrite\SDK\Specification;

use Appwrite\SDK\Method;
use Appwrite\Utopia\Response\Model;
use Utopia\DI\Container;
use Utopia\Http\Route;

abstract class Format
{
    protected Container $container;

    /**
     * @var array<Route>
     */
    protected array $routes;

    /**
     * @var array<Model>
     */
    protected array $models;

    protected array $services;
    protected array $keys;
    protected int $authCount;
    protected string $platform;
    protected array $params = [
        'name' => '',
        'description' => '',
        'endpoint' => 'https://localhost',
        'endpoint.docs' => 'https://<REGION>.cloud.appwrite.io/v1',
        'version' => '1.0.0',
        'terms' => '',
        'support.email' => '',
        'support.url' => '',
        'contact.name' => '',
        'contact.email' => '',
        'contact.url' => '',
        'license.name' => '',
        'license.url' => '',
    ];

    public function __construct(Container $container, array $services, array $routes, array $models, array $keys, int $authCount, string $platform)
    {
        $this->container = $container;
        $this->services = $services;
        $this->routes = $routes;
        $this->models = $models;
        $this->keys = $keys;
        $this->authCount = $authCount;
        $this->platform = $platform;
    }

    /**
     * Get Name.
     *
     * Get format name
     *
     * @return string
     */
    abstract public function getName(): string;

    /**
     * Parse
     *
     * Parses Appwrite App to given format
     *
     * @return array
     */
    abstract public function parse(): array;

    /**
     * Set Param.
     *
     * Set param value
     *
     * @param string $key
     * @param string $value
     *
     * @return self
     */
    public function setParam(string $key, string $value): self
    {
        $this->params[$key] = $value;

        return $this;
    }

    /**
     * Get Param.
     *
     * Get param value
     *
     * @param string $key
     * @param string $default
     *
     * @return string
     */
    public function getParam(string $key, string $default = ''): string
    {
        return $this->params[$key] ?? $default;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getArrayItemsSchema(mixed $example): array
    {
        if (\is_string($example)) {
            $decoded = \json_decode($example, true);
            if (\is_array($decoded)) {
                $example = $decoded;
            }
        }

        if (!\is_array($example) || empty($example)) {
            return ['type' => 'object'];
        }

        foreach ($example as $item) {
            if ($item === null) {
                continue;
            }

            if (\is_array($item)) {
                if (!\array_is_list($item)) {
                    return [
                        'type' => 'object',
                        'additionalProperties' => true,
                    ];
                }

                return [
                    'type' => 'array',
                    'items' => $this->getArrayItemsSchema($item),
                ];
            }

            if (\is_int($item) || \is_float($item)) {
                return [
                    'type' => 'number',
                    'format' => 'double',
                ];
            }

            return [
                'type' => match (\gettype($item)) {
                    'boolean' => 'boolean',
                    'string' => 'string',
                    default => 'object',
                },
            ];
        }

        return ['type' => 'object'];
    }

    /**
     * Set Services.
     *
     * Set services value
     *
     * @param array $services
     *
     * @return self
     */
    public function setServices(array $services): self
    {
        $this->services = $services;
        return $this;
    }

    /**
     * Set Services.
     *
     * Get services value
     *
     */
    public function getServices(): array
    {
        return $this->services;
    }

    /**
     * @param list<string> $injections
     * @return array<string, mixed>
     */
    protected function getResources(array $injections): array
    {
        $resources = [];

        foreach ($injections as $name) {
            $resources[$name] = $this->container->get($name);
        }

        return $resources;
    }

    /**
     * Parameters emitted for a method: the route params merged with SDK-only
     * additions. A method may declare an explicit `parameters` list to
     * override route params by name — set fields replace the route's, and
     * `hide: true` drops the param from the spec while the route keeps
     * accepting it at runtime.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function getMethodParameters(Route $route, Method $method): array
    {
        $parameters = \array_merge($route->getParams(), $method->getAdditionalParameters());

        foreach ($method->getParameters() as $parameter) {
            $name = $parameter->getName();

            if ($parameter->getHide()) {
                unset($parameters[$name]);
                continue;
            }

            $overrides = \array_filter([
                'description' => $parameter->getDescription() ?: null,
                'validator' => $parameter->getValidator(),
            ], fn (mixed $value) => $value !== null);

            if ($parameter->hasDefault()) {
                $overrides['default'] = $parameter->getDefault();
            }

            if ($parameter->hasOptional()) {
                $overrides['optional'] = $parameter->getOptional();
            }

            $parameters[$name] = \array_merge(
                $parameters[$name] ?? ['optional' => $parameter->getOptional(), 'injections' => []],
                $overrides,
            );
        }

        return $parameters;
    }

    protected function getValidator(array $param): mixed
    {
        return \is_callable($param['validator'])
            ? ($param['validator'])(...$this->getResources($param['injections'] ?? []))
            : $param['validator'];
    }

    protected function getDescriptionContents(?string $description): string
    {
        if ($description === null || $description === '') {
            return '';
        }

        if (!\str_ends_with($description, '.md')) {
            return $description;
        }

        $contents = @\file_get_contents($description);

        if ($contents === false) {
            throw new \RuntimeException('Documentation file not found or unreadable: ' . $description);
        }

        return $contents;
    }

    /**
     * @param array<Model> $models
     * @return array<string, mixed>|null
     */
    protected function getDiscriminator(array $models, string $refPrefix): ?array
    {
        if (\count($models) < 2) {
            return null;
        }

        $candidateKeys = \array_keys($models[0]->conditions);

        foreach (\array_slice($models, 1) as $model) {
            $candidateKeys = \array_values(\array_intersect($candidateKeys, \array_keys($model->conditions)));
        }

        if (empty($candidateKeys)) {
            return null;
        }

        foreach ($candidateKeys as $key) {
            $mapping = [];
            $isValid = true;

            foreach ($models as $model) {
                $rules = $model->getRules();
                $condition = $model->conditions[$key] ?? null;

                if (!isset($rules[$key]) || ($rules[$key]['required'] ?? false) !== true) {
                    $isValid = false;
                    break;
                }

                if (!\is_array($condition)) {
                    if (!\is_scalar($condition)) {
                        $isValid = false;
                        break;
                    }

                    $values = [$condition];
                } else {
                    if ($condition === []) {
                        $isValid = false;
                        break;
                    }

                    $values = $condition;
                    $hasInvalidValue = false;

                    foreach ($values as $value) {
                        if (!\is_scalar($value)) {
                            $hasInvalidValue = true;
                            break;
                        }
                    }

                    if ($hasInvalidValue) {
                        $isValid = false;
                        break;
                    }
                }

                if (isset($rules[$key]['enum']) && \is_array($rules[$key]['enum'])) {
                    $values = \array_values(\array_filter(
                        $values,
                        fn (mixed $value) => \in_array($value, $rules[$key]['enum'], true)
                    ));
                }

                if ($values === []) {
                    $isValid = false;
                    break;
                }

                $ref = $refPrefix . $model->getType();

                foreach ($values as $value) {
                    $mappingKey = \is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;

                    if (isset($mapping[$mappingKey]) && $mapping[$mappingKey] !== $ref) {
                        $isValid = false;
                        break;
                    }

                    $mapping[$mappingKey] = $ref;
                }

                if (!$isValid) {
                    break;
                }
            }

            if (!$isValid || $mapping === []) {
                continue;
            }

            return [
                'propertyName' => $key,
                'mapping' => $mapping,
            ];
        }

        // Single-key failed — try compound discriminator
        return $this->getCompoundDiscriminator($models, $refPrefix);
    }

    /**
     * @param array<Model> $models
     * @return array<string, mixed>|null
     */
    private function getCompoundDiscriminator(array $models, string $refPrefix): ?array
    {
        $allKeys = [];
        foreach ($models as $model) {
            foreach (\array_keys($model->conditions) as $key) {
                if (!\in_array($key, $allKeys, true)) {
                    $allKeys[] = $key;
                }
            }
        }

        if (\count($allKeys) < 2) {
            return null;
        }

        $primaryKey = $allKeys[0];
        $primaryMapping = [];
        $compoundMapping = [];

        foreach ($models as $model) {
            $rules = $model->getRules();
            $conditions = [];

            foreach ($model->conditions as $key => $condition) {
                if (!isset($rules[$key]) || ($rules[$key]['required'] ?? false) !== true) {
                    return null;
                }

                if (!\is_scalar($condition)) {
                    return null;
                }

                $conditions[$key] = \is_bool($condition) ? ($condition ? 'true' : 'false') : (string) $condition;
            }

            if (empty($conditions)) {
                return null;
            }

            $ref = $refPrefix . $model->getType();
            $compoundMapping[$ref] = $conditions;

            // Best-effort single-key mapping — last model with this value wins (fallback)
            if (isset($conditions[$primaryKey])) {
                $primaryMapping[$conditions[$primaryKey]] = $ref;
            }
        }

        // Verify compound uniqueness
        $seen = [];
        foreach ($compoundMapping as $conditions) {
            $sig = \json_encode($conditions, JSON_THROW_ON_ERROR);
            if (isset($seen[$sig])) {
                return null;
            }
            $seen[$sig] = true;
        }

        return \array_filter([
            'propertyName' => $primaryKey,
            'mapping' => !empty($primaryMapping) ? $primaryMapping : null,
            'x-propertyNames' => $allKeys,
            'x-mapping' => $compoundMapping,
        ]);
    }

    protected function shouldEmitDefaultForSchema(mixed $default, array $schema): bool
    {
        if (isset($schema['enum'])) {
            return \in_array($default, $schema['enum'], true);
        }

        if (isset($schema['items']['enum'])) {
            return \is_array($default) && empty(\array_diff($default, $schema['items']['enum']));
        }

        return true;
    }

    protected function getRequestParameterConfig(bool $optional, bool $nullable, mixed $default, string $methodName = '', string $paramName = '')
    {
        $required = !$optional;

        if (
            $paramName === 'hostname'
            && \in_array($methodName, ['project.createWebPlatform', 'project.updateWebPlatform'], true)
        ) {
            $required = true;
        }

        $config = [
            'required' => $required,
            'nullable' => $nullable,
        ];

        $config['emitDefault'] = !$config['required'] && !\is_null($default);

        return $config;
    }

    protected function getNestedModels(Model $model, array &$usedModels): void
    {
        foreach ($model->getRules() as $rule) {
            if (($rule['hidden'] ?? false) === true) {
                continue;
            }
            if (!in_array($model->getType(), $usedModels)) {
                continue;
            }
            $types = (array)$rule['type'];
            foreach ($types as $ruleType) {
                if (!in_array($ruleType, ['string', 'integer', 'boolean', 'json', 'float'])) {
                    $usedModels[] = $ruleType;
                    foreach ($this->models as $m) {
                        if ($m->getType() === $ruleType) {
                            $this->getNestedModels($m, $usedModels);
                        }
                    }
                }
            }
        }
    }

    protected function parseDescription(string $description, array $excludedValues): string
    {
        if (empty($excludedValues)) {
            return $description;
        }

        foreach ($excludedValues as $excludedValue) {
            // remove from comma-separated list
            $description = preg_replace(
                '/,\s*' . preg_quote($excludedValue, '/') . '(?=\s*[,.]|$)/',
                '',
                $description
            );
            $description = preg_replace(
                '/(?<=:\s|,\s)' . preg_quote($excludedValue, '/') . '\s*,\s*/',
                '',
                $description
            );
        }

        // clean up double commas and extra spaces
        $description = preg_replace('/,\s*,/', ',', $description);
        $description = preg_replace('/\s+/', ' ', $description);

        return trim($description);
    }
}

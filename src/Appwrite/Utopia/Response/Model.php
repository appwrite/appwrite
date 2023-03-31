<?php

namespace Appwrite\Utopia\Response;

use Appwrite\Utopia\Response\Attribute\Name;
use Appwrite\Utopia\Response\Attribute\Type;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionProperty;
use Utopia\Database\Document;

abstract class Model
{
    public const TYPE_STRING = 'string';
    public const TYPE_INTEGER = 'integer';
    public const TYPE_FLOAT = 'double';
    public const TYPE_BOOLEAN = 'boolean';
    public const TYPE_JSON = 'json';
    public const TYPE_DATETIME = 'datetime';
    public const TYPE_DATETIME_EXAMPLE = '2020-10-15T06:38:00.000+00:00';

    /**
     * @var array<string,Rule>
     */
    protected array $rules = [];
    protected string $name;
    protected string $type;
    protected bool $none = false;
    protected bool $any = false;
    protected bool $public = true;
    public array $conditions = [];

    public function __construct()
    {
        $reflection = new \ReflectionClass($this);

        $this->name = $this->getAttribute($reflection, Name::class);
        $this->type = $this->getAttribute($reflection, Type::class);

        $options = $this->getAttribute($reflection, Options::class);
        if ($options !== null) {
            $options = $options->newInstance();
            $this->none = $options->none;
            $this->any = $options->any;
            $this->public = $options->public;
        }

        foreach ($reflection->getProperties() as $property) {
            $attribute = $this->getAttribute($property, Type::class);
            if ($attribute === null) {
                continue;
            }

            /** @var Rule $rule */
            $rule = $attribute->newInstance();
            $name = $property->getName();
            $type = match ($property->getType()) {
                'string' => self::TYPE_STRING,
                'int' => self::TYPE_INTEGER,
                'float' => self::TYPE_FLOAT,
                'bool' => self::TYPE_BOOLEAN,
                'array' => self::TYPE_JSON,
                'object' => self::TYPE_JSON,
                'DateTime' => self::TYPE_DATETIME,
                default => throw new \Exception('Unknown type: ' . $property->getType()),
            };

            $rule
                ->setName($name)
                ->setType($type);

            $this->rules[$name] = $rule;
        }
    }

    public function filter(Document $document): Document
    {
        return $document;
    }

    /**
     * @return array<string,Rule>
     */
    public function getRules(): array
    {
        return $this->rules;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getRequired()
    {
        return \array_filter($this->rules, fn (Rule $rule) => $rule->isRequired());
    }

    protected function removeRule(string $key): self
    {
        if (isset($this->rules[$key])) {
            unset($this->rules[$key]);
        }

        return $this;
    }

    public function isNone(): bool
    {
        return $this->none;
    }

    public function isAny(): bool
    {
        return $this->any;
    }

    public function isPublic(): bool
    {
        return $this->public;
    }

    private function getAttribute(ReflectionClass | ReflectionProperty $reflection, string $class): ?ReflectionAttribute
    {
        return $reflection->getAttributes($class)[0] ?? null;
    }
}

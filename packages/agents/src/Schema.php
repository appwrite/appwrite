<?php

namespace Utopia\Agents;

use Utopia\Agents\Schema\SchemaObject;

class Schema
{
    protected string $name;

    protected string $description;

    /**
     * @var SchemaObject Properties following JSON Schema format
     */
    protected SchemaObject $object;

    /**
     * @var array<int, string> List of required property names
     */
    protected array $required;

    /**
     * @param  string  $name  - name of the schema
     * @param  string  $description  - description of the schema
     * @param  SchemaObject  $object  a Schema object
     * @param  array<int, string>  $required  - array of required properties
     */
    public function __construct(
        string $name,
        string $description,
        SchemaObject $object,
        array $required = []
    ) {
        $this->name = $name;
        $this->description = $description;
        $this->object = $object;
        $this->required = $required;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getObject(): SchemaObject
    {
        return $this->object;
    }

    /**
     * @return array<int, string>
     */
    public function getRequired(): array
    {
        return $this->required;
    }

    /**
     * @return array<string, mixed>
     */
    public function getProperties(): array
    {
        return $this->object->getProperties();
    }

    /**
     * Convert the schema parameters to a simple JSON string
     */
    public function toJson(): string
    {
        $json = [];
        foreach ($this->object->getProperties() as $property => $value) {
            $description = isset($value['description']) && \is_string($value['description']) ? $value['description'] : '';
            $type = isset($value['type']) && \is_string($value['type']) ? $value['type'] : '';
            $json[$property] = $description.' ('.$type.')';
        }

        if (! json_encode($json)) {
            throw new \Exception('Invalid JSON: '.json_last_error_msg());
        }

        return json_encode($json);
    }
}

<?php

namespace Appwrite\Utopia\Response;

abstract class Model
{
    const TYPE_STRING = 'string';
    const TYPE_INTEGER = 'integer';
    const TYPE_FLOAT = 'float';
    const TYPE_BOOLEAN = 'boolean';
    const TYPE_JSON = 'json';

    /**
     * @var bool
     */
    protected $any = false;

    /**
     * @var array
     */
    protected $rules = [];

    /**
     * Get Name
     * 
     * @return string
     */
    abstract public function getName():string;

    /**
     * Get Collection
     * 
     * @return string
     */
    abstract public function getType():string;

    /**
     * Get Rules
     * 
     * @return array
     */
    public function getRules(): array
    {
        return $this->rules;
    }

    /**
     * Add a New Rule
     */
    protected function addRule(string $key, array $options): self
    {
        $this->rules[$key] = array_merge([
            'require' => true,
            'type' => '',
            'description' => '',
            'default' => null,
            'example' => '',
            'array' => false,
        ], $options);

        return $this;
    }

    public function getRequired()
    {
        $list = [];

        foreach($this->rules as $key => $rule) {
            if(isset($rule['require']) || $rule['require']) {
                $list[] = $key;
            }
        }

        return $list;
    }

    public function isAny(): bool
    {
        return $this->any;
    }
}
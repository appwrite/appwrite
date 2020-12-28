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
    protected $none = false;

    /**
     * @var bool
     */
    protected $any = false;

    /**
     * @var bool
     */
    protected $public = true;

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

    /**
     * Is None
     * 
     * Use to check if response is empty
     * 
     * @return bool
     */
    public function isNone(): bool
    {
        return $this->none;
    }

    /**
     * Is Any
     * 
     * Use to check if response is a wildcard
     * 
     * @return bool
     */
    public function isAny(): bool
    {
        return $this->any;
    }

    /**
     * Is Public
     * 
     * Should this model be publicly available in docs and spec files?
     * 
     * @return bool
     */
    public function isPublic(): bool
    {
        return $this->public;
    }
}
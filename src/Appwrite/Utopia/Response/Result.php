<?php

namespace Appwrite\Utopia\Response;

abstract class Result
{
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
    abstract public function getCollection():string;

    /**
     * Get Rules
     * 
     * @return string
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
            'type' => '',
            'description' => '',
            'default' => null,
            'example' => '',
            'array' => false,
        ], $options);

        return $this;
    }
}
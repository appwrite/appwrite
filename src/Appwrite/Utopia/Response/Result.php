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
    protected function addRule(string $key, string $type, string $description, string $example): self
    {
        $this->rules[$key] = [
            'type' => $type,
            'description' => $description,
            'example' => $example,
        ];

        return $this;
    }
}
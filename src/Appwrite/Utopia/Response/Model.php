<?php

namespace Appwrite\Utopia\Response;

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
     * @var bool
     */
    protected bool $none = false;

    /**
     * @var bool
     */
    protected bool $any = false;

    /**
     * @var bool
     */
    protected bool $public = true;

    /**
     * @var array
     */
    protected array $rules = [];

    /**
     * @var array
     */
    public array $conditions = [];


    /**
     * Filter Document Structure
     *
     * @return Document
     */
    public function filter(Document $document): Document
    {
        return $document;
    }

    /**
     * Get Name
     *
     * @return string
     */
    abstract public function getName(): string;

    /**
     * Get Collection
     *
     * @return string
     */
    abstract public function getType(): string;

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
     * If rule is an array of documents with varying models
     *
     * @param string $key
     * @param array $options
     */
    protected function addRule(string $key, array $options): self
    {
        $this->rules[$key] = array_merge([
            'required' => false,
            'array' => false,
            'description' => '',
            'example' => ''
        ], $options);

        return $this;
    }

    /**
     * Delete an existing Rule
     * If rule exists, it will be removed
     *
     * @param string $key
     * @param array $options
     */
    protected function removeRule(string $key): self
    {
        if (isset($this->rules[$key])) {
            unset($this->rules[$key]);
        }

        return $this;
    }

    public function getRequired()
    {
        $list = [];

        foreach ($this->rules as $key => $rule) {
            if ($rule['required'] ?? false) {
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

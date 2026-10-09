<?php

namespace Appwrite\Utopia\Response;

use Utopia\Database\DateTime;
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
    public const TYPE_RELATIONSHIP = 'relationship';
    public const TYPE_ARRAY = 'array';
    public const TYPE_ENUM = 'enum';
    public const TYPE_ID = 'id';

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
     * @param Document $document Document to apply filter on
     *
     * @return Document
     */
    public function filter(Document $document): Document
    {
        return $document;
    }

    /**
     * Shape a document as this model for an event payload published outside a response,
     * such as from a worker: the model filter, only the model's keys, and datetimes in
     * the same ISO 8601 format the response renders.
     *
     * @return array<string, mixed>
     */
    public function payload(Document $document): array
    {
        $rules = $this->getRules();
        $payload = $this->filter(new Document($document->getArrayCopy()))
            ->getArrayCopy(\array_keys($rules));

        foreach ($rules as $key => $rule) {
            if ($rule['type'] !== self::TYPE_DATETIME || !\array_key_exists($key, $payload)) {
                continue;
            }

            $payload[$key] = $rule['array'] && \is_array($payload[$key])
                ? \array_map(self::formatDatetime(...), $payload[$key])
                : self::formatDatetime($payload[$key]);
        }

        return $payload;
    }

    /**
     * Datetimes created in memory (never read back through the database `datetime` filter)
     * are in the database format `Y-m-d H:i:s.v`. Normalise them to the documented
     * ISO 8601 format with timezone. Values already carrying the `T` separator and
     * empty values are returned unchanged.
     */
    public static function formatDatetime(mixed $value): mixed
    {
        if (!\is_string($value) || $value === '' || ($value[10] ?? '') !== ' ') {
            return $value;
        }

        return DateTime::formatTz($value);
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
     * @return Model
     */
    protected function addRule(string $key, array $options): self
    {
        $this->rules[$key] = array_merge([
            'required' => true,
            'array' => false,
            'description' => '',
            'example' => '',
            'sensitive' => false,
            'readOnly' => false
        ], $options);

        return $this;
    }

    /**
     * @return array
     */
    public function getRequired(): array
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

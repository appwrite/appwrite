<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

use Utopia\Database\Database;
use Utopia\Database\Query;
use Utopia\Database\QueryContext;
use Utopia\Database\Validator\Queries\V2 as DocumentsValidator;
use Utopia\Validator;

class Types extends Validator
{
    protected string $message = 'Invalid queries';

    /**
     * @var array<string>
     */
    protected array $types;

    /**
     * @var array<Query>
     */
    protected array $queries;

    /**
     * @var QueryContext
     */
    protected ?QueryContext $context = null;

    /**
     * @param string[] $allowedAttributes
     */
    protected array $allowedAttributes;

    /**
     * @param array $types
     * @param QueryContext|null $context
     * @param array $allowedAttributes
     */
    public function __construct(array $types = [], ?QueryContext $context = null, array $allowedAttributes = [])
    {
        $this->types = $types;

        if (is_null($context)) {
            $context = new QueryContext();
        }

        $this->context = $context;
        $this->allowedAttributes = $allowedAttributes;
    }

    /**
     * Get Description.
     *
     * Returns validator description
     *
     * @return string
     */
    public function getDescription(): string
    {
        return $this->message;
    }

    /**
     * @param array<Query|string> $value
     * @return bool
     */
    public function isValid($value): bool
    {
        try {
            if (!is_array($value)) {
                throw new \Exception('Queries must be an array');
            }

            foreach ($value as $query) {
                if (!$query instanceof Query) {
                    $query = Query::parse($query);
                }

                if ($query->isNested()) {
                    if (!self::isValid($query->getValues())) {
                        throw new \Exception('Invalid queries');
                    }
                }

                if (!in_array($query->getMethod(), $this->types)) {
                    throw new \Exception("Query method {$query->getMethod()} not allowed");
                }

                if (
                    $query->getMethod() !== Query::TYPE_SELECT &&
                    !empty($query->getAttribute()) &&
                    !in_array($query->getAttribute(), $this->allowedAttributes)
                ) {
                    throw new \Exception('shmuel');
                }
            }

            $validator = new DocumentsValidator(
                $this->context,
                Database::VAR_INTEGER
            );

            if (!$validator->isValid($value)) {
                throw new \Exception($validator->getDescription());
            }

        } catch (\Throwable $e) {
            var_dump($e->getMessage());
            var_dump($e->getFile());
            var_dump($e->getLine());

            $this->message = $e->getMessage();
            return false;
        }

        return true;
    }

    /**
     * Is array
     *
     * Function will return true if object is array.
     *
     * @return bool
     */
    public function isArray(): bool
    {
        return true;
    }

    /**
     * Get Type
     *
     * Returns validator type.
     *
     * @return string
     */
    public function getType(): string
    {
        return self::TYPE_OBJECT;
    }
}

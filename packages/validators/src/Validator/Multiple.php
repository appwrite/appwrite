<?php

namespace Utopia\Validator;

use Utopia\Validator;

/**
 * Multiple
 *
 * Multiple validator is a container of multiple validations each acting as a rule.
 *
 * @package Utopia\Validator
 */
class Multiple extends Validator
{
    /**
     * @var Validator[]
     */
    protected $rules = [];

    /**
     * Constructor
     *
     * Multiple constructor can get any number of arguments containing Validator instances using PHP func_get_args function.
     *
     * Example:
     *
     * $multiple = new Multiple([$validator1, $validator2]);
     * $multiple = new Multiple([$validator1, $validator2, $validator3], self::TYPE_STRING);
     */
    public function __construct(array $rules, protected ?string $type = self::TYPE_MIXED)
    {
        foreach ($rules as $rule) {
            $this->addRule($rule);
        }
    }
    /**
     * Add rule
     *
     * Add a new rule to the end of the rules containing array
     */
    public function addRule(Validator $rule): static
    {
        $this->rules[] = $rule;

        return $this;
    }

    /**
     * Get Description
     *
     * Returns validator description
     */
    public function getDescription(): string
    {
        $description = '';
        foreach ($this->rules as $key => $rule) {
            $description .= ++$key . '. ' . $rule->getDescription() . " \n";
        }

        return $description;
    }

    /**
     * Is valid
     *
     * Validation will pass when all rules are valid if only one of the rules is invalid validation will fail.
     */
    public function isValid(mixed $value): bool
    {
        foreach ($this->rules as $rule) { /* @var $rule Validator */
            if (false === $rule->isValid($value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get Type
     *
     * Returns validator type.
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Is array
     *
     * Function will return true if object is array.
     */
    public function isArray(): bool
    {
        return true;
    }
}

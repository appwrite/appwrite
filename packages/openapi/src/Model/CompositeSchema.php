<?php

declare(strict_types=1);

namespace Utopia\OpenAPI\Model;

use Utopia\OpenAPI\Exception\InvalidSpecification;

final readonly class CompositeSchema extends Schema
{
    /** @var list<Schema> */
    public array $schemas;

    private ?StringSchema $stringEnum;

    /** @param list<Schema> $schemas */
    public function __construct(
        public ?Composition $composition,
        array $schemas = [],
        public ?Schema $not = null,
        public ?Discriminator $discriminator = null,
        ?string $title = null,
        string $description = '',
        bool $nullable = false,
        mixed $default = null,
        array $enum = [],
        ?string $format = null,
        bool $readOnly = false,
        bool $writeOnly = false,
        bool $deprecated = false,
        mixed $example = null,
        array $extensions = [],
        string $location = '#',
    ) {
        $openEnumIndex = self::legacyOpenStringEnumBranchIndex($composition, $schemas, $not, $enum, $discriminator);
        if ($openEnumIndex !== null) {
            /** @var StringSchema $enumBranch */
            $enumBranch = $schemas[$openEnumIndex];
            $schemas[$openEnumIndex] = new StringSchema(
                minLength: $enumBranch->minLength,
                maxLength: $enumBranch->maxLength,
                pattern: $enumBranch->pattern,
                title: $enumBranch->title,
                description: $enumBranch->description,
                nullable: $enumBranch->nullable,
                default: $enumBranch->default,
                enum: $enumBranch->enum,
                format: $enumBranch->format,
                readOnly: $enumBranch->readOnly,
                writeOnly: $enumBranch->writeOnly,
                deprecated: $enumBranch->deprecated,
                example: $enumBranch->example,
                extensions: $enumBranch->extensions,
                enumName: $enumBranch->enumName ?? $title,
                enumKeys: $enumBranch->enumKeys,
                open: true,
            );
        }
        $this->schemas = $schemas;

        parent::__construct($title, $description, $nullable, $default, $enum, $format, $readOnly, $writeOnly, $deprecated, $example, $extensions);

        $this->stringEnum = self::detectStringEnum(
            $composition,
            $this->schemas,
            $not,
            $enum,
            $discriminator,
            $title,
            $description,
            $nullable,
            $default,
            $extensions,
            $location,
        );
    }

    /**
     * Return the documented string enum, whether closed, open, or annotated.
     *
     * Closed annotated oneOf/anyOf of string consts synthesize a StringSchema
     * with open: false. Open unions (legacy multi-value enum or annotated consts
     * plus an unconstrained string) return a StringSchema with open: true.
     */
    public function stringEnum(): ?StringSchema
    {
        return $this->stringEnum;
    }

    /**
     * Return required literal conditions on referenced union members.
     *
     * Recognizes oneOf/anyOf members composed with allOf from one reference
     * and object properties with required scalar singleton enums. Nested
     * allOf is supported; references are not resolved. Returns no cases for
     * unsupported members, conflicting conditions, or repeated references.
     *
     * These are selection hints, not validation results: the referenced model
     * may impose other constraints, and anyOf members may overlap. Consumers
     * must choose their own selection policy without assuming exclusivity.
     *
     * Names and references are stored as values so PHP cannot coerce numeric
     * strings into integer array keys.
     *
     * @return list<array{reference: string, conditions: list<array{propertyName: string, value: bool|int|float|string}>}>
     */
    public function conditionalReferences(): array
    {
        if (
            ! \in_array($this->composition, [Composition::ONE_OF, Composition::ANY_OF], true)
            || $this->not !== null
            || $this->nullable
            || $this->enum !== []
        ) {
            return [];
        }

        $cases = [];
        $references = [];
        foreach ($this->schemas as $branch) {
            $reference = null;
            $conditions = [];
            $pending = [$branch];
            while ($pending !== []) {
                $schema = array_pop($pending);
                if ($schema instanceof ReferenceSchema) {
                    if ($reference !== null) {
                        return [];
                    }
                    $reference = $schema->reference;

                    continue;
                }
                if ($schema->nullable || $schema->enum !== []) {
                    return [];
                }
                if ($schema instanceof self) {
                    if ($schema->composition !== Composition::ALL_OF || $schema->not !== null || $schema->schemas === []) {
                        return [];
                    }
                    array_push($pending, ...array_reverse($schema->schemas));

                    continue;
                }
                if (
                    ! $schema instanceof ObjectSchema
                    || $schema->properties === []
                    || $schema->additionalProperties !== null
                    || $schema->minProperties !== null
                    || $schema->maxProperties !== null
                    || array_diff($schema->required, array_keys($schema->properties)) !== []
                ) {
                    return [];
                }
                foreach ($schema->properties as $name => $property) {
                    if (
                        ! \in_array((string) $name, $schema->required, true)
                        || $property->nullable
                        || ! ($property instanceof AnySchema || $property instanceof StringSchema || $property instanceof IntegerSchema || $property instanceof NumberSchema || $property instanceof BooleanSchema)
                        || \count($property->enum) !== 1
                        || ! \is_scalar($property->enum[0])
                    ) {
                        return [];
                    }
                    $value = $property->enum[0];
                    if (! self::isSupportedLiteral($property, $value)) {
                        return [];
                    }
                    if (\array_key_exists($name, $conditions) && $conditions[$name] !== $value) {
                        return [];
                    }
                    $conditions[$name] = $value;
                }
            }
            if ($reference === null || $conditions === [] || \in_array($reference, $references, true)) {
                return [];
            }
            $references[] = $reference;
            $literals = [];
            foreach ($conditions as $name => $value) {
                $literals[] = ['propertyName' => (string) $name, 'value' => $value];
            }
            $cases[] = ['reference' => $reference, 'conditions' => $literals];
        }

        return $cases;
    }

    private static function isSupportedLiteral(Schema $schema, bool|int|float|string $value): bool
    {
        if ($schema instanceof StringSchema) {
            return \is_string($value) && ! self::isConstrainedString($schema);
        }
        if ($schema instanceof IntegerSchema || $schema instanceof NumberSchema) {
            return (\is_int($value) || (\is_float($value) && is_finite($value)
                    && ($schema instanceof NumberSchema || floor($value) === $value)))
                && $schema->minimum === null
                && $schema->maximum === null
                && ! $schema->exclusiveMinimum
                && ! $schema->exclusiveMaximum
                && $schema->multipleOf === null
                && $schema->format === null;
        }
        if ($schema instanceof BooleanSchema) {
            return \is_bool($value) && $schema->format === null;
        }

        return $schema instanceof AnySchema && $schema->format === null;
    }

    /**
     * Return the documented values from an open string enum.
     *
     * An open string enum uses anyOf to combine documented string values with
     * one or more string branches that accept values outside that set.
     */
    public function openStringEnumBranch(): ?StringSchema
    {
        return $this->stringEnum?->open === true ? $this->stringEnum : null;
    }

    /**
     * @param  list<Schema>  $schemas
     * @param  list<mixed>  $enum
     */
    private static function legacyOpenStringEnumBranchIndex(?Composition $composition, array $schemas, ?Schema $not, array $enum, ?Discriminator $discriminator): ?int
    {
        if ($composition !== Composition::ANY_OF || $not !== null || $enum !== [] || $discriminator !== null) {
            return null;
        }

        $enumBranchIndex = null;
        $hasOpenBranch = false;

        foreach ($schemas as $index => $schema) {
            if (! $schema instanceof StringSchema) {
                return null;
            }

            if ($schema->enum === []) {
                if (! self::isUnconstrainedString($schema)) {
                    return null;
                }
                $hasOpenBranch = true;

                continue;
            }

            if ($enumBranchIndex !== null || \count($schema->enum) < 2) {
                return null;
            }

            $enumBranchIndex = $index;
        }

        return $hasOpenBranch ? $enumBranchIndex : null;
    }

    /**
     * @param  list<Schema>  $schemas
     * @param  list<mixed>  $enum
     * @param  array<string, mixed>  $extensions
     */
    private static function detectStringEnum(
        ?Composition $composition,
        array $schemas,
        ?Schema $not,
        array $enum,
        ?Discriminator $discriminator,
        ?string $title,
        string $description,
        bool $nullable,
        mixed $default,
        array $extensions,
        string $location,
    ): ?StringSchema {
        if (
            ($composition !== Composition::ONE_OF && $composition !== Composition::ANY_OF)
            || $not !== null
            || $enum !== []
            || $discriminator !== null
            || $schemas === []
        ) {
            return null;
        }

        /** @var list<array{value: string, title: ?string}> $consts */
        $consts = [];
        /** @var list<StringSchema> $nested */
        $nested = [];
        /** @var list<StringSchema> $multiValue */
        $multiValue = [];
        $unconstrained = 0;
        $hasReference = false;
        $hasNumericConst = false;
        $hasNonString = false;
        $hasConstrainedString = false;

        foreach ($schemas as $schema) {
            if ($schema instanceof ReferenceSchema) {
                $hasReference = true;

                continue;
            }

            if (self::isUnconstrainedString($schema)) {
                $unconstrained++;

                continue;
            }

            if ($schema instanceof StringSchema && self::isConstrainedString($schema) && $schema->enum === []) {
                $hasConstrainedString = true;

                continue;
            }

            $nestedEnum = $schema instanceof self ? $schema->stringEnum() : null;
            if ($nestedEnum instanceof StringSchema && $nestedEnum->open === false && $nestedEnum->enum !== []) {
                $nested[] = $nestedEnum;

                continue;
            }

            $constValue = self::stringConstValue($schema);
            if ($constValue !== null) {
                $consts[] = ['value' => $constValue, 'title' => $schema->title];

                continue;
            }

            if ($schema instanceof StringSchema && \count($schema->enum) > 1) {
                $multiValue[] = $schema;

                continue;
            }

            if (self::isNonStringConst($schema)) {
                $hasNumericConst = true;

                continue;
            }

            $hasNonString = true;
        }

        $hasAnnotatedValues = $consts !== [] || $nested !== [];
        if ($hasReference || $hasConstrainedString) {
            return null;
        }
        if ($hasAnnotatedValues && ($hasNumericConst || $hasNonString || $multiValue !== [])) {
            throw new InvalidSpecification("Invalid annotated string enumeration at {$location}");
        }
        if ($hasAnnotatedValues && $consts !== [] && $nested !== []) {
            throw new InvalidSpecification("Invalid annotated string enumeration at {$location}");
        }

        if ($unconstrained > 0) {
            if ($composition !== Composition::ANY_OF) {
                return null;
            }
            if (\count($multiValue) === 1 && $consts === [] && $nested === [] && ! $hasNumericConst && ! $hasNonString) {
                return $multiValue[0];
            }
            if (\count($nested) === 1 && $consts === [] && $multiValue === []) {
                return self::openFrom($nested[0], $title, $description, $nullable, $default, $extensions);
            }
            if ($consts !== []) {
                return self::synthesize($consts, true, $title, $description, $nullable, $default, $extensions);
            }

            return null;
        }

        if ($consts !== []) {
            return self::synthesize($consts, false, $title, $description, $nullable, $default, $extensions);
        }

        return null;
    }

    /**
     * @param  list<array{value: string, title: ?string}>  $branches
     * @param  array<string, mixed>  $extensions
     */
    private static function synthesize(
        array $branches,
        bool $open,
        ?string $title,
        string $description,
        bool $nullable,
        mixed $default,
        array $extensions,
    ): StringSchema {
        $values = [];
        $keys = [];
        $allTitled = true;
        foreach ($branches as $branch) {
            $values[] = $branch['value'];
            if ($branch['title'] === null || $branch['title'] === '') {
                $allTitled = false;

                continue;
            }
            $keys[] = $branch['title'];
        }

        return new StringSchema(
            title: $title,
            description: $description,
            nullable: $nullable,
            default: $default,
            enum: $values,
            extensions: $extensions,
            enumName: $title,
            enumKeys: $allTitled ? $keys : [],
            open: $open,
        );
    }

    /** @param  array<string, mixed>  $extensions */
    private static function openFrom(
        StringSchema $inner,
        ?string $title,
        string $description,
        bool $nullable,
        mixed $default,
        array $extensions,
    ): StringSchema {
        return new StringSchema(
            title: $title ?? $inner->title,
            description: $description !== '' ? $description : $inner->description,
            nullable: $nullable || $inner->nullable,
            default: $default ?? $inner->default,
            enum: $inner->enum,
            extensions: $extensions !== [] ? $extensions : $inner->extensions,
            enumName: $title ?? $inner->enumName,
            enumKeys: $inner->enumKeys,
            open: true,
        );
    }

    private static function isUnconstrainedString(Schema $schema): bool
    {
        return $schema instanceof StringSchema
            && $schema->enum === []
            && $schema->minLength === null
            && $schema->maxLength === null
            && $schema->pattern === null
            && $schema->format === null;
    }

    private static function isConstrainedString(StringSchema $schema): bool
    {
        return $schema->minLength !== null
            || $schema->maxLength !== null
            || $schema->pattern !== null
            || $schema->format !== null;
    }

    private static function stringConstValue(Schema $schema): ?string
    {
        if (\count($schema->enum) !== 1 || ! \is_string($schema->enum[0])) {
            return null;
        }
        if ($schema instanceof StringSchema || $schema instanceof AnySchema) {
            return $schema->enum[0];
        }

        return null;
    }

    private static function isNonStringConst(Schema $schema): bool
    {
        return \count($schema->enum) === 1 && ! \is_string($schema->enum[0]);
    }
}

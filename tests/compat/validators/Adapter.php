<?php

namespace Tests\Compat\Validators;

use Tests\Compat\Adapter as Base;
use Tests\Compat\Fault;
use Tests\Compat\Session;
use Utopia\Validator\AnyOf;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Domain;
use Utopia\Validator\Integer;
use Utopia\Validator\Multiple;
use Utopia\Validator\Nullable;
use Utopia\Validator\Phone;
use Utopia\Validator\Range;
use Utopia\Validator\Validator;
use Utopia\Validator\WhiteList;

/**
 * Maps tests/compat/validators/spec.json operations onto the PHP library. Glue only: no logic.
 *
 * A validator is a handle from `validator.new`, or a spec `{"class": "Text", "args": {...}}`:
 * the class under Utopia\Validator and its constructor's named arguments, where `validator`,
 * `validators` and `rules` hold validators (handles or specs). A value `{"$object": {...}}`
 * is a stdClass with those properties.
 */
final class Adapter implements Base
{
    public function operations(): array
    {
        return [
            'validator.new' => fn (array $a, Session $s) => $s->handle(self::build($a, $s)),
            'validator.is_valid' => fn (array $a, Session $s) => self::validator($a['validator'], $s)->isValid(self::value($a['value'] ?? null)),
            'validator.description' => fn (array $a, Session $s) => self::validator($a['validator'], $s)->getDescription(),
            'validator.type' => fn (array $a, Session $s) => self::validator($a['validator'], $s)->getType(),
            'validator.is_array' => fn (array $a, Session $s) => self::validator($a['validator'], $s)->isArray(),
            'validator.get' => fn (array $a, Session $s) => self::get(self::validator($a['validator'], $s), $a['property']),
            'validator.check' => function (array $a, Session $s): array {
                $validator = self::validator($a['validator'], $s);
                $valid = $validator->isValid(self::value($a['value'] ?? null));

                return ['valid' => $valid, 'description' => $validator->getDescription()];
            },
            'validator.sequence' => function (array $a, Session $s): array {
                $validator = self::validator($a['validator'], $s);
                $results = [];
                foreach ((array) $a['values'] as $value) {
                    $valid = $validator->isValid(self::value($value));
                    $results[] = ['valid' => $valid, 'description' => $validator->getDescription()];
                }

                return $results;
            },
            'multiple.add_rule' => function (array $a, Session $s): null {
                $multiple = $s->get($a['validator']);
                if (!$multiple instanceof Multiple) {
                    throw new Fault('multiple.add_rule needs a Multiple');
                }
                $multiple->addRule(self::validator($a['rule'], $s));

                return null;
            },
            'domain.restriction' => fn (array $a) => Domain::createRestriction(...$a),
            'phone.normalize' => fn (array $a) => Phone::normalize($a['value']),
        ];
    }

    /**
     * @param array<string, mixed> $spec
     */
    private static function build(array $spec, Session $s): Validator
    {
        $class = 'Utopia\\Validator\\' . $spec['class'];
        $args = (array) ($spec['args'] ?? []);
        if (!is_subclass_of($class, Validator::class)) {
            throw new Fault('unknown validator ' . $spec['class']);
        }
        foreach (['validator', 'rules', 'validators'] as $key) {
            if (\array_key_exists($key, $args)) {
                $args[$key] = $key === 'validator'
                    ? self::validator($args[$key], $s)
                    : array_map(fn (mixed $v) => self::validator($v, $s), (array) $args[$key]);
            }
        }

        return new $class(...$args);
    }

    private static function validator(mixed $v, Session $s): Validator
    {
        if (\is_array($v) && isset($v['$handle'])) {
            $validator = $s->get($v);
        } elseif (\is_array($v) && isset($v['class'])) {
            $validator = self::build($v, $s);
        } else {
            throw new Fault('expected a validator handle or spec');
        }
        if (!$validator instanceof Validator) {
            throw new Fault('handle is not a validator');
        }

        return $validator;
    }

    private static function value(mixed $v): mixed
    {
        if (\is_array($v) && \count($v) === 1 && \array_key_exists('$object', $v)) {
            return (object) array_map(self::value(...), (array) $v['$object']);
        }
        if (\is_array($v)) {
            return array_map(self::value(...), $v);
        }

        return $v;
    }

    /**
     * A getter's result; validators it returns are reported by description and type.
     */
    private static function get(Validator $validator, string $property): mixed
    {
        $describe = fn (Validator $v): array => ['description' => $v->getDescription(), 'type' => $v->getType()];

        return match (true) {
            $property === 'validators' && $validator instanceof AnyOf => array_map($describe, $validator->getValidators()),
            $property === 'validator' && ($validator instanceof ArrayList || $validator instanceof Nullable) => $describe($validator->getValidator()),
            $property === 'bits' && $validator instanceof Integer => $validator->getBits(),
            $property === 'unsigned' && $validator instanceof Integer => $validator->isUnsigned(),
            $property === 'format' && ($validator instanceof Integer || $validator instanceof Range) => $validator->getFormat(),
            $property === 'min' && $validator instanceof Range => $validator->getMin(),
            $property === 'max' && $validator instanceof Range => $validator->getMax(),
            $property === 'list' && $validator instanceof WhiteList => $validator->getList(),
            default => throw new Fault("no property {$property} on " . $validator::class),
        };
    }
}

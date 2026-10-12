<?php

/**
 * validator.*, role.*, permission.*: Utopia\Database\Validator\* (except
 * Authorization) and Helpers\Role, Helpers\Permission. Glue only; the Rust
 * twin is crates/tools/compat/src/libs/database/validators.rs.
 *
 * A validator spec is {"validator": "<name>", ...constructor arguments by
 * PHP parameter name}. Documents (`collection`, `currentDocument`,
 * `attributes`, `schemaAttributes`, `indexes`) cross as Values tags, dates
 * (`min`, `max`, `minAllowedDate`, `maxAllowedDate`) as strings for
 * `new \DateTime()`, nested query validators (`validators`) as specs.
 * Attribute's callbacks are constants (`attributeCount`, `attributeWidth`)
 * and `filter: "adapter"` (the SQL adapters' key filter).
 */

namespace Tests\Compat\Database;

use Tests\Compat\Fault;
use Tests\Compat\Session;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Validator\Attribute;
use Utopia\Database\Validator\BigInt;
use Utopia\Database\Validator\ByteLength;
use Utopia\Database\Validator\Datetime;
use Utopia\Database\Validator\Index;
use Utopia\Database\Validator\IndexDependency;
use Utopia\Database\Validator\IndexedQueries;
use Utopia\Database\Validator\Key;
use Utopia\Database\Validator\Label;
use Utopia\Database\Validator\ObjectValidator;
use Utopia\Database\Validator\Operator as OperatorValidator;
use Utopia\Database\Validator\PartialStructure;
use Utopia\Database\Validator\Permissions;
use Utopia\Database\Validator\Queries;
use Utopia\Database\Validator\Queries\Document as DocumentQueries;
use Utopia\Database\Validator\Queries\Documents as DocumentsQueries;
use Utopia\Database\Validator\Query\Cursor;
use Utopia\Database\Validator\Query\Filter;
use Utopia\Database\Validator\Query\Limit;
use Utopia\Database\Validator\Query\Offset;
use Utopia\Database\Validator\Query\Order;
use Utopia\Database\Validator\Query\Select;
use Utopia\Database\Validator\Roles;
use Utopia\Database\Validator\Sequence;
use Utopia\Database\Validator\Spatial;
use Utopia\Database\Validator\Structure;
use Utopia\Database\Validator\UID;
use Utopia\Database\Validator\Vector;
use Utopia\Validator\Range;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

$make = static function (mixed $spec) use (&$make): object {
    if (!\is_array($spec) || !\is_string($spec['validator'] ?? null)) {
        throw new Fault('expected a validator spec');
    }
    $name = $spec['validator'];
    unset($spec['validator']);
    foreach (['collection', 'currentDocument'] as $k) {
        if (\array_key_exists($k, $spec) && $spec[$k] !== null) {
            $spec[$k] = Values::document($spec[$k]);
        }
    }
    foreach (['attributes', 'schemaAttributes', 'indexes'] as $k) {
        if (\array_key_exists($k, $spec)) {
            $spec[$k] = Values::documents($spec[$k]);
        }
    }
    foreach (['min', 'max', 'minAllowedDate', 'maxAllowedDate'] as $k) {
        if (\array_key_exists($k, $spec)) {
            $spec[$k] = new \DateTime((string) $spec[$k]);
        }
    }
    if (\array_key_exists('validators', $spec)) {
        $spec['validators'] = array_map($make, Values::list($spec['validators']));
    }
    if (\array_key_exists('attributeCount', $spec)) {
        $count = $spec['attributeCount'];
        unset($spec['attributeCount']);
        $spec['attributeCountCallback'] = static fn () => $count;
    }
    if (\array_key_exists('attributeWidth', $spec)) {
        $width = $spec['attributeWidth'];
        unset($spec['attributeWidth']);
        $spec['attributeWidthCallback'] = static fn () => $width;
    }
    if (\array_key_exists('filter', $spec)) {
        unset($spec['filter']);
        $spec['filterCallback'] = static fn (string $value) => preg_replace('/[^A-Za-z0-9_\-]/', '', $value);
    }

    return match ($name) {
        'attribute' => new Attribute(...$spec),
        'big_int' => new BigInt(...$spec),
        'byte_length' => new ByteLength(...$spec),
        'datetime' => new Datetime(...$spec),
        'index' => new Index(...$spec),
        'index_dependency' => new IndexDependency(...$spec),
        'indexed_queries' => new IndexedQueries(...$spec),
        'key' => new Key(...$spec),
        'label' => new Label(...$spec),
        'object' => new ObjectValidator(...$spec),
        'operator' => new OperatorValidator(...$spec),
        'partial_structure' => new PartialStructure(...$spec),
        'permissions' => new Permissions(...$spec),
        'queries' => new Queries(...$spec),
        'queries_document' => new DocumentQueries(...$spec),
        'queries_documents' => new DocumentsQueries(...$spec),
        'query_cursor' => new Cursor(...$spec),
        'query_filter' => new Filter(...$spec),
        'query_limit' => new Limit(...$spec),
        'query_offset' => new Offset(...$spec),
        'query_order' => new Order(...$spec),
        'query_select' => new Select(...$spec),
        'roles' => new Roles(...$spec),
        'sequence' => new Sequence(...$spec),
        'spatial' => new Spatial(...$spec),
        'structure' => new Structure(...$spec),
        'uid' => new UID(...$spec),
        'vector' => new Vector(...$spec),
        default => throw new Fault("unknown validator `{$name}`"),
    };
};

$validate = static function (object $validator, mixed $value): array {
    $value = Values::decode($value);
    $out = ['valid' => $validator->isValid($value), 'description' => $validator->getDescription()];
    if ($value instanceof \Utopia\Database\Document) {
        $out['value'] = Values::encode($value);
    }

    return $out;
};

$role = static fn (Role $r): array => [
    'role' => $r->getRole(),
    'identifier' => $r->getIdentifier(),
    'dimension' => $r->getDimension(),
    'string' => $r->toString(),
];

$permission = static fn (Permission $p): array => [
    'permission' => $p->getPermission(),
    'role' => $p->getRole(),
    'identifier' => $p->getIdentifier(),
    'dimension' => $p->getDimension(),
    'string' => $p->toString(),
];

// Validators the cases register as formats, by name.
$formats = [
    'email' => static fn (array|\Utopia\Database\Document $attribute) => new class () extends Text {
        public function __construct()
        {
            parent::__construct(0);
        }

        public function getDescription(): string
        {
            return 'Value must be a valid email address';
        }

        public function isValid($value): bool
        {
            return (bool) \filter_var($value, FILTER_VALIDATE_EMAIL);
        }
    },
    'text' => static fn (array|\Utopia\Database\Document $attribute) => new Text((int) ($attribute['size'] ?? 0), 0),
    'range' => static fn (array|\Utopia\Database\Document $attribute) => new Range(
        $attribute['formatOptions']['min'] ?? 0,
        $attribute['formatOptions']['max'] ?? 0,
    ),
    'enum' => static fn (array|\Utopia\Database\Document $attribute) => new WhiteList($attribute['formatOptions']['elements'] ?? [], true),
];

return [
    'validator.new' => static fn (array $a, Session $s) => $s->handle($make($a)),
    'validator.check' => static function (array $a, Session $s) use ($make, $validate) {
        $value = $a['value'] ?? null;
        unset($a['value']);

        return $validate($make($a), $value);
    },
    'validator.is_valid' => static fn (array $a, Session $s) => $validate($s->get($a['validator'] ?? null), $a['value'] ?? null),
    'validator.description' => static fn (array $a, Session $s) => $s->get($a['validator'] ?? null)->getDescription(),
    'validator.meta' => static function (array $a, Session $s) {
        $v = $s->get($a['validator'] ?? null);
        $out = ['type' => $v->getType(), 'is_array' => $v->isArray()];
        if (method_exists($v, 'getMethodType')) {
            $out['method_type'] = $v->getMethodType();
        }
        if (method_exists($v, 'getMaxValuesCount')) {
            $out['max_values_count'] = $v->getMaxValuesCount();
        }
        if (method_exists($v, 'getSpatialType')) {
            $out['spatial_type'] = $v->getSpatialType();
        }

        return $out;
    },
    'validator.call' => static function (array $a, Session $s) {
        $v = $s->get($a['validator'] ?? null);
        $method = (string) ($a['method'] ?? '');
        if (!($v instanceof Attribute || $v instanceof Index) || !str_starts_with($method, 'check') || !method_exists($v, $method)) {
            throw new Fault("no check method `{$method}`");
        }

        return ['valid' => $v->{$method}(Values::document($a['value'] ?? null)), 'description' => $v->getDescription()];
    },
    'validator.big_int' => static function (array $a, Session $s) {
        $value = (string) ($a['value'] ?? '');

        return match ((string) ($a['fn'] ?? '')) {
            'isIntegerString' => BigInt::isIntegerString($value, (bool) ($a['signed'] ?? true)),
            'fitsPhpInt' => BigInt::fitsPhpInt($value, (bool) ($a['signed'] ?? true)),
            'fitsBigIntRange' => BigInt::fitsBigIntRange($value, (bool) ($a['signed'] ?? true), (bool) ($a['supportUnsigned64Bit'] ?? true)),
            'normalizeUnsignedString' => BigInt::normalizeUnsignedString($value),
            'compareUnsignedStrings' => BigInt::compareUnsignedStrings($value, (string) ($a['other'] ?? '')),
            'formatIntegerString' => BigInt::formatIntegerString($value),
            default => throw new Fault('unknown BigInt function'),
        };
    },
    'validator.spatial_wkt' => static fn (array $a, Session $s) => Spatial::isWKTString((string) ($a['value'] ?? '')),
    'validator.format_add' => static function (array $a, Session $s) use ($formats) {
        $factory = $formats[(string) ($a['factory'] ?? '')] ?? throw new Fault('unknown format factory');
        Structure::addFormat((string) $a['name'], \Closure::fromCallable($factory), (string) $a['type']);

        return null;
    },
    'validator.format_remove' => static function (array $a, Session $s) {
        Structure::removeFormat((string) $a['name']);

        return null;
    },
    'validator.format_has' => static fn (array $a, Session $s) => Structure::hasFormat((string) $a['name'], (string) $a['type']),
    'validator.format_get' => static function (array $a, Session $s) {
        $format = Structure::getFormat((string) $a['name'], (string) $a['type']);

        return ['type' => $format['type']];
    },
    'validator.formats' => static fn (array $a, Session $s) => array_map(static fn (array $f) => $f['type'], Structure::getFormats()),

    'role.parse' => static fn (array $a, Session $s) => $role(Role::parse((string) ($a['role'] ?? ''))),
    'role.new' => static fn (array $a, Session $s) => $role(new Role((string) ($a['role'] ?? ''), (string) ($a['identifier'] ?? ''), (string) ($a['dimension'] ?? ''))),
    'role.of' => static fn (array $a, Session $s) => $role(match ((string) ($a['kind'] ?? '')) {
        'any' => Role::any(),
        'guests' => Role::guests(),
        'users' => Role::users((string) ($a['dimension'] ?? '')),
        'user' => Role::user((string) ($a['identifier'] ?? ''), (string) ($a['dimension'] ?? '')),
        'team' => Role::team((string) ($a['identifier'] ?? ''), (string) ($a['dimension'] ?? '')),
        'member' => Role::member((string) ($a['identifier'] ?? '')),
        'label' => Role::label((string) ($a['identifier'] ?? '')),
        default => throw new Fault('unknown role factory'),
    }),
    'permission.parse' => static fn (array $a, Session $s) => $permission(Permission::parse((string) ($a['permission'] ?? ''))),
    'permission.new' => static fn (array $a, Session $s) => $permission(new Permission((string) ($a['permission'] ?? ''), (string) ($a['role'] ?? ''), (string) ($a['identifier'] ?? ''), (string) ($a['dimension'] ?? ''))),
    'permission.of' => static function (array $a, Session $s) {
        $r = new Role((string) ($a['role'] ?? ''), (string) ($a['identifier'] ?? ''), (string) ($a['dimension'] ?? ''));

        return match ((string) ($a['kind'] ?? '')) {
            'read' => Permission::read($r),
            'create' => Permission::create($r),
            'update' => Permission::update($r),
            'delete' => Permission::delete($r),
            'write' => Permission::write($r),
            default => throw new Fault('unknown permission factory'),
        };
    },
    'permission.aggregate' => static function (array $a, Session $s) {
        $permissions = $a['permissions'] ?? null;
        if ($permissions !== null) {
            $permissions = array_map(strval(...), Values::list($permissions));
        }
        if (\array_key_exists('allowed', $a)) {
            return Permission::aggregate($permissions, array_map(strval(...), Values::list($a['allowed'])));
        }

        return Permission::aggregate($permissions);
    },
];

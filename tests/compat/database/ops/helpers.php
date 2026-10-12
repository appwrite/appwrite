<?php

// document.*, id.*, datetime.*, authorization.*, connection.*, change.*,
// error.*: Document, Helpers\ID, DateTime, Validator\Authorization (and its
// Input), Connection, Change and the exception classes.
//
// A `doc` argument is a handle (from document.handle or document.clone,
// mutated in place by the setters) or a document value (a `$document` tag or
// a plain array, constructed afresh). `at`, a list of keys, walks from it to a
// nested document (getAttribute on documents, [] on arrays), as PHP code
// reaches one through object references.
//
// DateTime operations construct \DateTime from a string, whose unset fields
// PHP fills from the clock: when date_parse() leaves the date unset both sides
// report 'depends on now' rather than a value (each side asks its own parser),
// as they do for a blank string (which PHP reads as now).

use Tests\Compat\Database\Values;
use Tests\Compat\Fault;
use Tests\Compat\Session;
use Utopia\Database\Change;
use Utopia\Database\Connection;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception as DatabaseException;
use Utopia\Database\Exception\Order;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Authorization\Input;

$doc = static function (array $a, Session $s): mixed {
    $d = \is_array($a['doc'] ?? null) && \array_key_exists('$handle', $a['doc'])
        ? $s->get($a['doc'])
        : Values::document($a['doc'] ?? []);
    foreach ($a['at'] ?? [] as $key) {
        $d = $d instanceof Document ? $d->getAttribute((string) $key) : $d[$key];
    }
    if (!$d instanceof Document) {
        throw new Fault('`at` does not lead to a document');
    }

    return $d;
};
$auth = static function (array $a, Session $s): Authorization {
    if (isset($a['auth'])) {
        return $s->get($a['auth']);
    }
    $auth = new Authorization();
    foreach ($a['roles'] ?? [] as $role) {
        $auth->addRole($role);
    }

    return $auth;
};
$verdict = static fn (Authorization $auth, mixed $input): array => [
    'valid' => $auth->isValid($input),
    'description' => $auth->getDescription(),
];
$dependsOnNow = static function (string $s): bool {
    if (trim($s, " \t\n\r\0\x0B") === '') {
        return true; // new \DateTime('') is now
    }
    $p = date_parse($s);

    return $p['error_count'] === 0 && ($p['year'] === false || $p['month'] === false || $p['day'] === false);
};
$now = 'depends on now';

return [
    'document.new' => fn (array $a) => Values::encode(new Document(Values::decodeArray($a['input'] ?? []))),
    'document.handle' => fn (array $a, Session $s) => $s->handle(Values::document($a['input'] ?? [])),
    'document.clone' => fn (array $a, Session $s) => $s->handle(clone $doc($a, $s)),
    'document.dump' => fn (array $a, Session $s) => Values::encode($doc($a, $s)),
    'document.offset_get' => fn (array $a, Session $s) => Values::encode(@$doc($a, $s)[$a['key']]),
    'document.get_id' => fn (array $a, Session $s) => $doc($a, $s)->getId(),
    'document.get_sequence' => fn (array $a, Session $s) => $doc($a, $s)->getSequence(),
    'document.get_collection' => fn (array $a, Session $s) => $doc($a, $s)->getCollection(),
    'document.get_created_at' => fn (array $a, Session $s) => $doc($a, $s)->getCreatedAt(),
    'document.get_updated_at' => fn (array $a, Session $s) => $doc($a, $s)->getUpdatedAt(),
    'document.get_tenant' => fn (array $a, Session $s) => $doc($a, $s)->getTenant(),
    'document.get_permissions' => fn (array $a, Session $s) => Values::encode($doc($a, $s)->getPermissions()),
    'document.get_read' => fn (array $a, Session $s) => $doc($a, $s)->getRead(),
    'document.get_create' => fn (array $a, Session $s) => $doc($a, $s)->getCreate(),
    'document.get_update' => fn (array $a, Session $s) => $doc($a, $s)->getUpdate(),
    'document.get_delete' => fn (array $a, Session $s) => $doc($a, $s)->getDelete(),
    'document.get_write' => fn (array $a, Session $s) => $doc($a, $s)->getWrite(),
    'document.get_permissions_by_type' => fn (array $a, Session $s) => $doc($a, $s)->getPermissionsByType($a['type']),
    'document.get_attributes' => fn (array $a, Session $s) => Values::encode($doc($a, $s)->getAttributes()),
    'document.get_attribute' => fn (array $a, Session $s) => Values::encode($doc($a, $s)->getAttribute($a['key'], Values::decode($a['default'] ?? null))),
    'document.set_attribute' => fn (array $a, Session $s) => Values::encode($doc($a, $s)->setAttribute($a['key'], Values::decode($a['value'] ?? null), $a['type'] ?? Document::SET_TYPE_ASSIGN)),
    'document.set_attributes' => fn (array $a, Session $s) => Values::encode($doc($a, $s)->setAttributes(Values::decodeArray($a['attributes'] ?? []))),
    'document.remove_attribute' => fn (array $a, Session $s) => Values::encode($doc($a, $s)->removeAttribute($a['key'])),
    'document.find' => fn (array $a, Session $s) => Values::encode($doc($a, $s)->find($a['key'], Values::decode($a['find'] ?? null), $a['subject'] ?? '')),
    'document.find_and_replace' => fn (array $a, Session $s) => $doc($a, $s)->findAndReplace($a['key'], Values::decode($a['find'] ?? null), Values::decode($a['replace'] ?? null), $a['subject'] ?? ''),
    'document.find_and_remove' => fn (array $a, Session $s) => $doc($a, $s)->findAndRemove($a['key'], Values::decode($a['find'] ?? null), $a['subject'] ?? ''),
    'document.is_empty' => fn (array $a, Session $s) => $doc($a, $s)->isEmpty(),
    'document.is_set' => fn (array $a, Session $s) => $doc($a, $s)->isSet($a['key']),
    'document.get_array_copy' => fn (array $a, Session $s) => Values::encode($doc($a, $s)->getArrayCopy(Values::list($a['allow'] ?? []), Values::list($a['disallow'] ?? []))),

    'id.unique' => function (array $a) {
        $id = \array_key_exists('padding', $a) ? ID::unique($a['padding']) : ID::unique();

        return ['id' => $id, 'length' => \strlen($id), 'hex' => $id === '' || ctype_xdigit($id)];
    },
    'id.custom' => fn (array $a) => ID::custom($a['id']),

    'datetime.now' => fn () => DateTime::now(),
    'datetime.format' => fn (array $a) => $dependsOnNow($a['date']) ? $now : DateTime::format(new \DateTime($a['date'])),
    'datetime.add_seconds' => fn (array $a) => $dependsOnNow($a['date']) ? $now : DateTime::addSeconds(new \DateTime($a['date']), $a['seconds']),
    'datetime.set_timezone' => fn (array $a) => $dependsOnNow($a['datetime']) ? $now : DateTime::setTimezone($a['datetime']),
    'datetime.format_tz' => fn (array $a) => \is_string($a['value'] ?? null) && $dependsOnNow($a['value']) ? $now : DateTime::formatTz($a['value'] ?? null),

    'authorization.new' => fn (array $a, Session $s) => $s->handle($auth($a, $s)),
    'authorization.is_valid' => fn (array $a, Session $s) => $verdict($auth($a, $s), new Input($a['action'], $a['permissions'] ?? [])),
    'authorization.is_valid_value' => fn (array $a, Session $s) => $verdict($auth($a, $s), Values::decode($a['value'] ?? null)),
    'authorization.add_role' => fn (array $a, Session $s) => $auth($a, $s)->addRole($a['role']),
    'authorization.remove_role' => fn (array $a, Session $s) => $auth($a, $s)->removeRole($a['role']),
    'authorization.get_roles' => fn (array $a, Session $s) => $auth($a, $s)->getRoles(),
    'authorization.clean_roles' => fn (array $a, Session $s) => $auth($a, $s)->cleanRoles(),
    'authorization.has_role' => fn (array $a, Session $s) => $auth($a, $s)->hasRole($a['role']),
    'authorization.set_default_status' => fn (array $a, Session $s) => $auth($a, $s)->setDefaultStatus($a['status']),
    'authorization.set_status' => fn (array $a, Session $s) => $auth($a, $s)->setStatus($a['status']),
    'authorization.get_status' => fn (array $a, Session $s) => $auth($a, $s)->getStatus(),
    'authorization.enable' => fn (array $a, Session $s) => $auth($a, $s)->enable(),
    'authorization.disable' => fn (array $a, Session $s) => $auth($a, $s)->disable(),
    'authorization.reset' => fn (array $a, Session $s) => $auth($a, $s)->reset(),
    'authorization.get_description' => fn (array $a, Session $s) => $auth($a, $s)->getDescription(),
    'authorization.is_array' => fn (array $a, Session $s) => $auth($a, $s)->isArray(),
    'authorization.get_type' => fn (array $a, Session $s) => $auth($a, $s)->getType(),
    'authorization.skip' => function (array $a, Session $s) use ($auth) {
        $auth = $auth($a, $s);
        $inside = [];
        $observe = function (int $level) use (&$observe, &$inside, $auth, $a): mixed {
            $inside[] = $auth->getStatus();
            if ($level > 1) {
                $result = $auth->skip(fn () => $observe($level - 1));
                $inside[] = $auth->getStatus();

                return $result;
            }
            if ($a['throw'] ?? false) {
                throw new \Exception('thrown inside skip');
            }

            return isset($a['action']) ? $auth->isValid(new Input($a['action'], $a['permissions'] ?? [])) : null;
        };
        $valid = $auth->skip(fn () => $observe($a['depth'] ?? 1));

        return ['inside' => $inside, 'valid' => $valid, 'after' => $auth->getStatus()];
    },
    'authorization.input' => function (array $a) {
        $input = new Input($a['action'], $a['permissions'] ?? []);
        if (\array_key_exists('set_action', $a)) {
            $input->setAction($a['set_action']);
        }
        if (\array_key_exists('set_permissions', $a)) {
            $input->setPermissions($a['set_permissions']);
        }

        return ['action' => $input->getAction(), 'permissions' => $input->getPermissions()];
    },

    'connection.has_error' => fn (array $a) => Connection::hasError(new \Exception($a['message'])),

    'change.new' => function (array $a) {
        $change = new Change(Values::document($a['old'] ?? []), Values::document($a['new'] ?? []));
        if (\array_key_exists('set_old', $a)) {
            $change->setOld(Values::document($a['set_old']));
        }
        if (\array_key_exists('set_new', $a)) {
            $change->setNew(Values::document($a['set_new']));
        }

        return ['old' => Values::encode($change->getOld()), 'new' => Values::encode($change->getNew())];
    },

    'error.new' => function (array $a) {
        $class = $a['class'] ?? DatabaseException::class;
        if (!is_a($class, DatabaseException::class, true)) {
            throw new Fault("not a database exception: {$class}");
        }
        $e = $class === Order::class || is_subclass_of($class, Order::class)
            ? new $class($a['message'], $a['code'] ?? 0, null, $a['attribute'] ?? null)
            : new $class($a['message'], $a['code'] ?? 0);

        return [
            'class' => $e::class,
            'message' => $e->getMessage(),
            'code' => $e->getCode(),
            'attribute' => $e instanceof Order ? $e->getAttribute() : null,
            'database' => $e instanceof DatabaseException,
        ];
    },
];

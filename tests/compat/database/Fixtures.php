<?php

/**
 * The named fixtures the database cases refer to: what PHP code (the
 * library's e2e and unit tests) passes to the library as code, which a case
 * can only name. Each runtime implements the same fixtures; every one logs
 * what it is given to `db.log` (Core::log), so the cases compare it.
 *
 * - Global filters (Database::addFilter): static methods of FilterFixtures,
 *   so their signatures, which cache keys carry, are `Class::method`.
 * - Instance filters (Filter\Codec): Fixture\Codec\<Name> classes, whose
 *   signature is their class name.
 * - Hooks (Database::addHook): `{"$hook": "<name>", ...parameters}`, see
 *   Hooks::make().
 * - Mirror write filters (Mirror\Filter): Fixture\LogMirrorFilter (`log`).
 * - Structure formats: Formats::get().
 * - The SQLite adapter emulating MySQL (the suites' anonymous subclass with
 *   `protected bool $emulateMySQL = true`): Fixture\EmulatedSQLite.
 */

namespace Tests\Compat\Database {

    use Tests\Compat\Fault;
    use Utopia\Database\Document;
    use Utopia\Database\Hook\Permissions;
    use Utopia\Database\Hook\Relationships;
    use Utopia\Database\Hook\Tenancy;
    use Utopia\Query\Schema\ColumnType;
    use Utopia\Validator\Range;
    use Utopia\Validator\Text;
    use Utopia\Validator\WhiteList;

    /**
     * The global filters of the suites (Database::addFilter), by fixture
     * name. Each call logs `{"filter": <fixture>, "call": "encode"|"decode"}`.
     */
    final class FilterFixtures
    {
        /** Fixture name => method prefix. The fixtures `db.open` registers under their own name. */
        public const FIXTURES = [
            // AttributeTests::testEncryptAttributes
            'encrypt' => 'encrypt',
            // OperatorTests: the encrypt filter of testOperatorRefusesEncryptedAttributes...
            'encrypt_operator' => 'encryptOperator',
            // AttributeTests::testFilterNotAppliedWhenAttributeNotSelected
            'subQueryProbe' => 'subQueryProbe',
            // OperatorTests::testOperatorDoesNotDoubleDecode...
            'operator_double_decode' => 'operatorDoubleDecode',
            // JoinTests::testJoinedValuesDecodeLikeADirectRead
            'joinedSeal' => 'joinedSeal',
            // RelationshipTests: the decode spy of the nested slice fixture (createNestedSliceFixture())
            'nsSliceAuthorSpy' => 'nsSliceAuthorSpy',
        ];

        /** The fixtures every `db.open` registers, under their own names. */
        public const CANONICAL = ['encrypt', 'subQueryProbe', 'operator_double_decode', 'joinedSeal'];

        /**
         * @return array{encode: callable, decode: callable}
         */
        public static function get(string $fixture): array
        {
            $prefix = self::FIXTURES[$fixture] ?? throw new Fault("no filter fixture `{$fixture}`");

            return ['encode' => [self::class, "{$prefix}Encode"], 'decode' => [self::class, "{$prefix}Decode"]];
        }

        private static function log(string $fixture, string $call): void
        {
            Core::log(['filter' => $fixture, 'call' => $call]);
        }

        public static function encryptEncode(mixed $value): string
        {
            self::log('encrypt', 'encode');
            if (!\is_scalar($value) && $value !== null) {
                throw new \InvalidArgumentException('Encrypted filter input must be scalar or null');
            }

            return json_encode([
                'data' => base64_encode((string) $value),
                'method' => 'base64',
                'version' => 'v1',
            ]) ?: throw new \RuntimeException('Failed to encode encrypted filter input');
        }

        public static function encryptDecode(mixed $value): ?string
        {
            self::log('encrypt', 'decode');
            if (\is_null($value)) {
                return null;
            }
            if (!\is_string($value)) {
                throw new \InvalidArgumentException('Encrypted filter value must be a string');
            }
            $value = json_decode($value, true);
            if (!\is_array($value) || !\is_string($value['data'] ?? null)) {
                throw new \InvalidArgumentException('Encrypted filter payload is invalid');
            }
            $decoded = base64_decode($value['data'], true);
            if ($decoded === false) {
                throw new \InvalidArgumentException('Encrypted filter payload is not valid base64');
            }

            return $decoded;
        }

        public static function encryptOperatorEncode(mixed $value): ?string
        {
            self::log('encrypt_operator', 'encode');

            return \is_string($value) ? (string) json_encode(['data' => base64_encode($value)]) : null;
        }

        public static function encryptOperatorDecode(mixed $value): ?string
        {
            self::log('encrypt_operator', 'decode');
            $decoded = \is_string($value) ? json_decode($value, true) : null;

            return \is_array($decoded) && \is_string($decoded['data'] ?? null) ? (string) base64_decode($decoded['data']) : null;
        }

        public static function subQueryProbeEncode(mixed $value): mixed
        {
            self::log('subQueryProbe', 'encode');

            return null;
        }

        public static function subQueryProbeDecode(mixed $value): mixed
        {
            self::log('subQueryProbe', 'decode');

            return ['fanned', 'out'];
        }

        public static function operatorDoubleDecodeEncode(mixed $value): string|false
        {
            self::log('operator_double_decode', 'encode');
            if (!\is_scalar($value) && $value !== null) {
                throw new \InvalidArgumentException('Operator filter input must be scalar or null');
            }

            return json_encode(['data' => base64_encode((string) $value)]);
        }

        public static function operatorDoubleDecodeDecode(mixed $value): ?string
        {
            self::log('operator_double_decode', 'decode');
            if (\is_null($value)) {
                return null;
            }
            if (!\is_string($value)) {
                throw new \InvalidArgumentException('Encoded operator filter value must be a string');
            }
            $decoded = json_decode($value, true);
            if (!\is_array($decoded) || !\is_string($decoded['data'] ?? null)) {
                throw new \InvalidArgumentException('Encoded operator filter payload is invalid');
            }
            $plain = base64_decode($decoded['data'], true);
            if ($plain === false) {
                throw new \InvalidArgumentException('Encoded operator filter payload is not valid base64');
            }

            return $plain;
        }

        public static function joinedSealEncode(mixed $value): mixed
        {
            self::log('joinedSeal', 'encode');

            return \is_string($value) ? json_encode(['data' => base64_encode($value), 'method' => 'base64']) : $value;
        }

        public static function joinedSealDecode(mixed $value): mixed
        {
            self::log('joinedSeal', 'decode');
            $payload = \is_string($value) ? json_decode($value, true) : null;
            if (!\is_array($payload) || !\is_string($payload['data'] ?? null)) {
                return $value;
            }

            return base64_decode($payload['data'], true);
        }

        public static function nsSliceAuthorSpyEncode(mixed $value): mixed
        {
            self::log('nsSliceAuthorSpy', 'encode');

            return $value;
        }

        public static function nsSliceAuthorSpyDecode(mixed $value): mixed
        {
            self::log('nsSliceAuthorSpy', 'decode');

            return $value;
        }
    }

    /**
     * The Structure formats of the suites (Structure::addFormat), by name.
     */
    final class Formats
    {
        /**
         * @return array{callback: \Closure, type: ColumnType}
         */
        public static function get(string $name): array
        {
            return match ($name) {
                // AttributeTests::priceRangeFormat (testUpdateAttributeFormat, testUpdateAttributeStructure)
                'priceRange', 'priceRangeNew' => [
                    'callback' => static function (array $attribute): Range {
                        $options = $attribute['formatOptions'] ?? [];
                        if (!\is_array($options)) {
                            $options = [];
                        }
                        $min = $options['min'] ?? 0;
                        $max = $options['max'] ?? 0;

                        return new Range((float) (is_numeric($min) ? $min : 0), (float) (is_numeric($max) ? $max : 0));
                    },
                    'type' => ColumnType::Integer,
                ],
                // Unit Validator\StructureTest: tests/unit/Format.php, an email Text of the attribute's size.
                'email' => [
                    'callback' => static function (mixed $attribute): Text {
                        $size = \is_array($attribute) && is_numeric($attribute['size'] ?? 0) ? (int) ($attribute['size'] ?? 0) : 0;
                        Core::log(['format' => 'email', 'attribute' => Values::encode($attribute)]);

                        return new Fixture\EmailFormat($size);
                    },
                    'type' => ColumnType::String,
                ],
                // Unit Validator\StructureFormatTest: a Text of `formatOptions.maximum`, logging the attribute it is given.
                'structureFormatProbe' => [
                    'callback' => static function (array $attribute): Text {
                        Core::log(['format' => 'structureFormatProbe', 'attribute' => Values::encode($attribute)]);
                        $options = $attribute['formatOptions'] ?? [];
                        $size = \is_array($options) ? ($options['maximum'] ?? 0) : 0;

                        return new Text(\is_int($size) ? $size : 0);
                    },
                    'type' => ColumnType::String,
                ],
                // Unit Validator\AttributeTest::test_invalid_format_for_type.
                'testformat' => ['callback' => static fn (mixed $attribute): Text => new Text(100), 'type' => ColumnType::Integer],
                // Unit Attributes\UpdateAttributeValidationTest.
                'updateAttributeIntegerOnly' => ['callback' => static fn (mixed $attribute): Text => new Text(0), 'type' => ColumnType::Integer],
                // Unit Documents\StoredValueRevalidationTest.
                'storedRange' => [
                    'callback' => static fn (mixed $attribute): Range => new Range($attribute['formatOptions']['min'] ?? 0, $attribute['formatOptions']['max'] ?? 0, Range::TYPE_INTEGER),
                    'type' => ColumnType::Integer,
                ],
                // Unit Documents\CounterRangeRaceTest::range(): a Range of `formatOptions` (unbounded where not given).
                'raceIntegerRange' => [
                    'callback' => static fn (array $attribute): Range => new Range($attribute['formatOptions']['min'] ?? -INF, $attribute['formatOptions']['max'] ?? INF, Range::TYPE_INTEGER),
                    'type' => ColumnType::Integer,
                ],
                'raceDoubleRange' => [
                    'callback' => static fn (array $attribute): Range => new Range($attribute['formatOptions']['min'] ?? -INF, $attribute['formatOptions']['max'] ?? INF, Range::TYPE_FLOAT),
                    'type' => ColumnType::Double,
                ],
                'storedEnum' => [
                    'callback' => static fn (mixed $attribute): WhiteList => new WhiteList($attribute['formatOptions']['elements'] ?? [], true),
                    'type' => ColumnType::String,
                ],
                default => throw new Fault("no format fixture `{$name}`"),
            };
        }
    }

    /**
     * Hooks by spec: `"<name>"` or `{"$hook": "<name>", ...parameters}`.
     *
     *   Relationships  {prepare?: bool}   new Hook\Relationships(prepare)
     *   Permissions                       new Hook\Permissions()
     *   Tenancy        {column?: string}  new Hook\Tenancy(column)
     *   Log            {name?: string, events?: [<event value>...]}
     *                  a lifecycle hook (Named with `name`, Selective with
     *                  `events`) logging `{"hook": name|null, "event": <the
     *                  typed event, Values-encoded>}` (the suites' EventRecorder)
     *   Transform      {table: string, event: <event value>, append: string, prepend?: string}
     *                  a Hook\Transform adding `prepend` and `append` to the
     *                  statements of `event` (every event for `*`) that name
     *                  `table` (any for ''), logging `{"transform": event,
     *                  "query": <the statement before>}` (CollectionTests, the
     *                  adapter cases' statement capture)
     *   Nested         {marker: string}
     *                  a Hook\Nested logging `{"nested": [<collection id>,
     *                  <document id>]}` and giving a document whose id is
     *                  `marker` a new ID::unique() (NestedPreparationTests)
     *   Decorator      {attribute: string, value: mixed}
     *                  a Hook\Decorator setting `attribute` on every document
     *                  it is given, logging `{"decorate": <event value>,
     *                  "collection", "document": <id>}`
     *   Queries        {limit: int}
     *                  a Hook\Queries appending Query::limit(limit) when the
     *                  read has none, logging `{"rewrite": <event value>,
     *                  "collection", "queries": <count>}`
     */
    final class Hooks
    {
        public const CLASSES = [
            'Log' => Fixture\LogHook::class,
            'NamedLog' => Fixture\NamedLogHook::class,
            'SelectiveLog' => Fixture\SelectiveLogHook::class,
            'Transform' => Fixture\TransformHook::class,
            'Nested' => Fixture\NestedHook::class,
            'Decorator' => Fixture\DecoratorHook::class,
            'Queries' => Fixture\QueriesHook::class,
        ];

        public static function make(mixed $spec): \Utopia\Query\Hook
        {
            if (\is_string($spec)) {
                $spec = ['$hook' => $spec];
            }
            if (!\is_array($spec) || !\is_string($spec['$hook'] ?? null)) {
                throw new Fault('a hook is "<name>" or {"$hook": "<name>", ...}');
            }
            $name = $spec['$hook'];
            unset($spec['$hook'], $spec['as']);

            return match ($name) {
                'Relationships' => new Relationships((bool) ($spec['prepare'] ?? true)),
                'Permissions' => new Permissions(),
                'Tenancy' => isset($spec['column']) ? new Tenancy((string) $spec['column']) : new Tenancy(),
                'Log' => match (true) {
                    isset($spec['events']) => new Fixture\SelectiveLogHook(isset($spec['name']) ? (string) $spec['name'] : null, array_map(static fn ($e) => \Utopia\Database\Event::from((string) $e), Values::list($spec['events']))),
                    isset($spec['name']) => new Fixture\NamedLogHook((string) $spec['name']),
                    default => new Fixture\LogHook(),
                },
                'Transform' => new Fixture\TransformHook((string) ($spec['table'] ?? ''), \Utopia\Database\Event::from((string) ($spec['event'] ?? '*')), (string) ($spec['append'] ?? ''), (string) ($spec['prepend'] ?? '')),
                'Nested' => new Fixture\NestedHook((string) ($spec['marker'] ?? '')),
                'Decorator' => new Fixture\DecoratorHook((string) ($spec['attribute'] ?? ''), Values::decode($spec['value'] ?? null)),
                'Queries' => new Fixture\QueriesHook((int) ($spec['limit'] ?? 25)),
                default => throw new Fault("no hook fixture `{$name}`"),
            };
        }

        /**
         * A class-string argument (removeHook, removeTransform): a fixture's
         * name maps to its class, anything else is a PHP class name.
         */
        public static function className(string $name): string
        {
            return self::CLASSES[$name] ?? $name;
        }
    }

    /**
     * The instance filters (Filter\Codec) of the unit tests, by spec:
     * `"<fixture>"` or `{"$codec": "<fixture>", ...constructor arguments}`.
     *
     *   reversed  {name?: "reversed"}  strrev() both ways (tests/unit/Filter/Reversed.php)
     *   rot13     {name?: "rot13"}     str_rot13() both ways (Filter/Rot13.php)
     *   prefixed  {prefix}             named `text`, prepends the prefix and strips it back; Signed,
     *                                  its signature `<class>:<prefix>` (Filter/CodecTestPrefixed.php)
     *
     * A codec's signature (cache keys carry it) is its class name unless it is
     * Signed: the fixtures' classes are Fixture\Codec\Reversed, Rot13, Prefixed.
     */
    final class Codecs
    {
        public const CLASSES = [
            'reversed' => Fixture\Codec\Reversed::class,
            'rot13' => Fixture\Codec\Rot13::class,
            'prefixed' => Fixture\Codec\Prefixed::class,
        ];

        public static function make(mixed $spec): \Utopia\Database\Filter\Codec
        {
            if (\is_string($spec)) {
                $spec = ['$codec' => $spec];
            }
            $name = (string) ($spec['$codec'] ?? '');
            $class = self::CLASSES[$name] ?? throw new Fault("no codec fixture `{$name}`");
            unset($spec['$codec'], $spec['as']);

            return new $class(...$spec);
        }
    }
}

namespace Tests\Compat\Database\Fixture {

    use Tests\Compat\Database\Core;
    use Tests\Compat\Database\Values;
    use Utopia\Database\Adapter\SQLite;
    use Utopia\Database\Collection;
    use Utopia\Database\Database;
    use Utopia\Database\Document;
    use Utopia\Database\Event;
    use Utopia\Database\Event\Domain;
    use Utopia\Database\Hook\Decorator;
    use Utopia\Database\Hook\Lifecycle;
    use Utopia\Database\Hook\Named;
    use Utopia\Database\Hook\Nested;
    use Utopia\Database\Hook\Queries;
    use Utopia\Database\Hook\Selective;
    use Utopia\Database\Hook\Transform;
    use Utopia\Database\Id;
    use Utopia\Database\Mirror\Filter as MirrorFilter;
    use Utopia\Database\Query;

    /**
     * A Redis client whose every command fails as a lost server does (the
     * client GeneralTests stubs for its `unreachable` cache): the commands
     * Utopia\Cache\Adapter\Redis sends throw RedisException('Redis server
     * redis:6379 went away').
     */
    final class UnreachableRedisCommands
    {
        public const COMMANDS = ['hGet', 'hSet', 'hKeys', 'hGetAll', 'hMget', 'hMset', 'hDel', 'del', 'expire', 'eval', 'evalsha', 'flushDB', 'dbSize', 'ping'];

        public const MESSAGE = 'Redis server redis:6379 went away';

        /** Declares UnreachableRedis, its commands with \Redis's own signatures. */
        public static function declare(): void
        {
            if (class_exists(UnreachableRedis::class, false)) {
                return;
            }
            // A type with its classes fully qualified.
            $type = static function (?\ReflectionType $t) use (&$type): string {
                return match (true) {
                    $t === null => '',
                    $t instanceof \ReflectionUnionType => implode('|', array_map($type, $t->getTypes())),
                    $t instanceof \ReflectionNamedType => ($t->allowsNull() && $t->getName() !== 'mixed' && $t->getName() !== 'null' ? '?' : '') . ($t->isBuiltin() ? '' : '\\') . $t->getName(),
                    default => (string) $t,
                };
            };
            $methods = '';
            foreach (self::COMMANDS as $command) {
                $method = new \ReflectionMethod(\Redis::class, $command);
                $params = [];
                foreach ($method->getParameters() as $p) {
                    $params[] = ($p->hasType() ? $type($p->getType()) . ' ' : '') . ($p->isVariadic() ? '...' : '') . '$' . $p->getName() . ($p->isDefaultValueAvailable() ? ' = ' . var_export($p->getDefaultValue(), true) : '');
                }
                $returns = $method->hasReturnType() ? ': ' . $type($method->getReturnType()) : '';
                $methods .= "    public function {$method->getName()}(" . implode(', ', $params) . "){$returns}\n    {\n        throw new \\RedisException(UnreachableRedisCommands::MESSAGE);\n    }\n";
            }
            eval("namespace Tests\\Compat\\Database\\Fixture;\n\nfinal class UnreachableRedis extends \\Redis\n{\n    public function __construct()\n    {\n    }\n{$methods}}\n");
        }
    }

    /** The unit tests' email format (tests/unit/Format.php). */
    final class EmailFormat extends \Utopia\Validator\Text
    {
        public function getDescription(): string
        {
            return 'Value must be a valid email address';
        }

        public function isValid($value): bool
        {
            return (bool) filter_var($value, FILTER_VALIDATE_EMAIL);
        }
    }

    /** The SQLite adapter emulating MySQL, as SQLiteTest builds it. */
    class EmulatedSQLite extends SQLite
    {
        protected bool $emulateMySQL = true;
    }

    /** A lifecycle hook logging every event it receives. */
    class LogHook implements Lifecycle
    {
        public function handle(Domain $event): void
        {
            Core::log(['hook' => $this instanceof Named ? $this->getName() : null, 'event' => Values::encode($event)]);
        }
    }

    /** LogHook under a name (Hook\Named): replaces a hook of that name. */
    class NamedLogHook extends LogHook implements Named
    {
        public function __construct(private readonly string $name)
        {
        }

        public function getName(): string
        {
            return $this->name;
        }
    }

    /** LogHook receiving only `events` (Hook\Selective), named when `name` is given. */
    final class SelectiveLogHook extends LogHook implements Selective
    {
        /**
         * @param list<Event> $events
         */
        public function __construct(private readonly ?string $name, private readonly array $events)
        {
        }

        public function handles(Event $event): bool
        {
            return \in_array($event, $this->events, true);
        }

        public function handle(Domain $event): void
        {
            Core::log(['hook' => $this->name, 'event' => Values::encode($event)]);
        }
    }

    /**
     * A transform of the statements of one event (every event for `*`)
     * naming a table (any for ''): CollectionTests::testTransformations' and
     * the adapter cases' statement capture.
     */
    final class TransformHook implements Transform
    {
        public function __construct(private readonly string $table, private readonly Event $event, private readonly string $append, private readonly string $prepend = '')
        {
        }

        public function transform(Event $event, string $query): string
        {
            if (($this->event !== Event::All && $event !== $this->event) || !str_contains($query, $this->table)) {
                return $query;
            }
            Core::log(['transform' => $event->value, 'query' => $query]);

            return $this->prepend . $query . $this->append;
        }
    }

    /** NestedPreparationTests' nested hook. */
    final class NestedHook implements Nested
    {
        public function __construct(private readonly string $marker)
        {
        }

        public function prepare(Document $collection, Document $document): Document
        {
            Core::log(['nested' => [$collection->getId(), $document->getId()]]);

            return $document->getId() === $this->marker ? $document->setAttribute(Document::ID, Id::unique()) : $document;
        }
    }

    /** A decorator setting one attribute on every document it is given. */
    final class DecoratorHook implements Decorator
    {
        public function __construct(private readonly string $attribute, private readonly mixed $value)
        {
        }

        public function decorate(Event $event, Document $collection, Document $document): Document
        {
            Core::log(['decorate' => $event->value, 'collection' => $collection->getId(), 'document' => $document->getId()]);

            return $document->setAttribute($this->attribute, $this->value);
        }
    }

    /** A query rewrite adding a limit to reads without one. */
    final class QueriesHook implements Queries
    {
        public function __construct(private readonly int $limit)
        {
        }

        public function rewrite(Event $event, Collection $collection, array $queries): array
        {
            Core::log(['rewrite' => $event->value, 'collection' => $collection->getId(), 'queries' => \count($queries)]);
            foreach ($queries as $query) {
                if ($query->getMethod() === \Utopia\Query\Method::Limit) {
                    return $queries;
                }
            }

            return [...$queries, Query::limit($this->limit)];
        }
    }

    /**
     * The Mirror write filter fixture `log`: logs each hook it runs
     * (`{"mirror_filter": <hook>, "collection", ...}`) and passes its input
     * through.
     */
    final class LogMirrorFilter extends MirrorFilter
    {
        /**
         * @param array<string, mixed> $extra
         */
        private static function hook(string $name, string $collection, array $extra = []): void
        {
            Core::log(['mirror_filter' => $name, 'collection' => $collection, ...$extra]);
        }

        public function beforeCreateCollection(Database $source, Database $destination, string $collectionId, ?Document $collection = null): ?Document
        {
            self::hook('beforeCreateCollection', $collectionId);

            return $collection;
        }

        public function beforeUpdateCollection(Database $source, Database $destination, string $collectionId, ?Document $collection = null): ?Document
        {
            self::hook('beforeUpdateCollection', $collectionId);

            return $collection;
        }

        public function beforeDeleteCollection(Database $source, Database $destination, string $collectionId): void
        {
            self::hook('beforeDeleteCollection', $collectionId);
        }

        public function beforeCreateAttribute(Database $source, Database $destination, string $collectionId, string $attributeId, ?Document $attribute = null): ?Document
        {
            self::hook('beforeCreateAttribute', $collectionId, ['attribute' => $attributeId, 'document' => Values::encode($attribute)]);

            return $attribute;
        }

        public function beforeUpdateAttribute(Database $source, Database $destination, string $collectionId, string $attributeId, ?Document $attribute = null): ?Document
        {
            self::hook('beforeUpdateAttribute', $collectionId, ['attribute' => $attributeId]);

            return $attribute;
        }

        public function beforeDeleteAttribute(Database $source, Database $destination, string $collectionId, string $attributeId): void
        {
            self::hook('beforeDeleteAttribute', $collectionId, ['attribute' => $attributeId]);
        }

        public function beforeCreateIndex(Database $source, Database $destination, string $collectionId, string $indexId, ?Document $index = null): ?Document
        {
            self::hook('beforeCreateIndex', $collectionId, ['index' => $indexId, 'document' => Values::encode($index)]);

            return $index;
        }

        public function beforeUpdateIndex(Database $source, Database $destination, string $collectionId, string $indexId, ?Document $index = null): ?Document
        {
            self::hook('beforeUpdateIndex', $collectionId, ['index' => $indexId]);

            return $index;
        }

        public function beforeDeleteIndex(Database $source, Database $destination, string $collectionId, string $indexId): void
        {
            self::hook('beforeDeleteIndex', $collectionId, ['index' => $indexId]);
        }

        public function beforeCreateDocument(Database $source, Database $destination, string $collectionId, Document $document): Document
        {
            self::hook('beforeCreateDocument', $collectionId, ['document' => Values::encode($document)]);

            return $document;
        }

        public function afterCreateDocument(Database $source, Database $destination, string $collectionId, Document $document): Document
        {
            self::hook('afterCreateDocument', $collectionId, ['id' => $document->getId()]);

            return $document;
        }

        public function beforeUpdateDocument(Database $source, Database $destination, string $collectionId, Document $document): Document
        {
            self::hook('beforeUpdateDocument', $collectionId, ['document' => Values::encode($document)]);

            return $document;
        }

        public function afterUpdateDocument(Database $source, Database $destination, string $collectionId, Document $document): Document
        {
            self::hook('afterUpdateDocument', $collectionId, ['id' => $document->getId()]);

            return $document;
        }

        public function beforeUpdateDocuments(Database $source, Database $destination, string $collectionId, Document $updates, array $queries): Document
        {
            self::hook('beforeUpdateDocuments', $collectionId, ['updates' => Values::encode($updates), 'queries' => \count($queries)]);

            return $updates;
        }

        public function afterUpdateDocuments(Database $source, Database $destination, string $collectionId, Document $updates, array $queries): void
        {
            self::hook('afterUpdateDocuments', $collectionId, ['queries' => \count($queries)]);
        }

        public function beforeDeleteDocument(Database $source, Database $destination, string $collectionId, string $documentId): void
        {
            self::hook('beforeDeleteDocument', $collectionId, ['id' => $documentId]);
        }

        public function afterDeleteDocument(Database $source, Database $destination, string $collectionId, string $documentId): void
        {
            self::hook('afterDeleteDocument', $collectionId, ['id' => $documentId]);
        }

        public function beforeDeleteDocuments(Database $source, Database $destination, string $collectionId, array $queries): void
        {
            self::hook('beforeDeleteDocuments', $collectionId, ['queries' => \count($queries)]);
        }

        public function afterDeleteDocuments(Database $source, Database $destination, string $collectionId, array $queries): void
        {
            self::hook('afterDeleteDocuments', $collectionId, ['queries' => \count($queries)]);
        }

        public function beforeCreateOrUpdateDocument(Database $source, Database $destination, string $collectionId, Document $document): Document
        {
            self::hook('beforeCreateOrUpdateDocument', $collectionId, ['document' => Values::encode($document)]);

            return $document;
        }

        public function afterCreateOrUpdateDocument(Database $source, Database $destination, string $collectionId, Document $document): Document
        {
            self::hook('afterCreateOrUpdateDocument', $collectionId, ['id' => $document->getId()]);

            return $document;
        }
    }
}

namespace Tests\Compat\Database\Fixture\Codec {

    use Utopia\Database\Filter\Codec;
    use Utopia\Database\Filter\Signed;

    /** strrev() both ways (tests/unit/Filter/Reversed.php). */
    final readonly class Reversed implements Codec
    {
        public function __construct(private string $name = 'reversed')
        {
        }

        public function name(): string
        {
            return $this->name;
        }

        public function encode(mixed $value): mixed
        {
            return \is_string($value) ? strrev($value) : $value;
        }

        public function decode(mixed $value): mixed
        {
            return \is_string($value) ? strrev($value) : $value;
        }
    }

    /** str_rot13() both ways (tests/unit/Filter/Rot13.php). */
    final readonly class Rot13 implements Codec
    {
        public function __construct(private string $name = 'rot13')
        {
        }

        public function name(): string
        {
            return $this->name;
        }

        public function encode(mixed $value): mixed
        {
            return \is_string($value) ? str_rot13($value) : $value;
        }

        public function decode(mixed $value): mixed
        {
            return \is_string($value) ? str_rot13($value) : $value;
        }
    }

    /** A `text` codec prefixing its values (tests/unit/Filter/CodecTestPrefixed.php). */
    final readonly class Prefixed implements Signed
    {
        public function __construct(private string $prefix)
        {
        }

        public function name(): string
        {
            return 'text';
        }

        public function encode(mixed $value): mixed
        {
            return \is_string($value) ? $this->prefix . $value : $value;
        }

        public function decode(mixed $value): mixed
        {
            return \is_string($value) && str_starts_with($value, $this->prefix) ? substr($value, \strlen($this->prefix)) : $value;
        }

        public function signature(): string
        {
            return self::class . ':' . $this->prefix;
        }
    }
}

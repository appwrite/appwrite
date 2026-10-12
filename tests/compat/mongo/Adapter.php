<?php

namespace Tests\Compat\Mongo;

use MongoDB\BSON\Document;
use MongoDB\BSON\Type;
use Tests\Compat\Adapter as Base;
use Tests\Compat\Benchmarks;
use Tests\Compat\Fault;
use Tests\Compat\Session;
use Utopia\Mongo\Auth;
use Utopia\Mongo\Client;
use Utopia\Mongo\Exception;

// The exception subclasses live in Exception.php, which autoloading by class
// name never loads for them.
class_exists(Exception::class);

/**
 * Maps tests/compat/mongo/spec.json operations onto utopia-php/mongo. Glue
 * only; values cross as described in spec.json (`$object` for a non-empty
 * stdClass, `$bson` for a BSON type in canonical extended JSON).
 */
final class Adapter implements Base, Benchmarks
{
    public function benchmarks(): array
    {
        $client = function (Session $s): Client {
            $client = self::connect($s, ['database' => $s->ns . '_bench']);
            $client->insert('bench', ['_id' => 'one', 'name' => 'Ada', 'n' => 1]);

            return $client;
        };

        return [
            'insert' => ['Insert one document (a fresh UUIDv7 id) and return it', function (Session $s) use ($client) {
                $c = $client($s);

                return fn () => $c->insert('bench', ['name' => 'Grace', 'n' => 2]);
            }],
            'find' => ['Find one document by id', function (Session $s) use ($client) {
                $c = $client($s);

                return fn () => $c->find('bench', ['_id' => 'one']);
            }],
            'update' => ['Update one document by id ($inc)', function (Session $s) use ($client) {
                $c = $client($s);

                return fn () => $c->update('bench', ['_id' => 'one'], ['$inc' => ['n' => 1]]);
            }],
            'count' => ['Count the documents matching a filter', function (Session $s) use ($client) {
                $c = $client($s);

                return fn () => $c->count('bench', ['name' => 'Ada'], []);
            }],
            'write_concern' => ['Build a write concern from options (no I/O)', function (Session $s) {
                $c = self::client($s, []);

                return fn () => $c->createWriteConcern(['w' => 'majority', 'j' => true, 'wtimeout' => 5000]);
            }],
            'scram' => ['Answer a SCRAM-SHA-256 challenge (4096 PBKDF2 rounds)', function (Session $s) {
                return function () {
                    $auth = new Auth(['authcid' => 'root', 'secret' => 'password']);
                    $auth->start();

                    return $auth->createResponse('r=' . $auth->getCnonce() . 'server,s=' . base64_encode('salt') . ',i=4096');
                };
            }],
        ];
    }

    public function operations(): array
    {
        $ops = [
            'client.new' => fn (array $a, Session $s) => $s->handle(($a['connect'] ?? true) ? self::connect($s, $a) : self::client($s, $a)),
            'client.connect' => fn (array $a, Session $s) => self::marker(self::c($a, $s)->connect()),
            'client.get_host' => fn (array $a, Session $s) => self::c($a, $s)->getHost(),
            'client.create_uuid' => fn (array $a, Session $s) => self::c($a, $s)->createUuid(),
            'client.select_database' => fn (array $a, Session $s) => self::marker(self::c($a, $s)->selectDatabase()),
            'client.create_database' => fn (array $a, Session $s) => self::marker(self::c($a, $s)->createDatabase()),
            'client.select_collection' => fn (array $a, Session $s) => self::marker(self::c($a, $s)->selectCollection($a['name'] ?? '')),
            'client.query' => fn (array $a, Session $s) => self::encode(self::c($a, $s)->query(self::command($a), self::db($a, $s))),
            'client.list_database_names' => fn (array $a, Session $s) => self::databases(self::c($a, $s)->listDatabaseNames(), $s),
            'client.drop_database' => fn (array $a, Session $s) => self::c($a, $s)->dropDatabase(self::array($a, 'options'), self::db($a, $s)),
            'client.create_collection' => fn (array $a, Session $s) => self::c($a, $s)->createCollection($a['name'], self::options($a)),
            'client.drop_collection' => fn (array $a, Session $s) => self::c($a, $s)->dropCollection($a['name'], self::options($a)),
            'client.list_collection_names' => fn (array $a, Session $s) => self::encode(self::c($a, $s)->listCollectionNames(self::array($a, 'filter'), self::options($a))),
            'client.create_indexes' => fn (array $a, Session $s) => self::c($a, $s)->createIndexes($a['collection'], self::array($a, 'indexes'), self::options($a)),
            'client.drop_indexes' => fn (array $a, Session $s) => self::marker(self::c($a, $s)->dropIndexes($a['collection'], self::decode($a['indexes'] ?? []), self::options($a))),
            'client.insert' => fn (array $a, Session $s) => self::encode(self::c($a, $s)->insert($a['collection'], self::array($a, 'document'), self::options($a))),
            'client.insert_many' => fn (array $a, Session $s) => self::encode(self::c($a, $s)->insertMany($a['collection'], self::array($a, 'documents'), self::options($a))),
            'client.last_document' => fn (array $a, Session $s) => self::encode(self::c($a, $s)->lastDocument($a['collection'])),
            'client.update' => fn (array $a, Session $s) => self::c($a, $s)->update($a['collection'], self::array($a, 'where'), self::array($a, 'updates'), self::options($a), (bool) ($a['multi'] ?? false)),
            'client.upsert' => fn (array $a, Session $s) => self::c($a, $s)->upsert($a['collection'], self::array($a, 'operations'), self::options($a)),
            'client.find' => fn (array $a, Session $s) => self::encode(self::c($a, $s)->find($a['collection'], self::array($a, 'filters'), self::options($a))),
            'client.aggregate' => fn (array $a, Session $s) => self::encode(self::c($a, $s)->aggregate($a['collection'], self::array($a, 'pipeline'), self::options($a))),
            'client.find_and_modify' => fn (array $a, Session $s) => self::encode(self::c($a, $s)->findAndModify($a['collection'], self::array($a, 'update'), (bool) ($a['remove'] ?? false), self::array($a, 'filters'), self::options($a))),
            'client.get_more' => fn (array $a, Session $s) => self::encode(self::c($a, $s)->getMore((int) self::decode($a['cursor_id']), $a['collection'], ...(isset($a['batch_size']) ? [$a['batch_size']] : []))),
            'client.delete' => fn (array $a, Session $s) => self::c($a, $s)->delete($a['collection'], self::array($a, 'filters'), $a['limit'] ?? 1, self::array($a, 'delete_options'), self::options($a)),
            'client.count' => fn (array $a, Session $s) => self::c($a, $s)->count($a['collection'], self::array($a, 'filters'), self::options($a)),
            'client.start_session' => fn (array $a, Session $s) => self::encode(self::c($a, $s)->startSession(self::array($a, 'options'))),
            'client.start_transaction' => fn (array $a, Session $s) => self::c($a, $s)->startTransaction(self::session($a['session']), self::array($a, 'options')),
            'client.commit_transaction' => fn (array $a, Session $s) => self::encode(self::c($a, $s)->commitTransaction(self::session($a['session']), self::array($a, 'options'))),
            'client.abort_transaction' => fn (array $a, Session $s) => self::encode(self::c($a, $s)->abortTransaction(self::session($a['session']), self::array($a, 'options'))),
            'client.end_sessions' => fn (array $a, Session $s) => self::encode(self::c($a, $s)->endSessions(array_map(self::session(...), $a['sessions'] ?? []), self::array($a, 'options'))),
            'client.with_transaction' => fn (array $a, Session $s) => self::withTransaction($a, $s),
            'client.is_replica_set' => fn (array $a, Session $s) => self::c($a, $s)->isReplicaSet(),
            'client.close' => function (array $a, Session $s) {
                self::c($a, $s)->close();

                return;
            },
            'client.is_transient_transaction_error' => fn (array $a, Session $s) => self::client($s, [])->isTransientTransactionError(self::exception($a)),
            'client.is_unknown_transaction_commit_result' => fn (array $a, Session $s) => self::client($s, [])->isUnknownTransactionCommitResult(self::exception($a)),
            'client.get_operation_time' => fn (array $a, Session $s) => self::encode(self::c($a, $s)->getOperationTime()),
            'client.get_cluster_time' => fn (array $a, Session $s) => self::encode(self::c($a, $s)->getClusterTime()),
            'client.get_session_state' => fn (array $a, Session $s) => self::c($a, $s)->getSessionState(self::session($a['session'])),
            'client.create_write_concern' => fn (array $a, Session $s) => self::encode(self::client($s, [])->createWriteConcern(self::decode($a['concern'] ?? null))),
            'client.create_read_concern' => fn (array $a, Session $s) => self::encode(self::client($s, [])->createReadConcern(self::decode($a['concern'] ?? null))),
            'client.get_connection_info' => fn (array $a, Session $s) => self::c($a, $s)->getConnectionInfo(),
            'client.cleanup_stale_sessions' => function (array $a, Session $s) {
                self::c($a, $s)->cleanupStaleSessions();

                return;
            },

            'exception.new' => fn (array $a, Session $s) => self::inspect(self::exception($a)),
            'exception.from_response' => fn (array $a, Session $s) => self::inspect(Exception::fromResponse((object) self::array($a, 'response'), $a['operation_type'] ?? null)),

            'auth.new' => fn (array $a, Session $s) => $s->handle(new Auth(array_filter([
                'authcid' => $a['authcid'] ?? null,
                'secret' => $a['secret'] ?? null,
                'authzid' => $a['authzid'] ?? null,
                'authSource' => $a['auth_source'] ?? null,
            ], fn ($v) => $v !== null))),
            'auth.start' => fn (array $a, Session $s) => self::encode(self::auth($a, $s)->start()),
            'auth.continue' => fn (array $a, Session $s) => self::encode(self::auth($a, $s)->continue((object) ['conversationId' => $a['conversation_id'] ?? null, 'payload' => new \MongoDB\BSON\Binary((string) ($a['payload'] ?? ''), 0)])),
            'auth.create_response' => fn (array $a, Session $s) => self::auth($a, $s)->createResponse($a['challenge'] ?? null),
            'auth.verify' => fn (array $a, Session $s) => self::auth($a, $s)->verify($a['data']),
            'auth.get_cnonce' => fn (array $a, Session $s) => self::auth($a, $s)->getCnonce(),
            'auth.get_salted_password' => fn (array $a, Session $s) => self::auth($a, $s)->getSaltedPassword(),
            'auth.get_auth_message' => fn (array $a, Session $s) => self::auth($a, $s)->getAuthMessage(),
            'auth.encode_credentials' => fn (array $a, Session $s) => Auth::encodeCredentials($a['username'] ?? '', $a['password'] ?? ''),
            // A fixture server: its first message for the conversation's
            // nonce (`r=<cnonce><suffix>,s=<base64 salt>,i=<iterations>`).
            'auth.server_first' => fn (array $a, Session $s) => 'r=' . self::auth($a, $s)->getCnonce() . ($a['suffix'] ?? '') . ',s=' . base64_encode($a['salt'] ?? '') . ',i=' . ($a['iterations'] ?? 4096),
        ];

        // `inspect: true` on a client operation: a Utopia\Mongo\Exception it
        // throws is returned as {"$exception": ...} (code and category too).
        foreach ($ops as $name => $op) {
            if (!str_starts_with($name, 'client.')) {
                continue;
            }
            $ops[$name] = function (array $a, Session $s) use ($op) {
                if (!($a['inspect'] ?? false)) {
                    return $op($a, $s);
                }
                try {
                    return $op($a, $s);
                } catch (Exception $e) {
                    return ['$exception' => self::inspect($e)];
                }
            };
        }

        return $ops;
    }

    /**
     * The `mongo` service's URL, parsed.
     *
     * @return array<string, mixed>
     */
    private static function url(Session $s): array
    {
        return parse_url($s->service('mongo')) ?: throw new Fault('invalid `mongo` service URL');
    }

    /**
     * `new Client(...)` from arguments, defaulting to the `mongo` service and
     * database `${ns}`.
     *
     * @param array<string, mixed> $a
     */
    private static function client(Session $s, array $a): Client
    {
        $url = self::url($s);

        return new Client(
            database: $a['database'] ?? $s->ns,
            host: $a['host'] ?? $url['host'],
            port: $a['port'] ?? $url['port'],
            user: $a['user'] ?? urldecode($url['user'] ?? ''),
            password: $a['password'] ?? urldecode($url['pass'] ?? ''),
            useCoroutine: false,
            tls: $a['tls'] ?? false,
            authSource: $a['auth_source'] ?? null,
            timeout: $a['timeout'] ?? 30.0,
            connectTimeout: $a['connect_timeout'] ?? null,
        );
    }

    /**
     * @param array<string, mixed> $a
     */
    private static function connect(Session $s, array $a): Client
    {
        $client = self::client($s, $a);
        $client->connect();

        return $client;
    }

    /**
     * @param array<string, mixed> $a
     */
    private static function c(array $a, Session $s): Client
    {
        $client = $s->get($a['client'] ?? null);
        if (!$client instanceof Client) {
            throw new Fault('`client` is not a client handle');
        }

        return $client;
    }

    /**
     * @param array<string, mixed> $a
     */
    private static function auth(array $a, Session $s): Auth
    {
        $auth = $s->get($a['auth'] ?? null);
        if (!$auth instanceof Auth) {
            throw new Fault('`auth` is not an auth handle');
        }

        return $auth;
    }

    /**
     * The `db` argument; `${ns}_<name>` databases stay in the run's namespace.
     *
     * @param array<string, mixed> $a
     */
    private static function db(array $a, Session $s): ?string
    {
        return $a['db'] ?? null;
    }

    /**
     * `command`, with `session` decoded into the session array.
     *
     * @param array<string, mixed> $a
     * @return array<string, mixed>
     */
    private static function command(array $a): array
    {
        $command = self::array($a, 'command');
        if (isset($a['session'])) {
            $command['session'] = self::session($a['session']);
        }

        return $command;
    }

    /**
     * `options` (an array), with `session` decoded into the session array.
     *
     * @param array<string, mixed> $a
     * @return array<string, mixed>
     */
    private static function options(array $a): array
    {
        $options = self::array($a, 'options');
        if (isset($a['session'])) {
            $options['session'] = self::session($a['session']);
        }

        return $options;
    }

    /**
     * A session array from its encoded form.
     *
     * @return array<string, mixed>
     */
    private static function session(mixed $value): array
    {
        $session = self::decode($value);
        if (!\is_array($session)) {
            throw new Fault('a session is {"id": ..., "sessionId": ...}');
        }

        return $session;
    }

    /**
     * An array argument (`{}` is an empty one).
     *
     * @param array<string, mixed> $a
     * @return array<mixed>
     */
    private static function array(array $a, string $key): array
    {
        $value = self::decode($a[$key] ?? []);
        if ($value instanceof \stdClass && (array) $value === []) {
            return [];
        }
        if (!\is_array($value)) {
            throw new Fault("`{$key}` must be an array");
        }

        return $value;
    }

    /**
     * A Utopia\Mongo\Exception (or subclass `class`) from arguments.
     *
     * @param array<string, mixed> $a
     */
    private static function exception(array $a): Exception
    {
        $class = 'Utopia\\Mongo\\' . ($a['class'] ?? 'Exception');
        if (!is_a($class, Exception::class, true)) {
            throw new Fault("unknown exception class `{$class}`");
        }
        if ($class === 'Utopia\\Mongo\\BulkWriteException') {
            return new $class($a['message'] ?? '', self::array($a, 'result'), $a['code'] ?? 0);
        }

        return new $class(
            $a['message'] ?? '',
            $a['code'] ?? 0,
            null,
            self::array($a, 'error_labels'),
            isset($a['write_errors']) ? self::array($a, 'write_errors') : null,
            isset($a['write_concern_errors']) ? self::array($a, 'write_concern_errors') : null,
            $a['operation_type'] ?? null,
        );
    }

    /**
     * Everything an exception answers.
     *
     * @return array<string, mixed>
     */
    private static function inspect(Exception $e): array
    {
        $out = [
            'class' => $e::class,
            'message' => $e->getMessage(),
            'code' => $e->getCode(),
            'transient' => $e->isTransientError(),
            'network' => $e->isNetworkError(),
            'retryable_write' => $e->isRetryableWrite(),
            'duplicate_key' => $e->isDuplicateKeyError(),
            'write_concern' => $e->isWriteConcernError(),
            'unsent' => $e->isUnsentError(),
            'timeout' => $e->isTimeoutError(),
            'error_labels' => self::encode($e->getErrorLabels()),
            'write_errors' => self::encode($e->getWriteErrors()),
            'write_concern_errors' => self::encode($e->getWriteConcernErrors()),
            'operation_type' => $e->getOperationType(),
            'category' => $e->getErrorCategory(),
        ];
        if ($e instanceof \Utopia\Mongo\BulkWriteException) {
            $out['result'] = self::encode($e->getResult());
        }

        return $out;
    }

    /**
     * `withTransaction()` with a callback that runs `steps` (client
     * operations, each given the callback's session) and returns `return`,
     * or throws `throw` ({message, code}).
     *
     * @param array<string, mixed> $a
     */
    private static function withTransaction(array $a, Session $s): mixed
    {
        $client = self::c($a, $s);
        $callback = self::array($a, 'callback');
        $calls = [];
        $ops = (new self())->operations();
        $result = $client->withTransaction(self::session($a['session']), function (array $session) use ($callback, $ops, $s, $a, &$calls) {
            $results = [];
            foreach ($callback['steps'] ?? [] as $step) {
                $args = (array) ($step['args'] ?? []);
                $args['client'] = $a['client'];
                $args['session'] = self::encode($session);
                $results[] = $ops[$step['op']]($args, $s);
            }
            $calls[] = $results;
            if (isset($callback['throw'])) {
                throw new Exception($callback['throw']['message'] ?? '', $callback['throw']['code'] ?? 0);
            }

            return $callback['return'] ?? null;
        }, self::array($a, 'options'));

        return ['result' => self::encode($result), 'calls' => $calls];
    }

    /**
     * `listDatabaseNames()` with only the run's databases (`${ns}`,
     * `${ns}_*`) and the server's own (`admin`, `config`, `local`): other
     * runs' databases come and go.
     */
    private static function databases(\stdClass $reply, Session $s): mixed
    {
        $reply->databases = array_values(array_filter($reply->databases, fn ($d) => \in_array($d->name, ['admin', 'config', 'local'], true) || $d->name === $s->ns || str_starts_with($d->name, $s->ns . '_')));

        return self::encode($reply);
    }

    private static function marker(Client $client): array
    {
        return ['$client' => true];
    }

    /**
     * An argument as PHP values: `{"$object": {...}}` is a stdClass,
     * `{"$bson": <extended JSON>}` the BSON type it describes.
     */
    public static function decode(mixed $value): mixed
    {
        if (\is_array($value)) {
            if (\count($value) === 1 && \array_key_exists('$object', $value)) {
                $inner = $value['$object'];

                return (object) ($inner instanceof \stdClass ? [] : array_map(self::decode(...), (array) $inner));
            }
            if (\count($value) === 1 && \array_key_exists('$bson', $value)) {
                /** @var \stdClass $decoded */
                $decoded = Document::fromJSON(json_encode(['v' => $value['$bson']], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION))->toPHP();

                return $decoded->v;
            }

            return array_map(self::decode(...), $value);
        }

        return $value;
    }

    /**
     * A result as JSON: a non-empty stdClass is `{"$object": {...}}` (an
     * empty one `{}`), a BSON type `{"$bson": <canonical extended JSON>}`.
     */
    public static function encode(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $properties = get_object_vars($value);

            return $properties === [] ? $value : ['$object' => array_map(self::encode(...), $properties)];
        }
        if ($value instanceof Type) {
            return ['$bson' => json_decode(Document::fromPHP(['v' => $value])->toCanonicalExtendedJSON(), true, 512, JSON_THROW_ON_ERROR)['v']];
        }
        if (\is_array($value)) {
            return array_map(self::encode(...), $value);
        }
        if (\is_object($value)) {
            throw new Fault('cannot encode ' . get_debug_type($value));
        }

        return $value;
    }
}

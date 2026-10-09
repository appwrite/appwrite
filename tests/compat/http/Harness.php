<?php

namespace Tests\Compat\Http;

use Swoole\Http\Request as SwooleRequest;
use Tests\Compat\Fault;
use Utopia\DI\Container;
use Utopia\Http\Adapter;
use Utopia\Http\Adapter\Swoole\Request;
use Utopia\Http\Http;
use Utopia\Http\Response;
use Utopia\Http\Route;
use Utopia\Http\TrustedHeaders;
use Utopia\Servers\Hook;
use Utopia\Validator;
use Utopia\Validator\Validator as Rule;

/**
 * An in-process server adapter: one child context per dispatched request,
 * as the Swoole adapter creates per connection.
 */
final class TestServer extends Adapter
{
    private ?Container $context = null;

    public function __construct(private Container $resources)
    {
    }

    public function begin(): void
    {
        $this->context = new Container($this->resources);
    }

    public function finish(): void
    {
        $this->context = null;
    }

    public function onStart(callable $callback): void
    {
        \call_user_func($callback, $this);
    }

    public function onRequest(callable $callback): void
    {
    }

    public function start(): void
    {
    }

    public function resources(): Container
    {
        return $this->resources;
    }

    public function context(): Container
    {
        return $this->context ?? $this->resources;
    }
}

/**
 * Builds applications from their JSON description and runs the statements
 * of their actions. The same vocabulary is interpreted by
 * crates/tools/compat/src/libs/http.rs.
 */
final class Harness
{
    /** @var list<mixed> Values emitted by actions of the current dispatch. */
    public static array $out = [];

    public static ?Http $http = null;

    /** Methods a synthetic request may use. */
    public const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS', 'TRACE', 'CONNECT'];

    /**
     * A request parsed by Swoole from its parts.
     *
     * @param array<string, mixed> $spec
     */
    public static function request(array $spec): Request
    {
        $target = (string) ($spec['uri'] ?? '/');
        if (!\in_array($spec['method'] ?? 'GET', self::METHODS, true) || !ctype_graph($target) || !(str_starts_with($target, '/')|| str_starts_with($target, 'http://') || str_starts_with($target, 'https://'))) {
            throw new Fault('a request line needs a known method and a target');
        }
        $swoole = SwooleRequest::create();
        $body =\array_key_exists('body_json', $spec) ? (string) json_encode($spec['body_json']) : (string) ($spec['body'] ?? '');
        $head = '';
        $length = false;
        foreach ($spec['headers'] ?? [] as $header) {
            [$name, $value] = isset($header['n']) ? [$header['n'], $header['v']] : $header;
            if ($name === '') {
                throw new Fault('a header needs a name');
            }
            $head .= $name . ': ' . $value . "\r\n";
            $length = $length || strtolower((string) $name) === 'content-length';
        }
        if ($body !== '' && !$length) {
            $head .= 'Content-Length: ' . \strlen($body) . "\r\n";
        }
        $raw = ($spec['method'] ?? 'GET') . ' ' . ($spec['uri'] ?? '/') . ' ' . ($spec['protocol'] ?? 'HTTP/1.1') . "\r\n" . $head . "\r\n" . $body;
        if ($swoole->parse($raw) === false) {
            throw new Fault('swoole cannot parse the request');
        }
        if (isset($spec['remote_addr'])) {
            $swoole->server['remote_addr'] = $spec['remote_addr'];
        }
        $trusted = isset($spec['trusted'])
            ? new TrustedHeaders($spec['trusted']['ip'] ?? [], $spec['trusted']['proto'] ?? ['x-forwarded-proto'])
            : new TrustedHeaders();

        return new Request($swoole, $trusted);
    }

    /**
     * @param array<string, mixed> $spec
     */
    public static function validator(mixed $spec): Rule
    {
        if (!\is_array($spec) || \count($spec) !== 1) {
            throw new Fault('validator spec: ' . json_encode($spec));
        }
        $kind = (string) array_key_first($spec);
        $a = $spec[$kind];

        return match ($kind) {
            'text' => new Validator\Text((int) ($a[0] ?? 0), (int) ($a[1] ?? 1)),
            'integer' => new Validator\Integer((bool) ($a ?? false)),
            'boolean' => new Validator\Boolean((bool) ($a ?? false)),
            'whitelist' => new Validator\WhiteList($a[0], (bool) ($a[1] ?? false)),
            'nullable' => new Validator\Nullable(self::validator($a)),
            'any_of' => new Validator\AnyOf(array_map(self::validator(...), $a)),
            'any' => new AnyValue(),
            default => throw new Fault("unknown validator {$kind}"),
        };
    }

    /**
     * Configures a hook (or route) from its description.
     *
     * @param array<string, mixed> $spec
     */
    public static function hook(Hook $hook, array $spec): void
    {
        if (isset($spec['desc'])) {
            $hook->desc($spec['desc']);
        }
        if (isset($spec['groups'])) {
            $hook->groups($spec['groups']);
        }
        foreach ($spec['labels'] ?? [] as $key => $value) {
            $hook->label($key, $value);
        }
        if ($hook instanceof Route && isset($spec['hook'])) {
            $hook->hook((bool) $spec['hook']);
        }
        $names = [];
        $injections = [];
        foreach ($spec['params'] ?? [] as $p) {
            $default = $p['default'] ?? null;
            if (!empty($p['default_fn'])) {
                $value = $default;
                $default = function (...$deps) use ($value) {
                    foreach ($deps as $dep) {
                        self::$out[] = $dep;
                    }

                    return $value;
                };
            }
            $validator = $p['validator'] ?? ['any' => true];
            if (isset($validator['factory'])) {
                $inner = $validator['factory'];
                $validator = function (...$deps) use ($inner) {
                    foreach ($deps as $dep) {
                        self::$out[] = $dep;
                    }

                    return self::validator($inner);
                };
            } else {
                $validator = self::validator($validator);
            }
            $hook->param(
                $p['key'],
                $default,
                $validator,
                $p['description'] ?? '',
                (bool) ($p['optional'] ?? false),
                $p['injections'] ?? [],
                skipValidation: (bool) ($p['skip_validation'] ?? false),
                aliases: $p['aliases'] ?? [],
            );
            $names[] = 'param:' . $p['key'];
        }
        foreach ($spec['inject'] ?? [] as $name) {
            $hook->inject($name);
            $injections[] = 'inject:' . $name;
        }
        $names = [...$names, ...$injections];
        $statements = $spec['action'] ?? [];
        $hook->action(function (...$args) use ($names, $statements) {
            $env = new Env();
            foreach ($names as $i => $name) {
                [$kind, $key] = explode(':', $name, 2);
                $env->{$kind === 'param' ? 'params' : 'injected'}[$key] = $args[$i] ?? null;
            }
            self::run($statements, $env);
        });
    }

    /**
     * @param list<array<string, mixed>> $statements
     */
    public static function run(array $statements, Env $env): void
    {
        foreach ($statements as $statement) {
            $op = (string) array_key_first($statement);
            $a = $statement[$op];
            match ($op) {
                'emit' => self::$out[] = self::eval($a, $env),
                'send' => $env->response()->send((string) self::eval($a, $env)),
                'text' => $env->response()->text((string) self::eval($a, $env)),
                'html' => $env->response()->html((string) self::eval($a, $env)),
                'json' => $env->response()->json(self::eval($a, $env)),
                'jsonp' => $env->response()->jsonp($a[0], self::eval($a[1], $env)),
                'iframe' => $env->response()->iframe($a[0], self::eval($a[1], $env)),
                'redirect' => $env->response()->redirect($a[0], $a[1] ?? 301),
                'no_content' => $env->response()->noContent(),
                'chunk' => $env->response()->chunk((string) self::eval($a[0], $env), (bool) ($a[1] ?? false)),
                'status' => $env->response()->setStatusCode($a),
                'content_type' => $env->response()->setContentType($a[0], $a[1] ?? ''),
                'add_header' => $env->response()->addHeader($a[0], (string) self::eval($a[1], $env)),
                'set_header' => $env->response()->setHeader($a[0], (string) self::eval($a[1], $env)),
                'remove_header' => $env->response()->removeHeader($a),
                'add_cookie' => $env->response()->addCookie($a['name'], $a['value'] ?? null, $a['expire'] ?? null, $a['path'] ?? null, $a['domain'] ?? null, $a['secure'] ?? null, $a['httponly'] ?? null, $a['samesite'] ?? null),
                'remove_cookie' => $env->response()->removeCookie($a),
                'disable_payload' => $env->response()->disablePayload(),
                'enable_payload' => $env->response()->enablePayload(),
                'accept_encoding' => $env->response()->setAcceptEncoding($a),
                'compression_min_size' => $env->response()->setCompressionMinSize($a),
                'compression_supported' => $env->response()->setCompressionSupported($a),
                'throw' => throw self::exception($a),
                'set_resource' => (function () use ($a, $env) {
                    $value = self::eval($a[1], $env);
                    self::app()->resources()->set($a[0], fn () => $value);
                })(),
                'execute' => (function () use ($a) {
                    $response = new Capture();
                    self::app()->execute(self::request($a), $response);
                    self::$out[] = $response->wire['body'];
                })(),
                default => throw new Fault("unknown statement {$op}"),
            };
        }
    }

    public static function app(): Http
    {
        return self::$http ?? throw new Fault('no application');
    }

    /**
     * @param array<string, mixed> $a
     */
    public static function exception(array $a): \Throwable
    {
        $class = $a['class'] ?? 'Exception';
        if (!\in_array($class, ['Exception', 'DomainException', 'LogicException', 'RuntimeException', 'InvalidArgumentException', 'Utopia\\Http\\Exception'], true)) {
            throw new Fault("exception class {$class}");
        }

        return new $class($a['message'] ?? '', $a['code'] ?? 0);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function error(?\Throwable $e): ?array
    {
        if ($e === null) {
            return null;
        }

        return ['class' => $e::class, 'message' => $e->getMessage(), 'code' => $e->getCode(), 'previous' => self::error($e->getPrevious())];
    }

    public static function eval(mixed $e, Env $env): mixed
    {
        if (!\is_array($e) || array_is_list($e) || \count($e) !== 1) {
            return $e;
        }
        $kind = (string) array_key_first($e);
        $a = $e[$kind];

        return match ($kind) {
            'lit' => $a,
            'param' => $env->params[$a] ?? null,
            'inject' => match ($a) {
                'route' => $env->injected['route'] instanceof Route ? $env->injected['route']->getPath() : null,
                'error' => self::error($env->injected['error'] ?? null),
                'request', 'response' => \is_object($env->injected[$a] ?? null),
                default => $env->injected[$a] ?? null,
            },
            'request' => Getters::request($env->request(), \is_array($a) ? array_map(fn ($x) => self::eval($x, $env), $a) : $a),
            'response' => Getters::response($env->response(), $a),
            'route' => Getters::route($env->injected['route'] ?? null, $a),
            'concat' => implode('', array_map(fn ($x) => self::string(self::eval($x, $env)), $a)),
            'json' => json_encode(self::eval($a, $env)),
            'map' => (function () use ($a, $env) {
                $map = [];
                foreach ($a as [$key, $value]) {
                    $map[$key] = self::eval($value, $env);
                }

                return $map;
            })(),
            default => $e,
        };
    }

    /**
     * Writes a fixture file (creating its directory).
     */
    public static function write(string $path, string $content): void
    {
        if (!is_dir(\dirname($path))) {
            mkdir(\dirname($path), 0777, true);
        }
        file_put_contents($path, $content);
    }

    public static function string(mixed $v): string
    {
        if (\is_array($v) || \is_object($v)) {
            return json_encode($v) ?: '';
        }

        return (string) $v;
    }
}

/**
 * The arguments of one action call.
 */
final class Env
{
    /** @var array<string, mixed> */
    public array $params = [];

    /** @var array<string, mixed> */
    public array $injected = [];

    public ?Request $request = null;

    public ?Response $response = null;

    public function request(): \Utopia\Http\Request
    {
        $r = $this->request ?? $this->injected['request'] ?? null;

        return $r instanceof \Utopia\Http\Request ? $r : throw new Fault('no request injected');
    }

    public function response(): Response
    {
        $r = $this->response ?? $this->injected['response'] ?? null;

        return $r instanceof Response ? $r : throw new Fault('no response injected');
    }
}

/**
 * Accepts any value (the E2E object route's validator).
 */
final class AnyValue extends Rule
{
    public function getDescription(): string
    {
        return 'Value must be anything';
    }

    public function isArray(): bool
    {
        return false;
    }

    public function getType(): string
    {
        return self::TYPE_MIXED;
    }

    public function isValid(mixed $value): bool
    {
        return true;
    }
}

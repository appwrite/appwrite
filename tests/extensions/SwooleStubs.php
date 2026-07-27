<?php

namespace Swoole\Http;

if (!class_exists(\Swoole\Http\Response::class, false)) {
    class Response
    {
        public const STATUS_CODE_MOVED_PERMANENTLY = 301;
        public const STATUS_CODE_MOVED_TEMPORARILY = 302;
        public const STATUS_CODE_NOT_MODIFIED = 304;
        public const STATUS_CODE_BAD_REQUEST = 400;
        public const STATUS_CODE_UNAUTHORIZED = 401;
        public const STATUS_CODE_FORBIDDEN = 403;
        public const STATUS_CODE_NOT_FOUND = 404;
        public const STATUS_CODE_METHOD_NOT_ALLOWED = 405;
        public const STATUS_CODE_NOT_ACCEPTABLE = 406;
        public const STATUS_CODE_REQUEST_TIMEOUT = 408;
        public const STATUS_CODE_CONFLICT = 409;
        public const STATUS_CODE_LENGTH_REQUIRED = 411;
        public const STATUS_CODE_PRECONDITION_FAILED = 412;
        public const STATUS_CODE_REQUEST_ENTITY_TOO_LARGE = 413;
        public const STATUS_CODE_REQUEST_URI_TOO_LONG = 414;
        public const STATUS_CODE_UNSUPPORTED_MEDIA_TYPE = 415;
        public const STATUS_CODE_RANGE_NOT_SATISFIABLE = 416;
        public const STATUS_CODE_EXPECTATION_FAILED = 417;
        public const STATUS_CODE_TOO_MANY_REQUESTS = 429;
        public const STATUS_CODE_INTERNAL_SERVER_ERROR = 500;
        public const STATUS_CODE_BAD_GATEWAY = 502;
        public const STATUS_CODE_SERVICE_UNAVAILABLE = 503;
        public const STATUS_CODE_GATEWAY_TIMEOUT = 504;
        public const STATUS_CODE_ACCEPTED = 202;
        public const STATUS_CODE_CREATED = 201;
        public const STATUS_CODE_NO_CONTENT = 204;

        protected mixed $body;

        public function __construct()
        {
            $this->body = null;
        }

        public function setStatusCode(int $code, string $reason = ''): bool
        {
            return true;
        }

        public function header(string $key, mixed $value, bool $format = true): bool
        {
            return true;
        }

        public function cookie(
            string $name,
            mixed $value = '',
            int $expire = 0,
            string $path = '/',
            string $domain = '',
            bool $secure = false,
            bool $httponly = false,
            string $samesite = '',
            string $priority = ''
        ): bool {
            return true;
        }

        public function status(mixed $code): bool
        {
            return true;
        }

        public function end(?string $content = null): bool
        {
            $this->body = $content;
            return true;
        }

        public function write(string $content): bool
        {
            return true;
        }

        public function detach(): bool
        {
            return true;
        }

        public static function create(int $fd): self
        {
            return new self();
        }

        public function isWritable(): bool
        {
            return true;
        }
    }
}

namespace Swoole;

if (!class_exists(\Swoole\Timer::class, false)) {
    class Timer
    {
        public static function clearAll(): void
        {
        }
        public static function after(int $ms, callable $callback): int
        {
            return 1;
        }
        public static function tick(int $ms, callable $callback): int
        {
            return 1;
        }
        public static function clear(int $timerId): void
        {
        }
        public static function exists(int $timerId): bool
        {
            return false;
        }
        public static function info(int $timerId): array
        {
            return [];
        }
        public static function list(): array
        {
            return [];
        }
        public static function stats(): array
        {
            return [];
        }
    }
}

namespace Swoole;

if (!class_exists(\Swoole\Event::class, false)) {
    class Event
    {
        public static function wait(): void
        {
        }
        public static function add(mixed $fd, ?callable $readCallback = null, ?callable $writeCallback = null, int $events = 0): bool
        {
            return true;
        }
        public static function del(mixed $fd): bool
        {
            return true;
        }
        public static function set(mixed $fd, ?callable $readCallback = null, ?callable $writeCallback = null, int $events = 0): bool
        {
            return true;
        }
        public static function isset(mixed $fd, int $events = 0): bool
        {
            return false;
        }
        public static function dispatch(): void
        {
        }
        public static function defer(callable $callback): bool
        {
            return true;
        }
        public static function cycle(?callable $callback = null): bool
        {
            return true;
        }
        public static function exit(): void
        {
        }
    }
}

namespace Swoole\Coroutine;

if (!class_exists(\Swoole\Coroutine::class, false)) {
    class Coroutine
    {
        public static function create(callable $fn): int
        {
            return 1;
        }
        public static function defer(callable $callback): void
        {
        }
        public static function yield(): void
        {
        }
        public static function resume(int $cid): void
        {
        }
        public static function exists(int $cid): bool
        {
            return false;
        }
        public static function getCid(): int
        {
            return 1;
        }
        public static function getPcid(): int
        {
            return -1;
        }
        public static function getContext(int $cid = 0): mixed
        {
            return null;
        }
        public static function list(): array
        {
            return [];
        }
        public static function stats(): array
        {
            return [];
        }
        public static function isExist(int $cid): bool
        {
            return false;
        }
    }
}

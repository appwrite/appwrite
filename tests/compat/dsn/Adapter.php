<?php

namespace Tests\Compat\Dsn;

use Tests\Compat\Adapter as Base;
use Tests\Compat\Fault;
use Tests\Compat\Session;
use Utopia\DSN\DSN;

/**
 * Maps tests/compat/dsn/spec.json operations onto utopia-php/dsn. Glue only: no logic.
 */
final class Adapter implements Base
{
    public function operations(): array
    {
        return [
            'dsn.new' => fn (array $a, Session $s) => $s->handle(new DSN($a['dsn'])),
            'dsn.parse' => function (array $a, Session $s) {
                $dsn = new DSN($a['dsn']);
                $parts = self::parts($dsn);
                if (isset($a['params'])) {
                    $parts['params'] = array_map(static function (string $key) use ($dsn) {
                        try {
                            return $dsn->getParam($key);
                        } catch (\TypeError $error) {
                            return ['$error' => ['class' => $error::class, 'message' => $error->getMessage()]];
                        }
                    }, $a['params']);
                }

                return $parts;
            },
            'dsn.get' => fn (array $a, Session $s) => self::parts(self::dsn($a, $s)),
            'dsn.param' => fn (array $a, Session $s) => self::dsn($a, $s)->getParam($a['key'], ...(isset($a['default']) ? ['default' => $a['default']] : [])),
            // Port of DSNTest::testUncaughtRefusalPrintsNoCredentials: the
            // exception as PHP prints it (with its trace and arguments).
            'dsn.refusal_printed' => function (array $a, Session $s) {
                $ignoreArgs = \ini_set('zend.exception_ignore_args', '0');
                $maxLength = \ini_set('zend.exception_string_param_max_len', '1000000');
                try {
                    new DSN($a['dsn']);

                    return false;
                } catch (\InvalidArgumentException $exception) {
                    $printed = (string) $exception;
                } finally {
                    \ini_set('zend.exception_ignore_args', (string) $ignoreArgs);
                    \ini_set('zend.exception_string_param_max_len', (string) $maxLength);
                }

                return array_all($a['secrets'], static fn (string $secret) => !str_contains($printed, $secret));
            },
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private static function parts(DSN $dsn): array
    {
        return [
            'scheme' => $dsn->getScheme(),
            'user' => $dsn->getUser(),
            'password' => $dsn->getPassword(),
            'host' => $dsn->getHost(),
            'port' => $dsn->getPort(),
            'path' => $dsn->getPath(),
            'query' => $dsn->getQuery(),
        ];
    }

    private static function dsn(array $a, Session $s): DSN
    {
        $dsn = $s->get($a['dsn']);

        return $dsn instanceof DSN ? $dsn : throw new Fault('not a DSN');
    }
}

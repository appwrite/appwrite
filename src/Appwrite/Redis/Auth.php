<?php

namespace Appwrite\Redis;

final class Auth
{
    public static function credentials(?string $user, ?string $password): array|string|null
    {
        $user = $user === null || $user === '' ? null : $user;

        if ($user !== null) {
            return [$user, $password ?? ''];
        }

        if ($password === null || $password === '') {
            return null;
        }

        return $password;
    }

    public static function authenticate(\Redis $redis, ?string $user, ?string $password): void
    {
        $credentials = self::credentials($user, $password);

        if ($credentials === null) {
            return;
        }

        $redis->auth($credentials);
    }
}

<?php

namespace Appwrite\Auth;

use Ahc\Jwt\JWT;
use Ahc\Jwt\JWTException;
use Appwrite\Extend\Exception;
use Utopia\Config\Config;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\System\System;

class Key
{
    public function __construct(
        protected string $projectId,
        protected string $type,
        protected string $role,
        protected array $scopes,
        protected string $name,
        protected bool $usage = false,
    ) {
    }

    public function getProjectId(): string
    {
        return $this->projectId;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function getScopes(): array
    {
        return $this->scopes;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function isUsageEnabled(): bool
    {
        return $this->usage;
    }

    public static function decode(
        Document $project,
        string $key
    ): Key {
        if (\str_contains($key, '_')) {
            [$type, $secret] = \explode('_', $key, 2);
        } else {
            $type = API_KEY_STANDARD;
            $secret = $key;
        }

        $role = Auth::USER_ROLE_APPS;
        $roles = Config::getParam('roles', []);
        $scopes = $roles[Auth::USER_ROLE_APPS]['scopes'] ?? [];

        $guestKey = new Key(
            $project->getId(),
            $type,
            Auth::USER_ROLE_GUESTS,
            $roles[Auth::USER_ROLE_GUESTS]['scopes'] ?? [],
            'UNKNOWN'
        );

        switch ($type) {
            case API_KEY_DYNAMIC:
                $jwtObj = new JWT(
                    key: System::getEnv('_APP_OPENSSL_KEY_V1'),
                    algo: 'HS256',
                    maxAge: 86400,
                    leeway: 0
                );

                try {
                    $payload = $jwtObj->decode($secret);
                } catch (JWTException) {
                    throw new Exception(Exception::API_KEY_EXPIRED);
                }

                $name = $payload['name'] ?? 'Dynamic Key';
                $projectId = $payload['projectId'] ?? '';
                $usage = $payload['usage'] ?? true;
                $scopes = \array_merge($payload['scopes'] ?? [], $scopes);

                if ($projectId !== $project->getId()) {
                    return $guestKey;
                }

                return new Key(
                    $projectId,
                    $type,
                    $role,
                    $scopes,
                    $name,
                    $usage
                );
            case API_KEY_STANDARD:
                $key = $project->find(
                    key: 'secret',
                    find: $key,
                    subject: 'keys'
                );

                if (!$key) {
                    return $guestKey;
                }

                $expire = $key->getAttribute('expire');
                if (!empty($expire) && $expire < DateTime::formatTz(DateTime::now())) {
                    throw new Exception(Exception::PROJECT_KEY_EXPIRED);
                }

                $name = $key->getAttribute('name', 'UNKNOWN');
                $scopes = \array_merge($key->getAttribute('scopes', []), $scopes);

                return new Key(
                    $project->getId(),
                    $type,
                    $role,
                    $scopes,
                    $name
                );
            default:
                return $guestKey;
        }
    }
}

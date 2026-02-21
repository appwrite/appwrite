<?php

namespace Appwrite\Auth;

use Ahc\Jwt\JWT;
use Ahc\Jwt\JWTException;
use Appwrite\Extend\Exception;
use Appwrite\Utopia\Database\Documents\User;
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
        protected bool $expired = false,
        protected array $disabledMetrics = [],
        protected bool $hostnameOverride = false,
        protected bool $bannerDisabled = false,
        protected bool $projectCheckDisabled = false,
        protected bool $previewAuthDisabled = false,
        protected bool $deploymentStatusIgnored = false,
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

    public function isExpired(): bool
    {
        return $this->expired;
    }

    public function getDisabledMetrics(): array
    {
        return $this->disabledMetrics;
    }


    public function getHostnameOverride(): bool
    {
        return $this->hostnameOverride;
    }


    public function isBannerDisabled(): bool
    {
        return $this->bannerDisabled;
    }

    public function isPreviewAuthDisabled(): bool
    {
        return $this->previewAuthDisabled;
    }

    public function isDeploymentStatusIgnored(): bool
    {
        return $this->deploymentStatusIgnored;
    }

    public function isProjectCheckDisabled(): bool
    {
        return $this->projectCheckDisabled;
    }

    /**
     * Decode the given secret key into a Key object, containing the project ID, type, role, scopes, and name.
     * Can be a stored API key or a dynamic key (JWT).
     *
     * @param Document $project
     * @param string $key
     * @return Key
     * @throws Exception
     */
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

        $role = User::ROLE_APPS;
        $roles = Config::getParam('roles', []);
        $scopes = $roles[User::ROLE_APPS]['scopes'] ?? [];
        $expired = false;

        $guestKey = new Key(
            $project->getId(),
            $type,
            User::ROLE_GUESTS,
            $roles[User::ROLE_GUESTS]['scopes'] ?? [],
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
                    $expired = true;
                }

                $name = $payload['name'] ?? 'Dynamic Key';
                $projectId = $payload['projectId'] ?? '';
                $disabledMetrics = $payload['disabledMetrics'] ?? [];
                $hostnameOverride = $payload['hostnameOverride'] ?? false;
                $bannerDisabled = $payload['bannerDisabled'] ?? false;
                $projectCheckDisabled = $payload['projectCheckDisabled'] ?? false;
                $previewAuthDisabled = $payload['previewAuthDisabled'] ?? false;
                $deploymentStatusIgnored = $payload['deploymentStatusIgnored'] ?? false;
                $scopes = \array_merge($payload['scopes'] ?? [], $scopes);

                if (!$projectCheckDisabled && $projectId !== $project->getId()) {
                    return $guestKey;
                }

                return new Key(
                    $projectId,
                    $type,
                    $role,
                    $scopes,
                    $name,
                    $expired,
                    $disabledMetrics,
                    $hostnameOverride,
                    $bannerDisabled,
                    $projectCheckDisabled,
                    $previewAuthDisabled,
                    $deploymentStatusIgnored
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
                    $expired = true;
                }

                $name = $key->getAttribute('name', 'UNKNOWN');
                $scopes = \array_merge($key->getAttribute('scopes', []), $scopes);

                return new Key(
                    $project->getId(),
                    $type,
                    $role,
                    $scopes,
                    $name,
                    $expired
                );
            default:
                return $guestKey;
        }
    }
}

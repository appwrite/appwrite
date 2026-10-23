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
    public const string PURPOSE_CLAIM = 'purpose';

    public const string PURPOSE = 'key';

    public function __construct(
        protected string $projectId,
        protected string $teamId,
        protected string $userId,
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
        protected ?string $expire = null,
    ) {
    }

    public function getProjectId(): string
    {
        return $this->projectId;
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function getTeamId(): string
    {
        return $this->teamId;
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

    /**
     * When the key stops working, or null when it never does.
     */
    public function getExpire(): ?string
    {
        return $this->expire;
    }

    /**
     * Whether a request authorized with this key may create, or change a key into,
     * a key of the given type with these scopes and expiry.
     *
     * A key is a fixed grant, so nothing it makes reaches further than itself: no
     * scope it does not hold, and no expiry later than its own (none at all when
     * it expires). Organization keys provisioning project keys are the one
     * exception on scopes: they hold organization scopes, and
     * organization.projects.keys.write is the grant to give project keys project
     * scopes. Their lifetime still bounds what they make.
     *
     * @param array<string> $scopes
     */
    public function mayCreate(string $type, array $scopes, ?string $expire): bool
    {
        if ($this->expire !== null && ($expire === null || new \DateTimeImmutable($expire) > new \DateTimeImmutable($this->expire))) {
            return false;
        }

        if ($this->type === API_KEY_ORGANIZATION && \in_array($type, [API_KEY_STANDARD, API_KEY_EPHEMERAL], true)) {
            return true;
        }

        return \array_diff($scopes, $this->scopes) === [];
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
     * Can be a stored API key or an ephemeral key (JWT).
     * Invalid, expired-JWT, unknown-prefix, or project-mismatched secrets are refused.
     *
     * @throws Exception
     */
    public static function decode(
        Document $project,
        Document $team,
        Document $user,
        string $key
    ): Key {
        if (\str_contains($key, '_')) {
            [$type, $secret] = \explode('_', $key, 2);
        } else {
            $type = API_KEY_STANDARD;
            $secret = $key;
        }

        $role = User::ROLE_KEYS;
        $roles = Config::getParam('roles', []);
        $scopes = $roles[User::ROLE_KEYS]['scopes'] ?? [];
        $expired = false;

        switch ($type) {
            // Dynamic supported for backwards compatibility
            case API_KEY_EPHEMERAL:
            case 'dynamic':
                $jwtObj = new JWT(
                    key: System::getEnv('_APP_OPENSSL_KEY_V1'),
                    algo: 'HS256',
                    maxAge: 86400,
                    leeway: 0
                );

                try {
                    $payload = $jwtObj->decode($secret);
                } catch (JWTException) {
                    throw new Exception(Exception::USER_UNAUTHORIZED);
                }

                if (($payload[self::PURPOSE_CLAIM] ?? '') !== self::PURPOSE) {
                    throw new Exception(Exception::USER_UNAUTHORIZED);
                }

                $name = $payload['name'] ?? 'Ephemeral Key';
                $projectId = $payload['projectId'] ?? '';
                $disabledMetrics = $payload['disabledMetrics'] ?? [];
                $hostnameOverride = $payload['hostnameOverride'] ?? false;
                $bannerDisabled = $payload['bannerDisabled'] ?? false;
                $projectCheckDisabled = $payload['projectCheckDisabled'] ?? false;
                $previewAuthDisabled = $payload['previewAuthDisabled'] ?? false;
                $deploymentStatusIgnored = $payload['deploymentStatusIgnored'] ?? false;
                $scopes = \array_merge($payload['scopes'] ?? [], $scopes);
                $expire = isset($payload['exp']) ? DateTime::format((new \DateTime())->setTimestamp((int) $payload['exp'])) : null;

                if (!$projectCheckDisabled && $projectId !== $project->getId()) {
                    throw new Exception(Exception::USER_UNAUTHORIZED);
                }

                // Keys issued before the sequence claim carry none; they expire within a day.
                $projectSequence = $payload['projectSequence'] ?? null;
                if (!$projectCheckDisabled && $projectSequence !== null && (string) $projectSequence !== (string) $project->getSequence()) {
                    throw new Exception(Exception::USER_UNAUTHORIZED);
                }

                return new Key(
                    $projectId,
                    '',
                    '',
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
                    $deploymentStatusIgnored,
                    $expire,
                );
            case API_KEY_STANDARD:
                $key = $project->find(
                    key: 'secret',
                    find: $key,
                    subject: 'keys'
                );

                if (!$key) {
                    throw new Exception(Exception::USER_UNAUTHORIZED);
                }

                $expire = $key->getAttribute('expire');
                if (!empty($expire) && $expire < DateTime::formatTz(DateTime::now())) {
                    $expired = true;
                }

                $name = $key->getAttribute('name', 'UNKNOWN');
                $scopes = \array_merge($key->getAttribute('scopes', []), $scopes);

                return new Key(
                    $project->getId(),
                    '',
                    '',
                    $type,
                    $role,
                    $scopes,
                    $name,
                    $expired,
                    expire: $expire ?: null,
                );
            case API_KEY_ACCOUNT:
                $key = $user->find(
                    key: 'secret',
                    find: $key,
                    subject: 'keys'
                );

                // Invalid key
                if (!$key) {
                    throw new Exception(Exception::USER_UNAUTHORIZED);
                }

                $expire = $key->getAttribute('expire');
                $expired = false;
                if (!empty($expire) && $expire < DateTime::formatTz(DateTime::now())) {
                    $expired = true;
                }

                $name = $key->getAttribute('name', 'UNKNOWN');

                $role = User::ROLE_USERS;

                $scopes = $key->getAttribute('scopes', []);

                $key = new Key(
                    '',
                    '',
                    $user->getId(),
                    $type,
                    $role,
                    $scopes,
                    $name,
                    $expired,
                    expire: $expire ?: null,
                );

                return $key;
            case API_KEY_ORGANIZATION:
                $key = $team->find(
                    key: 'secret',
                    find: $key,
                    subject: 'keys'
                );

                // Invalid key
                if (!$key) {
                    throw new Exception(Exception::USER_UNAUTHORIZED);
                }

                $expire = $key->getAttribute('expire');
                $expired = false;
                if (!empty($expire) && $expire < DateTime::formatTz(DateTime::now())) {
                    $expired = true;
                }

                $name = $key->getAttribute('name', 'UNKNOWN');

                $role = User::ROLE_KEYS;

                $scopes = $key->getAttribute('scopes', []);

                $key = new Key(
                    '',
                    $team->getId(),
                    '',
                    $type,
                    $role,
                    $scopes,
                    $name,
                    $expired,
                    expire: $expire ?: null,
                );

                return $key;
            default:
                throw new Exception(Exception::USER_UNAUTHORIZED);
        }
    }
}

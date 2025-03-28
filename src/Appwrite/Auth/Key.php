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
    /**
     * Constructs a new Key instance with the specified configuration.
     *
     * @param string $projectId The ID of the project associated with the key.
     * @param string $type The type of the key (e.g., standard or dynamic).
     * @param string $role The role associated with the key.
     * @param array $scopes An array of scopes defining the key's permissions.
     * @param string $name The name assigned to the key.
     * @param bool $expired Indicates whether the key is expired. Defaults to false.
     * @param array $disabledMetrics A list of metrics disabled for this key.
     * @param bool $hostnameOverride Indicates if hostname override is enabled.
     * @param bool $bannerDisabled Indicates if the banner display is disabled.
     * @param bool $projectCheckDisabled Indicates whether project checks are disabled.
     * @param bool $previewAuthDisabled Indicates whether preview authentication is disabled.
     * @param bool $deploymentStatusIgnored Indicates if the deployment status should be ignored. Defaults to false.
     */
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

    /**
     * Returns whether preview authentication is disabled.
     *
     * @return bool True if preview authentication is disabled, false otherwise.
     */
    public function isPreviewAuthDisabled(): bool
    {
        return $this->previewAuthDisabled;
    }

    /**
     * Determines if the API key's deployment status is ignored.
     *
     * @return bool True if the deployment status is ignored; otherwise, false.
     */
    public function isDeploymentStatusIgnored(): bool
    {
        return $this->deploymentStatusIgnored;
    }

    /**
     * Returns whether project checks are disabled.
     *
     * This method indicates if project-based validations are bypassed.
     *
     * @return bool True if project checks are disabled; false otherwise.
     */
    public function isProjectCheckDisabled(): bool
    {
        return $this->projectCheckDisabled;
    }

    /**
     * Decodes a secret API key into a Key object.
     *
     * This method processes both dynamic (JWT) keys and standard API keys. For dynamic keys, it decodes the JWT payload
     * using a secure environment key and extracts properties such as the key name, project ID, scopes, disabled metrics,
     * hostname override, banner, project check, preview authentication, and deployment status. If decoding fails or if the
     * project ID in the token does not match the provided project (when project checks are enabled), a guest key is returned.
     *
     * For standard keys, the method retrieves the key document from the project and checks for expiration, returning a guest
     * key if the key is not found or has expired.
     *
     * @param Document $project The project document containing API key definitions.
     * @param string   $key     The secret API key, either in standard format or as a dynamic JWT.
     *
     * @return Key A new Key instance reflecting the decoded API key or a guest key if validation fails.
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

        $role = Auth::USER_ROLE_APPS;
        $roles = Config::getParam('roles', []);
        $scopes = $roles[Auth::USER_ROLE_APPS]['scopes'] ?? [];
        $expired = false;

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

<?php

namespace Appwrite\Auth\OAuth2;

use Appwrite\Auth\OAuth2;

// Reference Material
// https://confluence.atlassian.com/bitbucket/oauth-on-bitbucket-cloud-238027431.html#OAuthonBitbucketCloud-Createaconsumer

class Bitbucket extends OAuth2
{
    /**
     * @var array
     */
    protected array $user = [];

    /**
     * @var array
     */
    protected array $tokens = [];

    /**
     * @var array
     */
    protected array $scopes = [];

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'bitbucket';
    }

    /**
     * @return string
     */
    public function getLoginURL(): string
    {
        return 'https://bitbucket.org/site/oauth2/authorize?' . \http_build_query([
            'response_type' => 'code',
            'client_id' => $this->appID,
            'scope' => \implode(' ', $this->getScopes()),
            'state' => \json_encode($this->state),
        ]);
    }

    /**
     * @param string $code
     *
     * @return array
     */
    protected function getTokens(string $code): array
    {
        if (empty($this->tokens)) {
            // Bitbucket wants HTTP Basic Auth here, not client_id/secret in
            // the body -- the body form fails silently with a misleading
            // "invalid_grant" error instead of an auth error.
            $headers = [
                'Content-Type: application/x-www-form-urlencoded',
                'Authorization: Basic ' . \base64_encode($this->appID . ':' . $this->appSecret),
            ];
            $this->tokens = \json_decode($this->request(
                'POST',
                'https://bitbucket.org/site/oauth2/access_token',
                $headers,
                \http_build_query([
                    'code' => $code,
                    'grant_type' => 'authorization_code'
                ])
            ), true);
        }

        return $this->tokens;
    }

    /**
     * @param string $refreshToken
     *
     * @return array
     */
    public function refreshTokens(string $refreshToken): array
    {
        $headers = [
            'Content-Type: application/x-www-form-urlencoded',
            'Authorization: Basic ' . \base64_encode($this->appID . ':' . $this->appSecret),
        ];
        $this->tokens = \json_decode($this->request(
            'POST',
            'https://bitbucket.org/site/oauth2/access_token',
            $headers,
            \http_build_query([
                'grant_type' => 'refresh_token',
                'refresh_token' => $refreshToken
            ])
        ), true);

        if (empty($this->tokens['refresh_token'])) {
            $this->tokens['refresh_token'] = $refreshToken;
        }

        return $this->tokens;
    }

    /**
     * @param string $accessToken
     *
     * @return string
     */
    public function getUserID(string $accessToken): string
    {
        $user = $this->getUser($accessToken);

        return $user['uuid'] ?? '';
    }

    /**
     * @param string $accessToken
     *
     * @return string
     */
    public function getUserEmail(string $accessToken): string
    {
        $user = $this->getUser($accessToken);

        return $user['email'] ?? '';
    }

    /**
     * Check if the OAuth email is verified
     *
     * @param string $accessToken
     *
     * @return bool
     */
    public function isEmailVerified(string $accessToken): bool
    {
        $user = $this->getUser($accessToken);

        if ($user['is_confirmed'] ?? false) {
            return true;
        }

        return false;
    }

    /**
     * @param string $accessToken
     *
     * @return string
     */
    public function getUserName(string $accessToken): string
    {
        $user = $this->getUser($accessToken);

        return $user['display_name'] ?? '';
    }

    /**
     * Resolves the account's personal workspace slug, which repository API
     * calls need to address. `/user`'s username/nickname aren't valid
     * workspace identifiers for migrated accounts (silently returns zero
     * repositories rather than an error), and the account's UUID doesn't
     * double as its workspace's either, so this goes through
     * GET /user/workspaces instead.
     *
     * @param string $accessToken
     * @return string
     */
    public function getUserSlug(string $accessToken): string
    {
        $user = $this->getUser($accessToken);

        $headers = ['Authorization: Bearer ' . $accessToken];
        $workspaces = \json_decode($this->request('GET', 'https://api.bitbucket.org/2.0/user/workspaces', $headers), true);
        $values = $workspaces['values'] ?? [];
        // Some Bitbucket user-scoped list endpoints wrap the resource under
        // its own key (e.g. the older /permissions/workspaces did); accept
        // either shape rather than assume this one is flat.
        $first = $values[0] ?? [];
        $slug = $first['slug'] ?? ($first['workspace']['slug'] ?? '');

        if (!empty($slug)) {
            return $slug;
        }

        return $user['username'] ?? ($user['nickname'] ?? '');
    }

    /**
     * @link https://developer.atlassian.com/cloud/bitbucket/rest/api-group-repositories/#api-repositories-workspace-repo-slug-post
     *
     * Bitbucket has no implicit "current user's default namespace" the way
     * GitHub/GitLab do -- the workspace is always part of the URL. Defaults
     * to the token owner's own workspace (their user slug) when no
     * $namespaceId (a workspace slug) is given.
     *
     * @param string $accessToken
     * @param string $repositoryName
     * @param bool $private
     * @param string $namespaceId
     *
     * @return array
     */
    public function createRepository(string $accessToken, string $repositoryName, bool $private, string $namespaceId = ''): array
    {
        $workspace = !empty($namespaceId) ? $namespaceId : $this->getUserSlug($accessToken);

        $repository = $this->request(
            'POST',
            'https://api.bitbucket.org/2.0/repositories/' . \rawurlencode($workspace) . '/' . \rawurlencode($repositoryName),
            ['Authorization: Bearer ' . $accessToken, 'Content-Type: application/json'],
            \json_encode([
                'scm' => 'git',
                'is_private' => $private,
            ])
        );

        $repository = \json_decode($repository, true) ?? [];

        // Normalize to the GitHub/Gitea/GitLab field shape ProviderRepository expects.
        // The id is the "workspace/slug" every later lookup routes on, which is
        // what listing reports too -- a uuid resolves to no repository.
        if (isset($repository['full_name'])) {
            $repository['id'] = $repository['full_name'];
        }

        if (isset($repository['is_private'])) {
            $repository['private'] = $repository['is_private'];
        }

        if (isset($repository['updated_on'])) {
            $repository['pushed_at'] = $repository['updated_on'];
        }

        if (isset($repository['error']['message']) && !isset($repository['message'])) {
            $repository['message'] = $repository['error']['message'];
        }

        return $repository;
    }

    /**
     * @param string $accessToken
     *
     * @return array
     */
    protected function getUser(string $accessToken): array
    {
        if (empty($this->user)) {
            // Bitbucket's CHANGE-3052 (enforced since 2026-05-04) removed the
            // ?access_token= query param; the token must be sent as a Bearer
            // Authorization header instead.
            $headers = ['Authorization: Bearer ' . $accessToken];

            $user = $this->request('GET', 'https://api.bitbucket.org/2.0/user', $headers);
            $this->user = \json_decode($user, true);

            $emails = $this->request('GET', 'https://api.bitbucket.org/2.0/user/emails', $headers);
            $emails = \json_decode($emails, true);
            if (isset($emails['values'])) {
                foreach ($emails['values'] as $email) {
                    if ($email['is_confirmed']) {
                        $this->user['email'] = $email['email'];
                        $this->user['is_confirmed'] = $email['is_confirmed'];
                        break;
                    }
                }
            }
        }
        return $this->user;
    }
}

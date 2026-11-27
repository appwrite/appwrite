<?php

namespace Tests\E2E\Services\Distribution;

use Appwrite\ID;
use Tests\E2E\Client;
use Utopia\System\System;

trait GiteaRepositories
{
    private const string GITEA_USERNAME = 'appwrite';
    private const string GITEA_PASSWORD = 'password';
    private const string GITEA_BRANCH = 'main';

    /**
     * @var array<string, array<string, mixed>>
     */
    private static array $installations = [];

    /**
     * @var array<string, string>
     */
    private array $giteaCookies = [];

    /**
     * Installs through the Console session on either side, because only a Console user can authorize a provider.
     *
     * @return array<string, mixed>
     */
    protected function createGiteaInstallation(?string $projectId = null): array
    {
        $projectId ??= $this->getProject()['$id'];

        return self::$installations[$projectId] ??= $this->installGitea($projectId);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    protected function giteaApi(string $method, string $path, array $params = []): array
    {
        $gitea = new Client();
        $gitea->setEndpoint(System::getEnv('_APP_VCS_GITEA_ENDPOINT', 'http://gitea:3000'));

        return $gitea->call($method, $path, [
            'content-type' => 'application/json',
            'authorization' => 'Basic ' . \base64_encode(self::GITEA_USERNAME . ':' . self::GITEA_PASSWORD),
        ], $params);
    }

    /**
     * @param array<string, string> $files Contents keyed by path
     * @return array<string, mixed>
     */
    protected function createGiteaRepository(array $files = []): array
    {
        $repository = $this->giteaApi(Client::METHOD_POST, '/api/v1/user/repos', [
            'name' => 'distribution-' . ID::unique(),
            'auto_init' => true,
            'default_branch' => self::GITEA_BRANCH,
            'private' => false,
        ]);
        $this->assertSame(201, $repository['headers']['status-code'], \json_encode($repository['body']));

        if ($files !== []) {
            $commit = $this->giteaApi(Client::METHOD_POST, '/api/v1/repos/' . self::GITEA_USERNAME . '/' . $repository['body']['name'] . '/contents', [
                'branch' => self::GITEA_BRANCH,
                'message' => 'Add app sources',
                'files' => \array_map(fn (string $path, string $content): array => [
                    'operation' => 'create',
                    'path' => $path,
                    'content' => \base64_encode($content),
                ], \array_keys($files), \array_values($files)),
            ]);
            $this->assertSame(201, $commit['headers']['status-code'], \json_encode($commit['body']));
            $this->awaitGiteaPush($repository['body']['name'], $commit['body']['commit']['sha']);
        }

        return $repository['body'];
    }

    /**
     * Gitea processes a push asynchronously, so a hook created before it is processed still receives it.
     */
    protected function awaitGiteaPush(string $repository, string $commit, string $branch = self::GITEA_BRANCH): void
    {
        $this->assertEventually(function () use ($repository, $commit, $branch): void {
            $feeds = $this->giteaApi(Client::METHOD_GET, '/api/v1/repos/' . self::GITEA_USERNAME . '/' . $repository . '/activities/feeds');
            $this->assertSame(200, $feeds['headers']['status-code'], \json_encode($feeds['body']));

            $pushes = \array_filter($feeds['body'], fn (array $activity): bool => ($activity['op_type'] ?? '') === 'commit_repo'
                && \in_array($activity['ref_name'] ?? '', [$branch, 'refs/heads/' . $branch], true)
                && \str_contains((string) ($activity['content'] ?? ''), $commit));
            $this->assertNotEmpty($pushes, "Gitea has not processed the push of {$commit} to {$branch} yet.");
        }, 30_000, 500);
    }

    protected function createGiteaTag(string $repository, string $tag): void
    {
        $created = $this->giteaApi(Client::METHOD_POST, '/api/v1/repos/' . self::GITEA_USERNAME . '/' . $repository . '/tags', [
            'tag_name' => $tag,
            'target' => self::GITEA_BRANCH,
        ]);
        $this->assertSame(201, $created['headers']['status-code'], \json_encode($created['body']));
    }

    /**
     * Commits through the contents API, which fires Gitea's real push hook.
     *
     * @return string The new head of the branch
     */
    protected function pushGiteaCommit(string $repository, string $path, string $content, string $message = 'Update', string $branch = self::GITEA_BRANCH): string
    {
        $contents = '/api/v1/repos/' . self::GITEA_USERNAME . '/' . $repository . '/contents';
        $file = [
            'operation' => 'create',
            'path' => $path,
            'content' => \base64_encode($content),
        ];

        $existing = $this->giteaApi(Client::METHOD_GET, $contents . '/' . $path, ['ref' => $branch]);
        if ($existing['headers']['status-code'] === 200) {
            $file['operation'] = 'update';
            $file['sha'] = $existing['body']['sha'];
        }

        $commit = $this->giteaApi(Client::METHOD_POST, $contents, [
            'branch' => $branch,
            'message' => $message,
            'files' => [$file],
        ]);
        $this->assertSame(201, $commit['headers']['status-code'], \json_encode($commit['body']));

        return $commit['body']['commit']['sha'];
    }

    /**
     * @return string The commit the new branch points at
     */
    protected function createGiteaBranch(string $repository, string $branch): string
    {
        $created = $this->giteaApi(Client::METHOD_POST, '/api/v1/repos/' . self::GITEA_USERNAME . '/' . $repository . '/branches', [
            'new_branch_name' => $branch,
            'old_branch_name' => self::GITEA_BRANCH,
        ]);
        $this->assertSame(201, $created['headers']['status-code'], \json_encode($created['body']));

        return $created['body']['commit']['id'];
    }

    /**
     * Sends a push delivery signed with the instance-wide webhook secret, as any tenant could.
     *
     * @param array<string, mixed> $repository The Gitea repository
     * @param array<string, mixed> $overrides Replaced into the payload recursively
     * @return array<string, mixed>
     */
    protected function sendGiteaPush(array $repository, string $ref, string $after, array $overrides = []): array
    {
        $url = System::getEnv('_APP_VCS_GITEA_ENDPOINT', 'http://gitea:3000') . '/' . self::GITEA_USERNAME . '/' . $repository['name'];
        $payload = \array_replace_recursive([
            'created' => false,
            'deleted' => false,
            'ref' => $ref,
            'after' => $after,
            'repository' => [
                'id' => (int) $repository['id'],
                'name' => $repository['name'],
                'full_name' => self::GITEA_USERNAME . '/' . $repository['name'],
                'html_url' => $url,
                'owner' => ['login' => self::GITEA_USERNAME],
            ],
            'head_commit' => [
                'author' => ['name' => 'Distribution', 'email' => 'distribution-e2e@appwrite.io'],
                'message' => 'Synthetic push',
                'url' => $url . '/commit/' . $after,
            ],
            'commits' => [[
                'id' => $after,
                'added' => [],
                'removed' => [],
                'modified' => ['README.md'],
            ]],
            'sender' => ['html_url' => System::getEnv('_APP_VCS_GITEA_ENDPOINT', 'http://gitea:3000') . '/' . self::GITEA_USERNAME, 'avatar_url' => ''],
        ], $overrides);

        return $this->client->call(Client::METHOD_POST, '/vcs/gitea/events', [
            'content-type' => 'application/json',
            'x-gitea-event' => 'push',
            'x-gitea-signature' => \hash_hmac('sha256', \json_encode($payload, JSON_THROW_ON_ERROR), System::getEnv('_APP_VCS_GITEA_WEBHOOK_SECRET', '')),
        ], $payload);
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function listGiteaHooks(string $repository): array
    {
        $hooks = $this->giteaApi(Client::METHOD_GET, '/api/v1/repos/' . self::GITEA_USERNAME . '/' . $repository . '/hooks');
        $this->assertSame(200, $hooks['headers']['status-code'], \json_encode($hooks['body']));

        return \array_values(\array_filter($hooks['body'], fn (array $hook): bool => \str_contains($hook['config']['url'] ?? '', '/v1/vcs/gitea/events')));
    }

    /**
     * @return array<string, mixed>
     */
    private function installGitea(string $projectId): array
    {
        $this->giteaCookies = [];
        $settings = 'http://localhost/projects/' . $projectId . '/settings';
        $headers = [
            'x-appwrite-project' => $projectId,
            'origin' => 'http://localhost',
            'cookie' => 'a_session_console=' . $this->getRoot()['session'],
            'x-appwrite-mode' => 'admin',
        ];

        $authorize = $this->client->call(Client::METHOD_GET, '/vcs/gitea/authorize', $headers, [
            'success' => $settings,
            'failure' => $settings,
        ], true, false);
        $this->assertSame(301, $authorize['headers']['status-code']);

        $login = (string) ($authorize['headers']['location'] ?? '');
        $query = [];
        \parse_str((string) \parse_url($login, PHP_URL_QUERY), $query);
        $this->assertNotEmpty($query['state'] ?? '');

        $gitea = new Client();
        $gitea->setEndpoint(System::getEnv('_APP_VCS_GITEA_ENDPOINT', 'http://gitea:3000'));

        $this->callGitea($gitea, Client::METHOD_GET, '/user/login');
        $this->assertNotEmpty($this->giteaCookie('_csrf'), 'Gitea did not issue a CSRF cookie.');

        $session = $this->callGitea($gitea, Client::METHOD_POST, '/user/login', [
            '_csrf' => $this->giteaCookie('_csrf'),
            'user_name' => self::GITEA_USERNAME,
            'password' => self::GITEA_PASSWORD,
        ]);
        $this->assertContains($session['headers']['status-code'], [302, 303], 'Gitea login failed.');

        $callbackQuery = $this->consentGitea($gitea, $login, $query);

        // Parallel methods sign in as the one Gitea user, and Gitea stores only one first grant per user; the others find it on a second consent.
        if (empty($callbackQuery['code'])) {
            $callbackQuery = $this->consentGitea($gitea, $login, $query);
        }

        $this->assertNotEmpty($callbackQuery['code'] ?? '', 'Gitea did not grant a code: ' . \json_encode($callbackQuery));

        $callback = $this->client->call(Client::METHOD_GET, '/vcs/gitea/callback', $headers, [
            'code' => $callbackQuery['code'],
            'state' => $callbackQuery['state'] ?? '',
        ], true, false);
        $this->assertSame(301, $callback['headers']['status-code']);
        $this->assertSame($settings, $callback['headers']['location'] ?? '');

        $installations = $this->client->call(Client::METHOD_GET, '/vcs/installations', $headers);
        $this->assertSame(200, $installations['headers']['status-code']);

        foreach ($installations['body']['installations'] as $installation) {
            if ($installation['provider'] === 'gitea') {
                return $installation;
            }
        }

        $this->fail('Gitea installation not found in listInstallations.');
    }

    /**
     * @param array<string, mixed> $query The authorize request
     * @return array<string, mixed> The query Gitea redirects back with
     */
    private function consentGitea(Client $gitea, string $login, array $query): array
    {
        $consent = $this->callGitea($gitea, Client::METHOD_GET, \parse_url($login, PHP_URL_PATH) . '?' . \parse_url($login, PHP_URL_QUERY));

        if (\in_array($consent['headers']['status-code'], [302, 303], true)) {
            $redirect = (string) ($consent['headers']['location'] ?? '');
        } else {
            $this->assertSame(200, $consent['headers']['status-code']);

            $grant = $this->callGitea($gitea, Client::METHOD_POST, '/login/oauth/grant', [
                '_csrf' => $this->giteaCookie('_csrf'),
                'client_id' => $query['client_id'] ?? '',
                'state' => $query['state'],
                'scope' => $query['scope'] ?? '',
                'nonce' => '',
                'redirect_uri' => $query['redirect_uri'] ?? '',
            ]);
            $this->assertSame(303, $grant['headers']['status-code']);

            $redirect = (string) ($grant['headers']['location'] ?? '');
        }

        $callbackQuery = [];
        \parse_str((string) \parse_url($redirect, PHP_URL_QUERY), $callbackQuery);

        return $callbackQuery;
    }

    private function giteaCookie(string $name): string
    {
        return $this->giteaCookies[$name] ?? '';
    }

    /**
     * Client keeps no cookies and must not follow redirects, so the jar is carried by hand.
     *
     * @param array<string, string> $params
     * @return array<string, mixed>
     */
    private function callGitea(Client $gitea, string $method, string $path, array $params = []): array
    {
        $headers = [];

        if ($this->giteaCookies !== []) {
            $headers['cookie'] = \implode('; ', \array_map(
                fn (string $name): string => $name . '=' . $this->giteaCookies[$name],
                \array_keys($this->giteaCookies),
            ));
        }

        if ($method !== Client::METHOD_GET) {
            $headers['content-type'] = 'application/x-www-form-urlencoded';
        }

        $response = $gitea->call($method, $path, $headers, $params, false, false);

        $this->giteaCookies = \array_merge($this->giteaCookies, $response['cookies']);

        return $response;
    }
}

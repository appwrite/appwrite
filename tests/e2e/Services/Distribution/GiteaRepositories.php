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
        }

        return $repository['body'];
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
        $headers = \array_merge(['x-appwrite-project' => $projectId], $this->getHeaders());

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
        $this->assertNotEmpty($callbackQuery['code'] ?? '', 'Gitea did not grant a code: ' . $redirect);

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

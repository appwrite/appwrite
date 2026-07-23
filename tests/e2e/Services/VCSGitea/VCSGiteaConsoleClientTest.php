<?php

declare(strict_types=1);

namespace Tests\E2E\Services\VCSGitea;

use Appwrite\Tests\Async\Exceptions\Critical;
use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideConsole;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Role;
use Utopia\System\System;

final class VCSGiteaConsoleClientTest extends Scope
{
    use VCSGiteaBase;
    use ProjectCustom;
    use SideConsole;

    // Admin user created by gitea-bootstrap (docker-compose.override.yml, `gitea` profile)
    private const GITEA_USERNAME = 'appwrite';
    private const GITEA_PASSWORD = 'password';

    private array $giteaCookies = [];

    public function testCreateInstallation(): void
    {
        $installation = $this->createInstallationHelper();

        $this->assertNotEmpty($installation['$id']);
        $this->assertEquals('gitea', $installation['provider']);
        $this->assertEquals(self::GITEA_USERNAME, $installation['organization']);
        $this->assertNotEmpty($installation['providerInstallationId']);
    }

    public function testCreateDeploymentFromGitPush(): void
    {
        $projectId = $this->getProject()['$id'];
        $installationId = $this->createInstallationHelper()['$id'];

        $repository = $this->giteaApiHelper(Client::METHOD_POST, '/api/v1/user/repos', [
            'name' => 'function-' . \uniqid(),
            'auto_init' => true,
            'default_branch' => 'main',
            'private' => false,
        ]);
        $this->assertEquals(201, $repository['headers']['status-code'], \json_encode($repository['body']));
        $repositoryName = $repository['body']['name'];

        $workdir = \sys_get_temp_dir() . '/vcs-gitea-' . \uniqid();
        $endpoint = System::getEnv('_APP_VCS_GITEA_ENDPOINT', 'http://gitea:3000');
        $remote = \str_replace('://', '://' . self::GITEA_USERNAME . ':' . self::GITEA_PASSWORD . '@', $endpoint)
            . '/' . self::GITEA_USERNAME . '/' . $repositoryName . '.git';

        $this->gitHelper("git clone {$remote} {$workdir}", \sys_get_temp_dir());
        $this->writeFunctionHelper($workdir, 'gitea-v1');
        $this->gitHelper('git add index.js && git commit -m "Add function"', $workdir);
        $this->gitHelper('git push origin main', $workdir);

        $function = $this->client->call(Client::METHOD_POST, '/functions', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'functionId' => ID::unique(),
            'name' => 'Gitea VCS',
            'execute' => [Role::any()->toString()],
            'runtime' => 'node-22',
            'entrypoint' => 'index.js',
            'timeout' => 15,
            'installationId' => $installationId,
            'providerRepositoryId' => (string) $repository['body']['id'],
            'providerBranch' => 'main',
        ]);
        $this->assertEquals(201, $function['headers']['status-code'], \json_encode($function['body']));
        $functionId = $function['body']['$id'];

        // Connecting the repository must register a push webhook in Gitea
        $hooks = $this->giteaApiHelper(Client::METHOD_GET, '/api/v1/repos/' . self::GITEA_USERNAME . '/' . $repositoryName . '/hooks');
        $this->assertEquals(200, $hooks['headers']['status-code']);
        $matching = \array_filter($hooks['body'], fn ($hook) => \str_contains($hook['config']['url'] ?? '', '/v1/vcs/gitea/events'));
        $this->assertNotEmpty($matching, 'No Appwrite webhook registered in Gitea: ' . \json_encode($hooks['body']));

        $deployment = $this->client->call(Client::METHOD_POST, '/functions/' . $functionId . '/deployments/vcs', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'type' => 'branch',
            'reference' => 'main',
            'activate' => true,
        ]);
        $this->assertEquals(202, $deployment['headers']['status-code'], \json_encode($deployment['body']));

        $this->waitForDeploymentReadyHelper($functionId, $deployment['body']['$id']);
        $this->assertEventually(fn () => $this->assertExecutionOutputHelper($functionId, 'gitea-v1'), 30000, 1000);

        // Connecting the repository can auto-create a deployment alongside the
        // explicit one, so collect every pre-push deployment; only a deployment
        // absent from this list can be the webhook's.
        $knownIds = $this->listDeploymentIdsHelper($functionId);

        $this->writeFunctionHelper($workdir, 'gitea-v2');
        $this->gitHelper('git add index.js && git commit -m "Update function"', $workdir);
        $this->gitHelper('git push origin main', $workdir);

        $webhookDeploymentId = $this->waitForNewDeploymentReadyHelper($functionId, $knownIds);
        $this->assertNotContains($webhookDeploymentId, $knownIds);
        $this->assertEventually(fn () => $this->assertExecutionOutputHelper($functionId, 'gitea-v2'), 30000, 1000);
    }

    /**
     * Walk the full OAuth2 dance against the local Gitea and return the
     * resulting installation, asserting every hop on the way.
     */
    private function createInstallationHelper(): array
    {
        $projectId = $this->getProject()['$id'];
        $consoleUrl = 'http://localhost/console/project-default-' . $projectId . '/settings/git-installations';

        $authorize = $this->client->call(Client::METHOD_GET, '/vcs/gitea/authorize', \array_merge([
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'success' => $consoleUrl,
            'failure' => $consoleUrl,
        ], true, false);

        $this->assertEquals(301, $authorize['headers']['status-code']);

        $loginUrl = $authorize['headers']['location'] ?? '';
        $this->assertStringContainsString('/login/oauth/authorize', (string) $loginUrl);

        $query = [];
        \parse_str(\parse_url($loginUrl, PHP_URL_QUERY) ?: '', $query);
        $this->assertNotEmpty($query['client_id'] ?? '');
        $this->assertNotEmpty($query['redirect_uri'] ?? '');
        $this->assertNotEmpty($query['state'] ?? '');
        $this->assertSame('code', $query['response_type'] ?? '');

        // Location targets the browser-facing endpoint, unreachable in-container; reuse only its path and query
        $gitea = new Client();
        $gitea->setEndpoint(System::getEnv('_APP_VCS_GITEA_ENDPOINT', 'http://gitea:3000'));

        $this->giteaCallHelper($gitea, Client::METHOD_GET, '/user/login');
        $this->assertNotEmpty($this->giteaCookies['_csrf'] ?? '', 'Gitea did not issue a CSRF cookie.');

        $login = $this->giteaCallHelper($gitea, Client::METHOD_POST, '/user/login', [
            '_csrf' => $this->giteaCookies['_csrf'],
            'user_name' => self::GITEA_USERNAME,
            'password' => self::GITEA_PASSWORD,
        ]);
        $this->assertContains($login['headers']['status-code'], [302, 303], 'Gitea login failed.');
        $this->assertNotEmpty($this->giteaCookies['i_like_gitea'] ?? '', 'Gitea did not issue a session cookie.');

        // Gitea stores client_id, state and redirect_uri in the session here; the grant must match them
        $authorizePath = \parse_url($loginUrl, PHP_URL_PATH) . '?' . \parse_url($loginUrl, PHP_URL_QUERY);
        $consent = $this->giteaCallHelper($gitea, Client::METHOD_GET, $authorizePath);

        if (\in_array($consent['headers']['status-code'], [302, 303])) {
            // Already granted: Gitea redirects straight back with a code
            $redirect = $consent['headers']['location'] ?? '';
        } else {
            $this->assertEquals(200, $consent['headers']['status-code']);

            $grant = $this->giteaCallHelper($gitea, Client::METHOD_POST, '/login/oauth/grant', [
                '_csrf' => $this->giteaCookies['_csrf'],
                'client_id' => $query['client_id'],
                'state' => $query['state'],
                'scope' => $query['scope'] ?? '',
                'nonce' => '',
                'redirect_uri' => $query['redirect_uri'],
            ]);
            $this->assertEquals(303, $grant['headers']['status-code']);

            $redirect = $grant['headers']['location'] ?? '';
        }

        $this->assertStringContainsString('/v1/vcs/gitea/callback', (string) $redirect);

        $callbackQuery = [];
        \parse_str(\parse_url($redirect, PHP_URL_QUERY) ?: '', $callbackQuery);
        $this->assertNotEmpty($callbackQuery['code'] ?? '');
        $this->assertNotEmpty($callbackQuery['state'] ?? '');

        $callback = $this->client->call(Client::METHOD_GET, '/vcs/gitea/callback', \array_merge([
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()), [
            'code' => $callbackQuery['code'],
            'state' => $callbackQuery['state'],
        ], true, false);

        $this->assertEquals(301, $callback['headers']['status-code']);
        $this->assertEquals($consoleUrl, $callback['headers']['location'] ?? '');

        $installations = $this->client->call(Client::METHOD_GET, '/vcs/installations', \array_merge([
            'x-appwrite-project' => $projectId,
        ], $this->getHeaders()));

        $this->assertEquals(200, $installations['headers']['status-code']);
        $this->assertGreaterThanOrEqual(1, $installations['body']['total']);

        foreach ($installations['body']['installations'] as $installation) {
            if ($installation['provider'] === 'gitea') {
                return $installation;
            }
        }

        $this->fail('Gitea installation not found in listInstallations.');
    }

    // Client does not persist cookies between calls, so carry them manually and never follow redirects
    private function giteaCallHelper(Client $gitea, string $method, string $path, array $params = []): array
    {
        $headers = [];

        if (!empty($this->giteaCookies)) {
            $headers['cookie'] = \implode('; ', \array_map(
                fn (string $name) => $name . '=' . $this->giteaCookies[$name],
                \array_keys($this->giteaCookies)
            ));
        }

        if ($method !== Client::METHOD_GET) {
            $headers['content-type'] = 'application/x-www-form-urlencoded';
        }

        $response = $gitea->call($method, $path, $headers, $params, false, false);

        $this->giteaCookies = \array_merge($this->giteaCookies, $response['cookies']);

        return $response;
    }

    private function giteaApiHelper(string $method, string $path, array $params = []): array
    {
        $gitea = new Client();
        $gitea->setEndpoint(System::getEnv('_APP_VCS_GITEA_ENDPOINT', 'http://gitea:3000'));

        return $gitea->call($method, $path, [
            'content-type' => 'application/json',
            'authorization' => 'Basic ' . \base64_encode(self::GITEA_USERNAME . ':' . self::GITEA_PASSWORD),
        ], $params);
    }

    private function gitHelper(string $command, string $directory): void
    {
        $identity = '-c user.email=gitea@appwrite.io -c user.name=' . self::GITEA_USERNAME;
        $command = \str_replace('git commit', "git {$identity} commit", $command);

        \exec('cd ' . \escapeshellarg($directory) . ' && ' . $command . ' 2>&1', $output, $exitCode);

        $this->assertSame(0, $exitCode, "Git command failed: {$command}\n" . \implode("\n", $output));
    }

    private function writeFunctionHelper(string $workdir, string $output): void
    {
        \file_put_contents($workdir . '/index.js', "module.exports = async (context) => context.res.send('{$output}');\n");
    }

    private function waitForDeploymentReadyHelper(string $functionId, string $deploymentId): void
    {
        $this->assertEventually(function () use ($functionId, $deploymentId) {
            $response = $this->client->call(Client::METHOD_GET, '/functions/' . $functionId . '/deployments/' . $deploymentId, \array_merge([
                'x-appwrite-project' => $this->getProject()['$id'],
            ], $this->getHeaders()));

            $this->assertEquals(200, $response['headers']['status-code']);

            if (($response['body']['status'] ?? '') === 'failed') {
                throw new Critical('Deployment build failed: ' . ($response['body']['buildLogs'] ?? 'no logs'));
            }

            $this->assertEquals('ready', $response['body']['status'] ?? '', \json_encode($response['body']));
        }, 300000, 2000);
    }

    private function listDeploymentIdsHelper(string $functionId): array
    {
        return \array_column($this->listDeploymentsHelper($functionId), '$id');
    }

    private function waitForNewDeploymentReadyHelper(string $functionId, array $knownIds): string
    {
        $deploymentId = '';

        $this->assertEventually(function () use ($functionId, $knownIds, &$deploymentId) {
            foreach ($this->listDeploymentsHelper($functionId) as $candidate) {
                if (\in_array($candidate['$id'], $knownIds, true)) {
                    continue;
                }

                if (($candidate['status'] ?? '') === 'failed') {
                    throw new Critical('Webhook deployment build failed: ' . ($candidate['buildLogs'] ?? 'no logs'));
                }

                if (($candidate['status'] ?? '') === 'ready') {
                    $deploymentId = $candidate['$id'];
                    return;
                }
            }

            $this->fail('Webhook deployment is not ready yet.');
        }, 300000, 2000);

        return $deploymentId;
    }

    private function listDeploymentsHelper(string $functionId): array
    {
        $response = $this->client->call(Client::METHOD_GET, '/functions/' . $functionId . '/deployments', \array_merge([
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()));

        $this->assertEquals(200, $response['headers']['status-code']);

        return $response['body']['deployments'] ?? [];
    }

    private function assertExecutionOutputHelper(string $functionId, string $output): void
    {
        $execution = $this->client->call(Client::METHOD_POST, '/functions/' . $functionId . '/executions', \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders()), [
            'async' => false,
        ]);

        $this->assertEquals(201, $execution['headers']['status-code'], \json_encode($execution['body']));
        $this->assertEquals('completed', $execution['body']['status'] ?? '', \json_encode($execution['body']));
        $this->assertEquals($output, $execution['body']['responseBody'] ?? '');
    }
}

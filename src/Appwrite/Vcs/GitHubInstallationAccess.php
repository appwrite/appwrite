<?php

declare(strict_types=1);

namespace Appwrite\Vcs;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Utopia\Psr7\Header;
use Utopia\Psr7\Method;
use Utopia\Psr7\Request\Factory as RequestFactory;

/**
 * Decides whether a GitHub user may attach a GitHub App installation to an Appwrite project.
 *
 * Attaching an installation hands the project the App's installation token, which reaches every
 * repository the installation covers. GitHub lists an installation under /user/installations as soon
 * as the user can read one of its repositories, so that listing alone is not enough. The user has to
 * administer what the installation reaches: own the personal account it is installed on, or hold
 * admin on every repository it covers, as organization owners do.
 */
final class GitHubInstallationAccess
{
    private const string API = 'https://api.github.com';

    private const int PER_PAGE = 100;

    private readonly RequestFactory $requests;

    /**
     * @param string $accessToken The authorizing user's GitHub App user access token
     */
    public function __construct(
        private readonly ClientInterface $client,
        private readonly string $accessToken,
    ) {
        $this->requests = new RequestFactory();
    }

    /**
     * @param int $repositoryCount How many repositories the installation covers, as its own token lists them
     */
    public function administers(string $installationId, int $repositoryCount): bool
    {
        $installation = $this->findInstallation($installationId);

        if ($installation === null) {
            return false;
        }

        $account = \is_array($installation['account'] ?? null) ? $installation['account'] : [];
        $accountId = (string) ($account['id'] ?? '');

        if ($accountId === '') {
            return false;
        }

        if (($account['type'] ?? '') === 'User') {
            return $accountId === $this->userId();
        }

        return $this->administersRepositories($installationId, $repositoryCount);
    }

    /**
     * The installation as /user/installations lists it for this user, or null when it is not listed.
     *
     * @return array<string, mixed>|null
     */
    private function findInstallation(string $installationId): ?array
    {
        $page = 1;

        do {
            $body = $this->get('/user/installations', ['per_page' => self::PER_PAGE, 'page' => $page]);

            if ($body === null) {
                return null;
            }

            $installations = \is_array($body['installations'] ?? null) ? $body['installations'] : [];

            foreach ($installations as $installation) {
                if (\is_array($installation) && (string) ($installation['id'] ?? '') === $installationId) {
                    return $installation;
                }
            }

            $page++;
        } while (\count($installations) === self::PER_PAGE);

        return null;
    }

    /**
     * Whether the user holds admin on every repository the installation covers.
     */
    private function administersRepositories(string $installationId, int $repositoryCount): bool
    {
        if ($repositoryCount < 1) {
            return false;
        }

        $seen = 0;
        $page = 1;

        do {
            $body = $this->get('/user/installations/' . \rawurlencode($installationId) . '/repositories', [
                'per_page' => self::PER_PAGE,
                'page' => $page,
            ]);

            if ($body === null || (int) ($body['total_count'] ?? -1) !== $repositoryCount) {
                return false;
            }

            $repositories = \is_array($body['repositories'] ?? null) ? $body['repositories'] : [];

            foreach ($repositories as $repository) {
                if (!\is_array($repository) || ($repository['permissions']['admin'] ?? false) !== true) {
                    return false;
                }
                $seen++;
            }

            $page++;
        } while (\count($repositories) === self::PER_PAGE && $seen < $repositoryCount);

        return $seen === $repositoryCount;
    }

    private function userId(): string
    {
        $body = $this->get('/user', []);

        return $body === null ? '' : (string) ($body['id'] ?? '');
    }

    /**
     * @param array<string, int|string> $query
     * @return array<string, mixed>|null The decoded body, or null when the request did not succeed
     */
    private function get(string $path, array $query): ?array
    {
        try {
            $response = $this->client->sendRequest($this->requests->query(Method::GET, self::API . $path, $query, [
                Header::ACCEPT => 'application/vnd.github+json',
                Header::AUTHORIZATION => 'Bearer ' . $this->accessToken,
                Header::USER_AGENT => 'Appwrite',
            ]));
        } catch (ClientExceptionInterface) {
            return null;
        }

        if ($response->getStatusCode() !== 200) {
            return null;
        }

        $body = \json_decode((string) $response->getBody(), true);

        return \is_array($body) ? $body : null;
    }
}

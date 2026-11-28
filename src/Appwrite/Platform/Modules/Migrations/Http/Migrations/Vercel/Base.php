<?php

namespace Appwrite\Platform\Modules\Migrations\Http\Migrations\Vercel;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Migrations\Http\Migrations\Action;
use Appwrite\Utopia\Request;
use Psr\Http\Client\ClientExceptionInterface;
use Utopia\Client\Client;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Psr7\ContentType;
use Utopia\Psr7\Header;
use Utopia\Psr7\Request\Factory as RequestFactory;
use Utopia\System\System;

/**
 * The console user's installation of Appwrite's Vercel integration, kept as an identity on the
 * platform so it is shared by every project the user imports sites into.
 */
abstract class Base extends Action
{
    /**
     * Identity provider of the installation, apart from Vercel sign in so the two never share a record
     */
    protected const string PROVIDER = 'vercel-migrations';

    /**
     * Vercel framework presets and the Appwrite framework each one builds as
     */
    protected const array FRAMEWORKS = [
        'nextjs' => 'nextjs',
        'nuxtjs' => 'nuxt',
        'sveltekit' => 'sveltekit',
        'sveltekit-1' => 'sveltekit',
        'astro' => 'astro',
        'remix' => 'remix',
        'vite' => 'vite',
        'create-react-app' => 'react',
        'angular' => 'angular',
        'vue' => 'vue',
        'tanstack-start' => 'tanstack-start',
    ];

    /**
     * @return array{0: string, 1: string} client ID and secret of the integration
     */
    protected function getClient(): array
    {
        $clientId = System::getEnv('_APP_MIGRATIONS_VERCEL_CLIENT_ID', '');
        $clientSecret = System::getEnv('_APP_MIGRATIONS_VERCEL_CLIENT_SECRET', '');

        if ($clientId === '' || $clientSecret === '' || System::getEnv('_APP_MIGRATIONS_VERCEL_INTEGRATION', '') === '') {
            throw new Exception(Exception::GENERAL_NOT_IMPLEMENTED, 'The Vercel integration is not configured on this server.');
        }

        return [$clientId, $clientSecret];
    }

    protected function getRedirectURL(Request $request): string
    {
        $protocol = System::getEnv('_APP_OPTIONS_FORCE_HTTPS') === 'disabled' ? 'http' : 'https';
        $port = $request->getPort();
        $callback = $protocol . '://' . $request->getHostname();
        // An empty port means the host named none, so the scheme's default applies
        if ($port !== '' && $port !== ($protocol === 'https' ? '443' : '80')) {
            $callback .= ':' . $port;
        }

        return $callback . '/v1/migrations/vercel/oauth2/callback';
    }

    protected function getIdentity(Database $dbForPlatform, Document $user): Document
    {
        $identity = $dbForPlatform->findOne('identities', [
            Query::equal('provider', [self::PROVIDER]),
            Query::equal('userInternalId', [$user->getSequence()]),
        ]);

        if ($identity->isEmpty()) {
            throw new Exception(Exception::USER_IDENTITY_NOT_FOUND, 'Connect your Vercel account first.');
        }

        return $identity;
    }

    /**
     * A token for the installation, requested again once the cached one is near expiry since
     * Vercel integration tokens last an hour and are never refreshed
     */
    protected function getAccessToken(Client $clientForOAuth2, Database $dbForPlatform, Document $identity): string
    {
        $expiry = $identity->getAttribute('providerAccessTokenExpiry');

        if (!empty($expiry) && new \DateTime($expiry) > new \DateTime('+5 minutes')) {
            return $identity->getAttribute('providerAccessToken', '');
        }

        [$clientId, $clientSecret] = $this->getClient();

        try {
            $tokens = $this->request($clientForOAuth2, 'POST', '/v2/oauth/access_token', [
                'Authorization' => 'Basic ' . \base64_encode($clientId . ':' . $clientSecret),
            ], [
                'grant_type' => 'client_credentials',
                'installation_id' => $identity->getAttribute('providerUid', ''),
            ]);
        } catch (\Throwable) {
            throw new Exception(Exception::USER_IDENTITY_NOT_FOUND, 'Appwrite can no longer reach your Vercel account. Connect it again.');
        }

        $accessToken = $tokens['access_token'] ?? '';

        if ($accessToken === '') {
            throw new Exception(Exception::USER_IDENTITY_NOT_FOUND, 'Appwrite can no longer reach your Vercel account. Connect it again.');
        }

        $dbForPlatform->updateDocument('identities', $identity->getId(), new Document([
            'providerAccessToken' => $accessToken,
            'providerAccessTokenExpiry' => DateTime::addSeconds(new \DateTime(), (int) ($tokens['expires_in'] ?? 3600)),
        ]));

        return $accessToken;
    }

    /**
     * Call the Vercel REST API; a form body is sent when one is given
     *
     * @param array<string, string> $headers
     * @param array<string, string> $form
     * @return array<mixed>
     */
    protected function request(Client $clientForOAuth2, string $method, string $path, array $headers = [], array $form = []): array
    {
        $factory = new RequestFactory();
        $request = empty($form)
            ? $factory->createRequest($method, 'https://api.vercel.com' . $path)
            : $factory->body($method, 'https://api.vercel.com' . $path, \http_build_query($form), ContentType::FORM_URLENCODED);

        foreach ([Header::USER_AGENT => 'Appwrite Migrations', ...$headers] as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        try {
            $response = $clientForOAuth2->sendRequest($request);
        } catch (ClientExceptionInterface $exception) {
            throw new \RuntimeException($exception->getMessage());
        }

        if ($response->getStatusCode() >= 400) {
            throw new \RuntimeException((string) $response->getBody(), $response->getStatusCode());
        }

        return \json_decode((string) $response->getBody(), true) ?? [];
    }

    /**
     * Call the Vercel REST API as the installation, scoped to its team when it has one
     *
     * @param array<string, string> $query
     * @return array<mixed>
     */
    protected function api(Client $clientForOAuth2, string $accessToken, Document $identity, string $path, array $query = []): array
    {
        $teamId = $identity->getAttribute('secrets', [])['teamId'] ?? '';

        if ($teamId !== '') {
            $query['teamId'] = $teamId;
        }

        return $this->request($clientForOAuth2, 'GET', $path . (empty($query) ? '' : '?' . \http_build_query($query)), [
            'Authorization' => 'Bearer ' . $accessToken,
        ]);
    }

    /**
     * The site settings of a Vercel project, in the shape of Appwrite's site parameters
     *
     * @param array<mixed> $project
     */
    protected function toSite(array $project): Document
    {
        $link = $project['link'] ?? [];

        return new Document([
            'projectId' => $project['id'] ?? '',
            'name' => $project['name'] ?? '',
            'framework' => self::FRAMEWORKS[$project['framework'] ?? ''] ?? 'other',
            'installCommand' => $project['installCommand'] ?? '',
            'buildCommand' => $project['buildCommand'] ?? '',
            'outputDirectory' => $project['outputDirectory'] ?? '',
            'rootDirectory' => $project['rootDirectory'] ?? '',
            'repositoryProvider' => $link['type'] ?? '',
            'repositoryOwner' => $link['org'] ?? ($link['projectNamespace'] ?? ''),
            'repositoryName' => $link['repo'] ?? ($link['projectName'] ?? ''),
            'branch' => $link['productionBranch'] ?? '',
            'variables' => [],
        ]);
    }
}

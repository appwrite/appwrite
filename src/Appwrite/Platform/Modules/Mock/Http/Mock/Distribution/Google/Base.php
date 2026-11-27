<?php

namespace Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Google;

use Appwrite\Platform\Action;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Cache\Cache;
use Utopia\Platform\Scope\HTTP;

abstract class Base extends Action
{
    use HTTP;

    protected const string PREFIX = '/v1/mock/distribution/google';
    protected const string SEEDS = self::PREFIX . '/seeds';
    protected const string APPLICATIONS = self::PREFIX . '/androidpublisher/v3/applications';
    protected const string SCOPE = 'https://www.googleapis.com/auth/androidpublisher';
    protected const string AUDIENCE = 'https://oauth2.googleapis.com/token';
    protected const string GRANT = 'urn:ietf:params:oauth:grant-type:jwt-bearer';
    protected const array TRACKS = ['production', 'beta', 'alpha', 'internal'];
    protected const int TTL = 86400;
    protected const int TOKEN_LIFETIME = 3600;

    protected const string RESOURCE_ACCOUNT = 'account';
    protected const string RESOURCE_TOKEN = 'token';
    protected const string RESOURCE_APPLICATION = 'application';
    protected const string RESOURCE_EDIT = 'edit';
    protected const string RESOURCE_CURRENT = 'current';

    protected function key(string $resource, string $id): string
    {
        return 'mock:distribution:google:' . $resource . ':' . $id;
    }

    protected function current(string $packageName, string $email): string
    {
        return $this->key(self::RESOURCE_CURRENT, $packageName . ':' . $email);
    }

    protected function authorize(Request $request, Response $response, Cache $cache): ?string
    {
        $header = $request->getHeaderLine('authorization');

        if (!\str_starts_with($header, 'Bearer ')) {
            $this->unauthenticated($response);
            return null;
        }

        $email = $cache->load($this->key(self::RESOURCE_TOKEN, \substr($header, \strlen('Bearer '))), self::TOKEN_LIFETIME);

        if (!\is_string($email) || $email === '') {
            $this->unauthenticated($response);
            return null;
        }

        return $email;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function application(Response $response, Cache $cache, string $packageName, string $email): ?array
    {
        $application = $cache->load($this->key(self::RESOURCE_APPLICATION, $packageName), self::TTL);

        if (!\is_array($application)) {
            $this->notFound($response, 'applicationNotFound', 'Package not found: ' . $packageName . '.');
            return null;
        }

        if (!\in_array($email, $application['emails'] ?? [], true)) {
            $this->forbidden($response);
            return null;
        }

        return $application;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function edit(Request $request, Response $response, Cache $cache, string $packageName, string $editId): ?array
    {
        $email = $this->authorize($request, $response, $cache);

        if ($email === null || $this->application($response, $cache, $packageName, $email) === null) {
            return null;
        }

        $edit = $cache->load($this->key(self::RESOURCE_EDIT, $editId), self::TTL);

        if (
            !\is_array($edit)
            || ($edit['packageName'] ?? '') !== $packageName
            || ($edit['email'] ?? '') !== $email
            || (int) ($edit['expiryTimeSeconds'] ?? 0) < \time()
            || $cache->load($this->current($packageName, $email), self::TTL) !== $editId
        ) {
            $this->notFound($response, 'editNotFound', 'This Edit has been deleted.');
            return null;
        }

        return $edit;
    }

    /**
     * @param array<string, mixed> $edit
     * @return array{id: string, expiryTimeSeconds: string}
     */
    protected function appEdit(array $edit): array
    {
        return [
            'id' => (string) $edit['id'],
            'expiryTimeSeconds' => (string) $edit['expiryTimeSeconds'],
        ];
    }

    protected function grantError(Response $response, string $description): void
    {
        $response
            ->setStatusCode(Response::STATUS_CODE_BAD_REQUEST)
            ->json([
                'error' => 'invalid_grant',
                'error_description' => $description,
            ]);
    }

    protected function unauthenticated(Response $response): void
    {
        $this->error(
            $response,
            Response::STATUS_CODE_UNAUTHORIZED,
            'UNAUTHENTICATED',
            'authError',
            'Request had invalid authentication credentials. Expected OAuth 2 access token, login cookie or other valid authentication credential.',
        );
    }

    protected function forbidden(Response $response): void
    {
        $this->error($response, Response::STATUS_CODE_FORBIDDEN, 'PERMISSION_DENIED', 'forbidden', 'The caller does not have permission');
    }

    protected function notFound(Response $response, string $reason, string $message): void
    {
        $this->error($response, Response::STATUS_CODE_NOT_FOUND, 'NOT_FOUND', $reason, $message);
    }

    private function error(Response $response, int $code, string $status, string $reason, string $message): void
    {
        $response
            ->setStatusCode($code)
            ->json([
                'error' => [
                    'code' => $code,
                    'message' => $message,
                    'status' => $status,
                    'errors' => [
                        [
                            'reason' => $reason,
                            'domain' => 'global',
                            'message' => $message,
                        ],
                    ],
                ],
            ]);
    }
}

<?php

namespace Tests\E2E\Services\Distribution;

use Appwrite\ID;
use Appwrite\Tests\Async\Exceptions\Critical;
use Tests\E2E\Client;
use Utopia\Distribution\Signing\Keystore;

trait DistributionBase
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function routes(): iterable
    {
        yield 'createApp' => [Client::METHOD_POST, '/distribution/apps', 'distribution.write'];
        yield 'listApps' => [Client::METHOD_GET, '/distribution/apps', 'distribution.read'];
        yield 'getApp' => [Client::METHOD_GET, '/distribution/apps/missing', 'distribution.read'];
        yield 'updateApp' => [Client::METHOD_PATCH, '/distribution/apps/missing', 'distribution.write'];
        yield 'deleteApp' => [Client::METHOD_DELETE, '/distribution/apps/missing', 'distribution.write'];
        yield 'createTarget' => [Client::METHOD_POST, '/distribution/apps/missing/targets', 'distribution.write'];
        yield 'listTargets' => [Client::METHOD_GET, '/distribution/apps/missing/targets', 'distribution.read'];
        yield 'getTarget' => [Client::METHOD_GET, '/distribution/apps/missing/targets/missing', 'distribution.read'];
        yield 'updateTarget' => [Client::METHOD_PATCH, '/distribution/apps/missing/targets/missing', 'distribution.write'];
        yield 'deleteTarget' => [Client::METHOD_DELETE, '/distribution/apps/missing/targets/missing', 'distribution.write'];
        yield 'createVariable' => [Client::METHOD_POST, '/distribution/apps/missing/variables', 'distribution.write'];
        yield 'listVariables' => [Client::METHOD_GET, '/distribution/apps/missing/variables', 'distribution.read'];
        yield 'getVariable' => [Client::METHOD_GET, '/distribution/apps/missing/variables/missing', 'distribution.read'];
        yield 'updateVariable' => [Client::METHOD_PATCH, '/distribution/apps/missing/variables/missing', 'distribution.write'];
        yield 'deleteVariable' => [Client::METHOD_DELETE, '/distribution/apps/missing/variables/missing', 'distribution.write'];
        yield 'createAppleConnection' => [Client::METHOD_POST, '/distribution/connections/apple', 'distribution.credentials.write'];
        yield 'updateAppleConnection' => [Client::METHOD_PATCH, '/distribution/connections/apple/missing', 'distribution.credentials.write'];
        yield 'createGoogleConnection' => [Client::METHOD_POST, '/distribution/connections/google', 'distribution.credentials.write'];
        yield 'updateGoogleConnection' => [Client::METHOD_PATCH, '/distribution/connections/google/missing', 'distribution.credentials.write'];
        yield 'listConnections' => [Client::METHOD_GET, '/distribution/connections', 'distribution.credentials.read'];
        yield 'getConnection' => [Client::METHOD_GET, '/distribution/connections/missing', 'distribution.credentials.read'];
        yield 'deleteConnection' => [Client::METHOD_DELETE, '/distribution/connections/missing', 'distribution.credentials.write'];
        yield 'createIosSigningIdentity' => [Client::METHOD_POST, '/distribution/signing-identities/ios', 'distribution.credentials.write'];
        yield 'createAndroidSigningIdentity' => [Client::METHOD_POST, '/distribution/signing-identities/android', 'distribution.credentials.write'];
        yield 'listSigningIdentities' => [Client::METHOD_GET, '/distribution/signing-identities', 'distribution.credentials.read'];
        yield 'getSigningIdentity' => [Client::METHOD_GET, '/distribution/signing-identities/missing', 'distribution.credentials.read'];
        yield 'updateSigningIdentity' => [Client::METHOD_PATCH, '/distribution/signing-identities/missing', 'distribution.credentials.write'];
        yield 'deleteSigningIdentity' => [Client::METHOD_DELETE, '/distribution/signing-identities/missing', 'distribution.credentials.write'];
        yield 'getSigningIdentityDownload' => [Client::METHOD_GET, '/distribution/signing-identities/missing/download', 'distribution.credentials.write'];
        yield 'createArtifact' => [Client::METHOD_POST, '/distribution/apps/missing/artifacts', 'distribution.write'];
        yield 'listArtifacts' => [Client::METHOD_GET, '/distribution/apps/missing/artifacts', 'distribution.read'];
        yield 'getArtifact' => [Client::METHOD_GET, '/distribution/apps/missing/artifacts/missing', 'distribution.read'];
        yield 'getArtifactDownload' => [Client::METHOD_GET, '/distribution/apps/missing/artifacts/missing/download', 'distribution.read'];
        yield 'deleteArtifact' => [Client::METHOD_DELETE, '/distribution/apps/missing/artifacts/missing', 'distribution.write'];
        yield 'createRelease' => [Client::METHOD_POST, '/distribution/apps/missing/releases', 'distribution.write'];
        yield 'listReleases' => [Client::METHOD_GET, '/distribution/apps/missing/releases', 'distribution.read'];
        yield 'getRelease' => [Client::METHOD_GET, '/distribution/apps/missing/releases/missing', 'distribution.read'];
        yield 'updateRelease' => [Client::METHOD_PATCH, '/distribution/apps/missing/releases/missing', 'distribution.write'];
        yield 'deleteRelease' => [Client::METHOD_DELETE, '/distribution/apps/missing/releases/missing', 'distribution.write'];
        yield 'createListing' => [Client::METHOD_POST, '/distribution/apps/missing/listings', 'distribution.write'];
        yield 'listListings' => [Client::METHOD_GET, '/distribution/apps/missing/listings', 'distribution.read'];
        yield 'getListing' => [Client::METHOD_GET, '/distribution/apps/missing/listings/missing', 'distribution.read'];
        yield 'updateListing' => [Client::METHOD_PATCH, '/distribution/apps/missing/listings/missing', 'distribution.write'];
        yield 'deleteListing' => [Client::METHOD_DELETE, '/distribution/apps/missing/listings/missing', 'distribution.write'];
        yield 'createListingScreenshot' => [Client::METHOD_POST, '/distribution/apps/missing/listings/missing/screenshots', 'distribution.write'];
        yield 'deleteListingScreenshot' => [Client::METHOD_DELETE, '/distribution/apps/missing/listings/missing/screenshots/missing', 'distribution.write'];
        yield 'createSubmission' => [Client::METHOD_POST, '/distribution/apps/missing/submissions', 'distribution.submissions.write'];
        yield 'listSubmissions' => [Client::METHOD_GET, '/distribution/apps/missing/submissions', 'distribution.submissions.read'];
        yield 'getSubmission' => [Client::METHOD_GET, '/distribution/apps/missing/submissions/missing', 'distribution.submissions.read'];
        yield 'updateSubmission' => [Client::METHOD_PATCH, '/distribution/apps/missing/submissions/missing', 'distribution.submissions.write'];
        yield 'updateSubmissionStatus' => [Client::METHOD_PATCH, '/distribution/apps/missing/submissions/missing/status', 'distribution.submissions.write'];
        yield 'createCheck' => [Client::METHOD_POST, '/distribution/apps/missing/checks', 'distribution.read'];
        yield 'createBuild' => [Client::METHOD_POST, '/distribution/apps/missing/builds', 'distribution.write'];
        yield 'listBuilds' => [Client::METHOD_GET, '/distribution/apps/missing/builds', 'distribution.read'];
        yield 'getBuild' => [Client::METHOD_GET, '/distribution/apps/missing/builds/missing', 'distribution.read'];
        yield 'deleteBuild' => [Client::METHOD_DELETE, '/distribution/apps/missing/builds/missing', 'distribution.write'];
        yield 'updateBuildStatus' => [Client::METHOD_PATCH, '/distribution/apps/missing/builds/missing/status', 'distribution.write'];
        yield 'listBuildLogs' => [Client::METHOD_GET, '/distribution/apps/missing/builds/missing/logs', 'distribution.read'];
    }

    /**
     * @return list<string>
     */
    public static function scopes(): array
    {
        return [
            'distribution.read',
            'distribution.write',
            'distribution.credentials.read',
            'distribution.credentials.write',
            'distribution.submissions.read',
            'distribution.submissions.write',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function headers(): array
    {
        return \array_merge([
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ], $this->getHeaders());
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    protected function createApp(array $params = []): array
    {
        return $this->client->call(Client::METHOD_POST, '/distribution/apps', $this->headers(), \array_merge([
            'appId' => ID::unique(),
            'name' => 'App ' . \uniqid(),
            'framework' => 'flutter',
        ], $params));
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    protected function createTarget(string $appId, array $params = []): array
    {
        return $this->client->call(Client::METHOD_POST, '/distribution/apps/' . $appId . '/targets', $this->headers(), \array_merge([
            'targetId' => ID::unique(),
            'platform' => 'android',
            'identifier' => 'io.appwrite.t' . \uniqid(),
        ], $params));
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    protected function createRelease(string $appId, array $params = []): array
    {
        return $this->client->call(Client::METHOD_POST, '/distribution/apps/' . $appId . '/releases', $this->headers(), \array_merge([
            'releaseId' => ID::unique(),
            'version' => '1.0.0+' . \uniqid(),
        ], $params));
    }

    /**
     * @return array<string, mixed>
     */
    protected function getRelease(string $appId, string $releaseId): array
    {
        return $this->client->call(Client::METHOD_GET, '/distribution/apps/' . $appId . '/releases/' . $releaseId, $this->headers());
    }

    /**
     * @return array<string, mixed>
     */
    protected function createKeystoreIdentity(Keystore $keystore): array
    {
        return $this->client->call(Client::METHOD_POST, '/distribution/signing-identities/android', $this->headers(), [
            'signingIdentityId' => ID::unique(),
            'name' => 'Keystore ' . \uniqid(),
            'keystore' => \base64_encode($keystore->archive),
            'storePassword' => $keystore->password,
            'keyAlias' => $keystore->alias,
            'keyPassword' => $keystore->password,
        ]);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    protected function createBuild(string $appId, array $params): array
    {
        return $this->client->call(Client::METHOD_POST, '/distribution/apps/' . $appId . '/builds', $this->headers(), $params);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getBuild(string $appId, string $buildId): array
    {
        return $this->client->call(Client::METHOD_GET, '/distribution/apps/' . $appId . '/builds/' . $buildId, $this->headers());
    }

    /**
     * @param list<string> $statuses
     * @return array<string, mixed>
     */
    protected function awaitBuild(string $appId, string $buildId, array $statuses, int $timeoutMs = 300_000): array
    {
        $build = [];

        $this->assertEventually(function () use ($appId, $buildId, $statuses, &$build): void {
            $fetched = $this->getBuild($appId, $buildId);
            $this->assertSame(200, $fetched['headers']['status-code']);
            $build = $fetched['body'];

            if (!\in_array($build['status'], $statuses, true) && \in_array($build['status'], ['ready', 'failed', 'canceled'], true)) {
                throw new Critical('Build ' . $buildId . ' ended ' . $build['status'] . ' instead of ' . \implode(' or ', $statuses) . ': ' . $build['errorCode'] . ' ' . $build['errorMessage']);
            }

            $this->assertContains($build['status'], $statuses);
        }, $timeoutMs, 2000);

        return $build;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    protected function createSubmission(string $appId, array $params): array
    {
        return $this->client->call(Client::METHOD_POST, '/distribution/apps/' . $appId . '/submissions', $this->headers(), $params);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getSubmission(string $appId, string $submissionId): array
    {
        return $this->client->call(Client::METHOD_GET, '/distribution/apps/' . $appId . '/submissions/' . $submissionId, $this->headers());
    }

    /**
     * @return array<string, mixed>
     */
    protected function updateSubmissionStatus(string $appId, string $submissionId, string $status): array
    {
        return $this->client->call(Client::METHOD_PATCH, '/distribution/apps/' . $appId . '/submissions/' . $submissionId . '/status', $this->headers(), [
            'status' => $status,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function awaitSubmission(string $appId, string $submissionId, string $status, int $timeoutMs = 240_000): array
    {
        $submission = [];

        $this->assertEventually(function () use ($appId, $submissionId, $status, &$submission): void {
            $fetched = $this->getSubmission($appId, $submissionId);
            $this->assertSame(200, $fetched['headers']['status-code']);
            $submission = $fetched['body'];

            if ($submission['status'] !== $status && \in_array($submission['status'], ['failed', 'rejected', 'canceled'], true)) {
                throw new Critical('Submission ' . $submissionId . ' ended ' . $submission['status'] . ' instead of ' . $status . ': ' . $submission['errorCode'] . ' ' . $submission['errorMessage']);
            }

            $this->assertSame($status, $submission['status']);
        }, $timeoutMs, 2000);

        return $submission;
    }
}

<?php

namespace Tests\E2E\Services\Advisor;

use Tests\E2E\Client;
use Utopia\Database\Id;

trait AdvisorBase
{
    protected function serverHeaders(): array
    {
        return [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
            'x-appwrite-key' => $this->getProject()['apiKey'],
        ];
    }

    protected function getReport(string $reportId, ?array $headers = null): array
    {
        return $this->client->call(Client::METHOD_GET, '/reports/' . $reportId, $headers ?? $this->serverHeaders());
    }

    protected function listReports(array $params = [], ?array $headers = null): array
    {
        return $this->client->call(Client::METHOD_GET, '/reports', $headers ?? $this->serverHeaders(), $params);
    }

    protected function getInsight(string $reportId, string $insightId, ?array $headers = null): array
    {
        return $this->client->call(Client::METHOD_GET, '/reports/' . $reportId . '/insights/' . $insightId, $headers ?? $this->serverHeaders());
    }

    protected function listInsights(string $reportId, array $params = [], ?array $headers = null): array
    {
        return $this->client->call(Client::METHOD_GET, '/reports/' . $reportId . '/insights', $headers ?? $this->serverHeaders(), $params);
    }

    public function testListReports(): void
    {
        $list = $this->listReports();

        $this->assertSame(200, $list['headers']['status-code']);
        $this->assertArrayHasKey('reports', $list['body']);
        $this->assertArrayHasKey('total', $list['body']);
        $this->assertIsArray($list['body']['reports']);
    }

    public function testGetReportMissing(): void
    {
        $missing = $this->getReport(Id::unique());

        $this->assertSame(404, $missing['headers']['status-code']);
        $this->assertSame('report_not_found', $missing['body']['type']);
    }

    public function testListInsightsMissingReport(): void
    {
        $missing = $this->listInsights(Id::unique());

        $this->assertSame(404, $missing['headers']['status-code']);
        $this->assertSame('report_not_found', $missing['body']['type']);
    }

    public function testGetInsightMissingReport(): void
    {
        $missing = $this->getInsight(Id::unique(), Id::unique());

        $this->assertSame(404, $missing['headers']['status-code']);
        $this->assertSame('report_not_found', $missing['body']['type']);
    }

    public function testReportsCreateAndUpdateNotExposed(): void
    {
        $create = $this->client->call(Client::METHOD_POST, '/reports', $this->serverHeaders(), [
            'reportId' => Id::unique(),
            'type' => 'audit',
            'title' => 'Read-only check',
            'targetType' => 'sites',
            'target' => 'home',
        ]);
        $this->assertSame(404, $create['headers']['status-code']);

        $update = $this->client->call(Client::METHOD_PATCH, '/reports/' . Id::unique(), $this->serverHeaders(), [
            'title' => 'Read-only check',
        ]);
        $this->assertSame(404, $update['headers']['status-code']);
    }

    public function testDeleteReportMissing(): void
    {
        $delete = $this->client->call(Client::METHOD_DELETE, '/reports/' . Id::unique(), $this->serverHeaders());
        $this->assertSame(404, $delete['headers']['status-code']);
        $this->assertSame('report_not_found', $delete['body']['type']);
    }

    public function testInsightsCreateUpdateDeleteNotExposed(): void
    {
        $create = $this->client->call(
            Client::METHOD_POST,
            '/reports/' . Id::unique() . '/insights',
            $this->serverHeaders(),
            []
        );
        $this->assertSame(404, $create['headers']['status-code']);

        $update = $this->client->call(
            Client::METHOD_PATCH,
            '/reports/' . Id::unique() . '/insights/' . Id::unique(),
            $this->serverHeaders(),
            ['status' => 'dismissed']
        );
        $this->assertSame(404, $update['headers']['status-code']);

        $delete = $this->client->call(
            Client::METHOD_DELETE,
            '/reports/' . Id::unique() . '/insights/' . Id::unique(),
            $this->serverHeaders()
        );
        $this->assertSame(404, $delete['headers']['status-code']);
    }
}

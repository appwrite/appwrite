<?php

namespace Tests\E2E\Services\Project;

trait LabelsBase
{
    // Update labels tests

    public function testUpdateLabels(): void
    {
        $response = $this->updateLabels(['frontend', 'backend']);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertIsArray($response['body']['labels']);
        $this->assertCount(2, $response['body']['labels']);
        $this->assertContains('frontend', $response['body']['labels']);
        $this->assertContains('backend', $response['body']['labels']);

        // Cleanup
        $this->updateLabels([]);
    }

    public function testUpdateLabelsReplace(): void
    {
        $response = $this->updateLabels(['alpha', 'beta']);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertCount(2, $response['body']['labels']);
        $this->assertContains('alpha', $response['body']['labels']);
        $this->assertContains('beta', $response['body']['labels']);

        // Replace with new labels
        $response = $this->updateLabels(['gamma', 'delta', 'epsilon']);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertCount(3, $response['body']['labels']);
        $this->assertContains('gamma', $response['body']['labels']);
        $this->assertContains('delta', $response['body']['labels']);
        $this->assertContains('epsilon', $response['body']['labels']);
        $this->assertNotContains('alpha', $response['body']['labels']);
        $this->assertNotContains('beta', $response['body']['labels']);

        // Cleanup
        $this->updateLabels([]);
    }

    public function testUpdateLabelsEmpty(): void
    {
        // Set some labels first
        $response = $this->updateLabels(['toRemove']);
        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertCount(1, $response['body']['labels']);

        // Clear all labels
        $response = $this->updateLabels([]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertIsArray($response['body']['labels']);
        $this->assertCount(0, $response['body']['labels']);
    }

    public function testUpdateLabelsDeduplicated(): void
    {
        $response = $this->updateLabels(['duplicate', 'duplicate', 'unique']);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertCount(2, $response['body']['labels']);
        $this->assertContains('duplicate', $response['body']['labels']);
        $this->assertContains('unique', $response['body']['labels']);

        // Cleanup
        $this->updateLabels([]);
    }

    public function testUpdateLabelsSingleLabel(): void
    {
        $response = $this->updateLabels(['solo']);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertCount(1, $response['body']['labels']);
        $this->assertContains('solo', $response['body']['labels']);

        // Cleanup
        $this->updateLabels([]);
    }

    public function testUpdateLabelsWithoutAuthentication(): void
    {
        $response = $this->updateLabels(['unauthorized'], false);

        $this->assertSame(401, $response['headers']['status-code']);
    }

    public function testUpdateLabelsInvalidLabelTooLong(): void
    {
        $response = $this->updateLabels([str_repeat('a', 37)]);

        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testUpdateLabelsInvalidLabelCharacters(): void
    {
        $response = $this->updateLabels(['invalid-label!']);

        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testUpdateLabelsAlphanumericOnly(): void
    {
        $response = $this->updateLabels(['ABC123', 'lowercase', 'UPPERCASE', '0123456789']);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertCount(4, $response['body']['labels']);

        // Cleanup
        $this->updateLabels([]);
    }

    public function testUpdateLabelsMaxLength(): void
    {
        $label = str_repeat('a', 36);
        $response = $this->updateLabels([$label]);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertCount(1, $response['body']['labels']);
        $this->assertContains($label, $response['body']['labels']);

        // Cleanup
        $this->updateLabels([]);
    }

    public function testUpdateLabelsIdempotent(): void
    {
        $labels = ['stable', 'production'];

        $first = $this->updateLabels($labels);
        $this->assertSame(200, $first['headers']['status-code']);

        $second = $this->updateLabels($labels);
        $this->assertSame(200, $second['headers']['status-code']);

        $this->assertSame($first['body']['labels'], $second['body']['labels']);

        // Cleanup
        $this->updateLabels([]);
    }

    public function testUpdateLabelsDeduplicatedOrder(): void
    {
        $response = $this->updateLabels(['b', 'a', 'b']);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertCount(2, $response['body']['labels']);
        $this->assertSame('b', $response['body']['labels'][0]);
        $this->assertSame('a', $response['body']['labels'][1]);

        // Cleanup
        $this->updateLabels([]);
    }

    public function testUpdateLabelsInvalidHyphen(): void
    {
        $response = $this->updateLabels(['my-label']);

        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testUpdateLabelsInvalidUnderscore(): void
    {
        $response = $this->updateLabels(['my_label']);

        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testUpdateLabelsInvalidSpace(): void
    {
        $response = $this->updateLabels(['my label']);

        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testUpdateLabelsInvalidEmptyString(): void
    {
        $response = $this->updateLabels(['']);

        $this->assertSame(400, $response['headers']['status-code']);
    }

    public function testUpdateLabelsResponseModel(): void
    {
        $response = $this->updateLabels(['test']);

        $this->assertSame(200, $response['headers']['status-code']);
        $this->assertArrayHasKey('$id', $response['body']);
        $this->assertArrayHasKey('name', $response['body']);
        $this->assertArrayHasKey('labels', $response['body']);
        $this->assertIsArray($response['body']['labels']);
        $this->assertContains('test', $response['body']['labels']);

        // Cleanup
        $this->updateLabels([]);
    }

    // Helpers

    /**
     * @param array<string> $labels
     */
    protected function updateLabels(array $labels, bool $authenticated = true): mixed
    {
        $headers = [
            'content-type' => 'application/json',
            'x-appwrite-project' => $this->getProject()['$id'],
        ];

        if ($authenticated) {
            $headers = array_merge($headers, $this->getHeaders());
        }

        return $this->client->call(\Tests\E2E\Client::METHOD_PUT, '/project/labels', $headers, [
            'labels' => $labels,
        ]);
    }
}

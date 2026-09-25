<?php

declare(strict_types=1);

namespace Tests\E2E\General;

use GraphQL\Language\Parser;
use PHPUnit\Framework\TestCase;

final class SpecsTest extends TestCase
{
    public function testRequestExamples(): void
    {
        $root = dirname(__DIR__, 3);
        $version = 'examples-' . bin2hex(random_bytes(3));
        $directory = $root . '/app/config/specs';
        $files = array_map(
            static fn (string $suffix): string => $directory . '/open-api3-' . $version . $suffix . '.json',
            ['', '-client', '-server', '-console'],
        );

        try {
            // Exercise the actual CLI task, including preview-only methods and SDK aliases.
            exec(
                '_APP_SDK_PREVIEW=enabled ' . escapeshellarg(PHP_BINARY) . ' '
                . escapeshellarg($root . '/app/cli.php')
                . ' specs --version=' . escapeshellarg($version) . ' --mode=normal --git=no 2>&1',
                $output,
                $status,
            );
            $this->assertSame(0, $status, implode("\n", $output));

            foreach ($files as $file) {
                $this->assertFileExists($file);
                $spec = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
                $checked = 0;

                foreach ($spec['paths'] as $path => $methods) {
                    foreach ($methods as $method => $operation) {
                        if (!isset($operation['operationId'])) {
                            continue;
                        }
                        $context = basename($file) . ' ' . $method . ' ' . $path;
                        foreach ($operation['parameters'] ?? [] as $parameter) {
                            if (($parameter['required'] ?? false) && ($parameter['schema']['type'] ?? '') === 'array') {
                                $this->assertArrayExample($parameter['schema'], $context . ' ' . $parameter['name']);
                                $checked++;
                            }
                        }
                        foreach ($operation['requestBody']['content'] ?? [] as $content) {
                            $schema = $content['schema'];
                            $required = $schema['required'] ?? [];
                            foreach ($operation['x-appwrite']['methods'] ?? [] as $alias) {
                                $required = array_merge($required, $alias['required'] ?? []);
                            }
                            foreach (array_unique($required) as $name) {
                                $property = $schema['properties'][$name] ?? [];
                                if (($property['type'] ?? '') === 'array') {
                                    $this->assertArrayExample($property, $context . ' ' . $name);
                                    $checked++;
                                }
                            }
                        }
                    }
                }
                $this->assertGreaterThan(0, $checked, basename($file));
            }

            $spec = json_decode(file_get_contents($files[0]), true, flags: JSON_THROW_ON_ERROR);
            $properties = $spec['paths']['/vectorsdb/{databaseId}/collections']['post']['requestBody']['content']['application/json']['schema']['properties'];
            $dimension = $properties['dimension']['example'];
            $this->assertSame(4, $dimension);

            $properties = $spec['paths']['/vectorsdb/{databaseId}/collections/{collectionId}/indexes']['post']['requestBody']['content']['application/json']['schema']['properties'];
            $this->assertSame('hnsw_euclidean', $properties['type']['example']);
            $this->assertSame(['embeddings'], $properties['attributes']['example']);

            $path = '/vectorsdb/{databaseId}/collections/{collectionId}/documents';
            foreach (['post', 'put'] as $method) {
                $properties = $spec['paths'][$path][$method]['requestBody']['content']['application/json']['schema']['properties'];
                foreach ($properties['documents']['example'] as $document) {
                    $this->assertCount($dimension, $document['embeddings']);
                    $this->assertArrayHasKey('$id', $document);
                    $this->assertArrayHasKey('metadata', $document);
                }
            }
            $properties = $spec['paths']['/vectorsdb/transactions/{transactionId}/operations']['post']['requestBody']['content']['application/json']['schema']['properties'];
            $this->assertCount($dimension, $properties['operations']['example'][0]['data']['embeddings']);

            foreach (['/graphql', '/graphql/mutation'] as $path) {
                $properties = $spec['paths'][$path]['post']['requestBody']['content']['application/json']['schema']['properties'];
                $query = $properties['query']['example']['query'];
                $this->assertNotEmpty($query);
                $this->assertCount(1, Parser::parse($query)->definitions);
            }
        } finally {
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }

    private function assertArrayExample(array $schema, string $context): void
    {
        $this->assertArrayHasKey('example', $schema, $context);
        $example = $schema['example'];
        $this->assertIsArray($example, $context);
        $this->assertNotEmpty($example, $context);
        $this->assertTrue(array_is_list($example), $context);

        foreach ($example as $item) {
            switch ($schema['items']['type'] ?? '') {
                case 'string':
                    $this->assertIsString($item, $context);
                    break;
                case 'object':
                    $this->assertIsArray($item, $context);
                    $this->assertFalse(array_is_list($item), $context);
                    break;
            }
            if (isset($schema['items']['oneOf'])) {
                $values = array_merge(...array_column($schema['items']['oneOf'], 'enum'));
                $this->assertContains($item, $values, $context);
            }
        }
    }
}

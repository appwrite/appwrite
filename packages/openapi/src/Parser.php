<?php

declare(strict_types=1);

namespace Utopia\OpenAPI;

use JsonException;
use Utopia\OpenAPI\Exception\InvalidSpecification;
use Utopia\OpenAPI\Exception\ParseException;
use Utopia\OpenAPI\Parser\OpenAPI2;
use Utopia\OpenAPI\Parser\OpenAPI30;
use Utopia\OpenAPI\Parser\OpenAPI31;
use Utopia\OpenAPI\Parser\Reader;
use Utopia\OpenAPI\Parser\Schema\Dialect;
use Utopia\OpenAPI\Parser\Schema\Reader as SchemaReader;

final class Parser
{
    /** @param string|array<string, mixed> $input */
    public function read(string|array $input, ?Version $version = null): Specification
    {
        $document = $this->decode($input);
        [$detected, $sourceVersion] = $this->detectVersion($document);

        if ($version !== null && $version !== $detected) {
            throw new InvalidSpecification(
                "Expected OpenAPI {$version->value}, document declares {$sourceVersion}",
            );
        }

        $reader = $this->reader($detected, $sourceVersion, $document);

        return $reader->read();
    }

    /** @param string|array<string, mixed> $input */
    public static function parse(string|array $input, ?Version $version = null): Specification
    {
        return new self()->read($input, $version);
    }

    /** @return array<string, mixed> */
    private function decode(string|array $input): array
    {
        if (\is_array($input)) {
            if ($input !== [] && array_is_list($input)) {
                throw new InvalidSpecification('The OpenAPI document root must be an object');
            }

            return $input;
        }

        try {
            $decoded = json_decode($input, false, 512, JSON_THROW_ON_ERROR);
            $document = $this->normalizeDecodedValue($decoded);
        } catch (JsonException $exception) {
            throw new ParseException('Invalid JSON: ' . $exception->getMessage(), previous: $exception);
        }

        if (! \is_array($document) || ($document !== [] && array_is_list($document))) {
            throw new InvalidSpecification('The OpenAPI document root must be an object');
        }

        return $document;
    }

    private function normalizeDecodedValue(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $object = [];
            foreach ((array) $value as $key => $item) {
                $object[$key] = $this->normalizeDecodedValue($item);
            }

            return $object;
        }
        if (\is_array($value)) {
            return array_map($this->normalizeDecodedValue(...), $value);
        }

        return $value;
    }

    /** @param array<string, mixed> $document @return array{Version, string} */
    private function detectVersion(array $document): array
    {
        if (\array_key_exists('swagger', $document)) {
            if (! \is_string($document['swagger'])) {
                throw new InvalidSpecification("The 'swagger' version must be a string");
            }

            return [Version::fromDocumentVersion($document['swagger']), $document['swagger']];
        }
        if (\array_key_exists('openapi', $document)) {
            if (! \is_string($document['openapi'])) {
                throw new InvalidSpecification("The 'openapi' version must be a string");
            }

            return [Version::fromDocumentVersion($document['openapi']), $document['openapi']];
        }

        throw new InvalidSpecification("Missing 'swagger' or 'openapi' version field");
    }

    /** @param array<string, mixed> $document */
    private function reader(Version $version, string $sourceVersion, array $document): Reader
    {
        $schemas = new SchemaReader(Dialect::for($version));

        return match ($version) {
            Version::V2 => new OpenAPI2($document, $version, $sourceVersion, $schemas),
            Version::V3_0 => new OpenAPI30($document, $version, $sourceVersion, $schemas),
            Version::V3_1 => new OpenAPI31($document, $version, $sourceVersion, $schemas),
        };
    }
}

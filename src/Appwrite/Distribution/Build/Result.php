<?php

namespace Appwrite\Distribution\Build;

use Appwrite\Distribution\Artifact\Type;
use Utopia\Database\Document;

/**
 * The `result.json` an Android build job writes. The job runs untrusted code, so
 * nothing it reports is stored before it passes `parse()`.
 */
final readonly class Result
{
    public const int BYTES = 5_242_880;
    public const int VERSION_CODE = 2_100_000_000;
    public const int VERSION_NAME = 64;
    public const int TOOL = 128;

    /**
     * The tools the build image reports. `image` is not one: the server records the image it dispatched.
     */
    public const array TOOLS = ['fastlane', 'java', 'gradle', 'flutter'];

    /**
     * @param array<string, string|null> $toolchain
     */
    public function __construct(
        public int $versionCode,
        public string $versionName,
        public array $toolchain,
    ) {
    }

    /**
     * @throws \UnexpectedValueException When the result does not describe a bundle of the target
     */
    public static function parse(string $json, Document $target, Document $build): self
    {
        if (\strlen($json) > self::BYTES) {
            throw new \UnexpectedValueException('The build result exceeds ' . self::BYTES . ' bytes.');
        }

        try {
            $result = \json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new \UnexpectedValueException('The build result is not valid JSON.', previous: $error);
        }

        if (!\is_array($result)) {
            throw new \UnexpectedValueException('The build result is not an object.');
        }

        if (($result['type'] ?? null) !== Type::Aab->value) {
            throw new \UnexpectedValueException('The build result is not an Android App Bundle.');
        }

        if (($result['identifier'] ?? null) !== (string) $target->getAttribute('identifier', '')) {
            throw new \UnexpectedValueException('The build result identifier differs from the target.');
        }

        $versionCode = $result['versionCode'] ?? null;
        $expected = $build->getAttribute('versionCode');
        if (!\is_int($versionCode)
            || $versionCode < 1
            || $versionCode > self::VERSION_CODE
            || ($expected !== null && $versionCode !== (int) $expected)) {
            throw new \UnexpectedValueException('The build result version code is invalid or differs from the build.');
        }

        $versionName = $result['versionName'] ?? null;
        if ($versionName !== null && (!\is_string($versionName) || \strlen($versionName) > self::VERSION_NAME)) {
            throw new \UnexpectedValueException('The build result version name is not a string of at most ' . self::VERSION_NAME . ' bytes.');
        }

        return new self(
            versionCode: $versionCode,
            versionName: (string) $versionName ?: (string) $build->getAttribute('versionName', ''),
            toolchain: self::toolchain($result['toolchain'] ?? null),
        );
    }

    /**
     * @return array<string, string|null>
     */
    private static function toolchain(mixed $toolchain): array
    {
        if ($toolchain === null) {
            return [];
        }

        if (!\is_array($toolchain)) {
            throw new \UnexpectedValueException('The build result toolchain is not an object.');
        }

        $tools = \array_intersect_key($toolchain, \array_flip(self::TOOLS));
        foreach ($tools as $version) {
            if ($version !== null && (!\is_string($version) || \strlen($version) > self::TOOL)) {
                throw new \UnexpectedValueException('A build result tool version is not a string of at most ' . self::TOOL . ' bytes.');
            }
        }

        return $tools;
    }
}

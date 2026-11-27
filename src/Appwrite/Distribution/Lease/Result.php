<?php

namespace Appwrite\Distribution\Lease;

use Appwrite\Distribution\Artifact\Type;
use Utopia\Database\Document;

/**
 * The `result` artifact a runner agent reports after it verified the IPA it
 * built. The runner is trusted with the signing identity (RFC §9.1) and vouches
 * for the IPA's digest, which is checked for format only; nothing it reports is
 * stored before it passes `parse()` against the target, the build and the
 * signing identity, and the worker checks its size against the upload.
 */
final readonly class Result
{
    public const int VERSION_NAME = 64;

    public const int TOOL = 1024;

    public const array TOOLS = ['image', 'fastlane', 'xcode', 'flutter'];

    private const int DIGEST = 64;

    /**
     * @param array<string, mixed> $entitlements
     * @param array<string, string|null> $toolchain
     */
    public function __construct(
        public string $digest,
        public int $size,
        public string $identifier,
        public string $versionName,
        public string $buildNumber,
        public string $teamId,
        public bool $signed,
        public array $entitlements,
        public array $toolchain,
    ) {
    }

    /**
     * @throws \UnexpectedValueException When the result does not describe a signed IPA of the build
     */
    public static function parse(mixed $content, Document $target, Document $build, Document $signingIdentity): self
    {
        if (!\is_array($content)) {
            throw new \UnexpectedValueException('The build result is not an object.');
        }

        if (($content['type'] ?? null) !== Type::Ipa->value) {
            throw new \UnexpectedValueException('The build result is not an iOS app archive.');
        }

        $digest = $content['digest'] ?? null;
        if (!\is_string($digest) || \strlen($digest) !== self::DIGEST || !\ctype_xdigit($digest)) {
            throw new \UnexpectedValueException('The build result digest is not a SHA-256 hex digest.');
        }

        $size = $content['size'] ?? null;
        if (!\is_int($size) || $size < 1) {
            throw new \UnexpectedValueException('The build result size is not a positive integer.');
        }

        $identifier = (string) $target->getAttribute('identifier', '');
        if (($content['identifier'] ?? null) !== $identifier) {
            throw new \UnexpectedValueException('The build result identifier differs from the target.');
        }

        $buildNumber = $content['buildNumber'] ?? null;
        if ((!\is_string($buildNumber) && !\is_int($buildNumber)) || (string) $buildNumber !== (string) $build->getAttribute('buildNumber', '')) {
            throw new \UnexpectedValueException('The build result build number differs from the build.');
        }

        $versionName = $content['versionName'] ?? null;
        $expected = (string) $build->getAttribute('versionName', '');
        if (!\is_string($versionName)
            || ($expected !== '' && $versionName !== $expected)
            || ($expected === '' && ($versionName === '' || \strlen($versionName) > self::VERSION_NAME))) {
            throw new \UnexpectedValueException('The build result version name is invalid or differs from the build.');
        }

        $teamId = (string) $signingIdentity->getAttribute('teamId', '');
        if ($teamId === '' || ($content['teamId'] ?? null) !== $teamId) {
            throw new \UnexpectedValueException('The build result team differs from the signing identity.');
        }

        if (($content['signed'] ?? null) !== true) {
            throw new \UnexpectedValueException('The build result is not signed.');
        }

        $entitlements = $content['entitlements'] ?? null;
        if ($entitlements !== null && !\is_array($entitlements)) {
            throw new \UnexpectedValueException('The build result entitlements are not an object.');
        }

        return new self(
            digest: \strtolower($digest),
            size: $size,
            identifier: $identifier,
            versionName: $versionName,
            buildNumber: (string) $buildNumber,
            teamId: $teamId,
            signed: true,
            entitlements: $entitlements ?? [],
            toolchain: self::toolchain($content['toolchain'] ?? null),
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

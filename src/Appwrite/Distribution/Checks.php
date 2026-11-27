<?php

namespace Appwrite\Distribution;

use Appwrite\Distribution\Check\Code;
use Appwrite\Distribution\Check\Severity;
use Appwrite\Distribution\Connection\Status as ConnectionStatus;
use Appwrite\Distribution\Signing\Status as SigningStatus;
use Appwrite\Extend\Exception;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Distribution\Exception\NotFound;
use Utopia\Distribution\Exception\Unauthorized;
use Utopia\Distribution\Issue;

/**
 * Preflight for a target: whether its track, connection, store app, signing identity
 * and, given an artifact, the artifact and its version would let a build or submission
 * go through. Store errors other than rejections (rate limits, outages, malformed
 * requests) are not issues and propagate to the caller.
 *
 * @phpstan-type CheckIssue array{code: string, severity: string, platform: string, field: string, message: string, remediation: string, manualActionRequired: bool}
 */
readonly class Checks
{
    public const int TARGET_SDK = 35;

    private const array CREDENTIAL_ISSUES = ['invalid_grant', 'UNAUTHENTICATED', 'authError', 'NOT_AUTHORIZED'];

    private const array SIGNING_UNUSABLE = [SigningStatus::Expired->value, SigningStatus::Revoked->value];

    private const int SIGNING_EXPIRY_WARNING = 604800;

    private const string WILDCARD = '*';

    private const string WILDCARD_SUFFIX = '.*';

    public function __construct(
        private Database $dbForProject,
        private Stores $stores,
    ) {
    }

    /**
     * @return array{ready: bool, issues: list<CheckIssue>}
     */
    public function run(Document $app, Document $target, string $track, ?Document $artifact = null, bool $store = true): array
    {
        $platform = (string) $target->getAttribute('platform', '');
        $this->track($platform, $track);

        $connection = $this->connectionOf($target);
        $issues = $this->connection($connection, $platform);

        if ($issues === [] && $store && !Submissions::isContended($this->dbForProject, $connection, (string) $target->getAttribute('identifier', ''))) {
            $issues = $this->store($connection, $target, $platform, $artifact);
        }

        if ($artifact !== null) {
            \array_push($issues, ...$this->artifact($artifact, $platform));
        }

        if ($artifact?->getAttribute('signed') !== true) {
            \array_push($issues, ...$this->signing($target, $platform));
        }

        return [
            'ready' => !\array_any($issues, static fn (array $issue): bool => $issue['severity'] === Severity::Error->value),
            'issues' => $issues,
        ];
    }

    private function track(string $platform, string $track): void
    {
        $tracks = Platform::tryFrom($platform) === null ? [] : $this->stores->tracks(Stores::provider(Platform::from($platform)));

        if (!\in_array($track, $tracks, true)) {
            throw new Exception(Exception::DISTRIBUTION_TRACK_INVALID, "Track '{$track}' is not a valid track for {$platform}. Use one of: " . \implode(', ', $tracks) . '.');
        }
    }

    /**
     * @return list<CheckIssue>
     */
    private function connection(Document $connection, string $platform): array
    {
        if ($connection->isEmpty()) {
            return [$this->issue(
                Code::ConnectionMissing,
                $platform,
                'connectionId',
                'The target has no store connection.',
                'Connect an App Store Connect or Google Play account and set it as the connection of the target.',
            )];
        }

        if ($connection->getAttribute('status') === ConnectionStatus::Invalid->value) {
            return [$this->issue(
                Code::ConnectionUnauthorized,
                $platform,
                'connectionId',
                \trim('The store rejected the credentials of the connection. ' . $connection->getAttribute('error', '')),
                'Update the connection with a valid key.',
            )];
        }

        return [];
    }

    /**
     * @return list<CheckIssue>
     */
    private function store(Document $connection, Document $target, string $platform, ?Document $artifact): array
    {
        $identifier = (string) $target->getAttribute('identifier', '');
        $build = $artifact === null ? '' : $this->build($artifact);

        try {
            $adapter = $this->stores->connection($connection);

            if (!\ctype_digit($build)) {
                $adapter->app($identifier);

                return [];
            }

            $latest = $adapter->latestVersionCode($identifier);
        } catch (Exception $exception) {
            if ($exception->getType() !== Exception::DISTRIBUTION_CONNECTION_INVALID) {
                throw $exception;
            }

            return [$this->issue(Code::ConnectionUnauthorized, $platform, 'connectionId', $exception->getMessage(), 'Update the connection with a valid key.')];
        } catch (NotFound $notFound) {
            return [$this->missing($identifier, $platform, $notFound)];
        } catch (Unauthorized $unauthorized) {
            return [$this->unauthorized($unauthorized, $platform)];
        }

        if ($latest < (int) $build) {
            return [];
        }

        return [$this->issue(
            Code::VersionNotIncreasing,
            $platform,
            $platform === Platform::Ios->value ? 'buildNumber' : 'versionCode',
            "Build {$build} is not higher than {$latest}, the highest build the store already has.",
            'Build again with a higher version code or build number.',
        )];
    }

    /**
     * @return list<CheckIssue>
     */
    private function artifact(Document $artifact, string $platform): array
    {
        $issues = [];

        if ($platform === Platform::Ios->value && $artifact->getAttribute('signed') !== true) {
            $issues[] = $this->issue(
                Code::SigningMissing,
                $platform,
                'artifactId',
                'The artifact is not signed.',
                'Upload an archive signed with a distribution certificate and provisioning profile, or build it with the signing identity of the target.',
            );
        }

        $manifest = $artifact->getAttribute('manifest', []);
        $targetSdk = \is_array($manifest) ? ($manifest['targetSdk'] ?? null) : null;

        if ($platform === Platform::Android->value && \is_int($targetSdk) && $targetSdk < self::TARGET_SDK) {
            $issues[] = $this->issue(
                Code::TargetSdkTooLow,
                $platform,
                'targetSdk',
                "The artifact targets API level {$targetSdk}; Google Play requires API level " . self::TARGET_SDK . ' or higher.',
                'Raise targetSdkVersion to ' . self::TARGET_SDK . ' or higher and build again.',
            );
        }

        return $issues;
    }

    /**
     * @return list<CheckIssue>
     */
    private function signing(Document $target, string $platform): array
    {
        $signingIdentityId = (string) $target->getAttribute('signingIdentityId', '');
        $signingIdentity = $signingIdentityId === ''
            ? new Document()
            : $this->dbForProject->getDocument('distributionSigningIdentities', $signingIdentityId);

        if ($signingIdentity->isEmpty()) {
            return [$this->issue(
                Code::SigningMissing,
                $platform,
                'signingIdentityId',
                'The target has no signing identity.',
                'Import a certificate or keystore, or generate a managed identity, and set it as the signing identity of the target.',
            )];
        }

        $issues = [];
        $status = (string) $signingIdentity->getAttribute('status', SigningStatus::Active->value);
        $expiresAt = $this->expiresAt($signingIdentity);
        $now = new \DateTimeImmutable();

        if (\in_array($status, self::SIGNING_UNUSABLE, true) || ($expiresAt !== null && $expiresAt <= $now)) {
            $issues[] = $this->issue(
                Code::SigningExpired,
                $platform,
                'signingIdentityId',
                $status === SigningStatus::Revoked->value ? 'The signing identity was revoked.' : 'The signing identity expired.',
                'Import a new certificate or keystore, or generate a managed identity.',
            );
        } elseif ($status === SigningStatus::Expiring->value || ($expiresAt !== null && $expiresAt->getTimestamp() - $now->getTimestamp() <= self::SIGNING_EXPIRY_WARNING)) {
            $issues[] = $this->issue(
                Code::SigningExpired,
                $platform,
                'signingIdentityId',
                'The signing identity expires ' . ($expiresAt === null ? 'soon' : 'on ' . $expiresAt->format(\DATE_ATOM)) . '.',
                'Rotate the signing identity before it expires.',
                severity: Severity::Warning,
            );
        }

        $identifier = (string) $target->getAttribute('identifier', '');
        $identifiers = $signingIdentity->getAttribute('identifiers', []);

        if (\is_array($identifiers) && $identifiers !== [] && !\array_any($identifiers, static fn (mixed $pattern): bool => \is_string($pattern) && self::covers($pattern, $identifier))) {
            $issues[] = $this->issue(
                Code::SigningIdentifierMismatch,
                $platform,
                'identifier',
                "The signing identity does not cover '{$identifier}'.",
                'Use a signing identity whose provisioning profile covers the identifier of the target.',
            );
        }

        return $issues;
    }

    /**
     * @return CheckIssue
     */
    private function unauthorized(Unauthorized $unauthorized, string $platform): array
    {
        $issues = $unauthorized->getIssues();
        $codes = \array_map(static fn (Issue $issue): string => $issue->code, $issues);

        if ($unauthorized->isCredential() || \array_intersect(self::CREDENTIAL_ISSUES, $codes) !== []) {
            return $this->issue(Code::ConnectionUnauthorized, $platform, 'connectionId', $unauthorized->getMessage(), 'Update the connection with a valid key.');
        }

        if (\array_any($issues, static fn (Issue $issue): bool => $issue->manual)) {
            return $this->issue(
                Code::StoreAgreementRequired,
                $platform,
                'connectionId',
                $unauthorized->getMessage(),
                'Accept the latest agreements in the store console.',
                manual: true,
            );
        }

        return $this->issue(
            Code::ConnectionRoleInsufficient,
            $platform,
            'connectionId',
            $unauthorized->getMessage(),
            'Grant the key of the connection a role that can manage the app and its releases in the store console.',
            manual: true,
        );
    }

    /**
     * @return CheckIssue
     */
    private function missing(string $identifier, string $platform, NotFound $notFound): array
    {
        return $this->issue(
            Code::StoreAppMissing,
            $platform,
            'identifier',
            "The store has no app for '{$identifier}'. " . $notFound->getMessage(),
            'Create the app in the store console with the identifier of the target.',
            manual: true,
        );
    }

    /**
     * @return CheckIssue
     */
    private function issue(Code $code, string $platform, string $field, string $message, string $remediation, Severity $severity = Severity::Error, bool $manual = false): array
    {
        return [
            'code' => $code->value,
            'severity' => $severity->value,
            'platform' => $platform,
            'field' => $field,
            'message' => $message,
            'remediation' => $remediation,
            'manualActionRequired' => $manual,
        ];
    }

    private function connectionOf(Document $target): Document
    {
        $connectionId = (string) $target->getAttribute('connectionId', '');

        return $connectionId === '' ? new Document() : $this->dbForProject->getDocument('distributionConnections', $connectionId);
    }

    private function expiresAt(Document $signingIdentity): ?\DateTimeImmutable
    {
        $expiresAt = $signingIdentity->getAttribute('expiresAt');

        return \is_string($expiresAt) && $expiresAt !== '' ? new \DateTimeImmutable($expiresAt) : null;
    }

    private function build(Document $artifact): string
    {
        return match (Platform::tryFrom((string) $artifact->getAttribute('platform', ''))) {
            Platform::Android => (string) $artifact->getAttribute('versionCode', ''),
            Platform::Ios => (string) $artifact->getAttribute('buildNumber', ''),
            null => '',
        };
    }

    private static function covers(string $pattern, string $identifier): bool
    {
        if ($pattern === self::WILDCARD || $pattern === $identifier) {
            return true;
        }

        return \str_ends_with($pattern, self::WILDCARD_SUFFIX)
            && \str_starts_with($identifier, \substr($pattern, 0, -\strlen(self::WILDCARD)));
    }
}

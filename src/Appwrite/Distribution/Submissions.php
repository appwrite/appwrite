<?php

namespace Appwrite\Distribution;

use Appwrite\Distribution\Artifact\Status as ArtifactStatus;
use Appwrite\Distribution\Check\Code;
use Appwrite\Distribution\Connection\Status as ConnectionStatus;
use Appwrite\Distribution\Release\Status as ReleaseStatus;
use Appwrite\Distribution\Submission\Checkpoint;
use Appwrite\Distribution\Submission\Status;
use Appwrite\Distribution\Submission\Step;
use Appwrite\Event\Message\DistributionSubmission as DistributionSubmissionMessage;
use Appwrite\Event\Message\DistributionSubmissionAction;
use Appwrite\Event\Publisher\DistributionSubmission as DistributionSubmissionPublisher;
use Appwrite\Extend\Exception;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Id;
use Utopia\Database\Query;
use Utopia\Distribution\Exception as StoreException;
use Utopia\Distribution\Exception\Conflict;
use Utopia\Distribution\Exception\Invalid;
use Utopia\Distribution\Exception\NotFound;
use Utopia\Distribution\Exception\RateLimited;
use Utopia\Distribution\Exception\Unauthorized;
use Utopia\Distribution\Exception\Unavailable;
use Utopia\Distribution\Issue;
use Utopia\Distribution\State;
use Utopia\Lock\Exception\Contention;
use Utopia\Storage\Device;

readonly class Submissions
{
    public const array ACTIVE = [
        Status::Waiting->value,
        Status::Validating->value,
        Status::Uploading->value,
        Status::Processing->value,
        Status::Pending->value,
        Status::Reviewing->value,
        Status::Approved->value,
        Status::Halted->value,
    ];

    public const int CLAIM = 90;

    public const string ERROR_REJECTED = 'store_rejected';

    private const array BACKOFF = [60, 300, 900, 3600];

    private const int BACKOFF_LIMIT = 86400;

    private const int INTERVAL_MOVING = 2;

    private const int INTERVAL_WAITING = 15;

    private const int INTERVAL_ROLLOUT = 60;

    private const int CONTROL_ATTEMPTS = 5;

    private const string ISSUE_VERSION_CONFLICT = 'apkUpgradeVersionConflict';

    private const string ISSUE_APPLICATION_NOT_FOUND = 'applicationNotFound';

    private const string ISSUE_EDIT_COMMITTED = 'editAlreadyCommitted';

    private const string ISSUE_IN_REVIEW = 'CHANGES_ALREADY_IN_REVIEW';

    private const string STATE_ABSENT = 'NOT_FOUND';

    private const array ISSUES_CREDENTIAL = ['invalid_grant', 'UNAUTHENTICATED', 'authError', 'NOT_AUTHORIZED'];

    private const array STATES = [
        State::STATUS_PROCESSING => Status::Processing,
        State::STATUS_PENDING => Status::Pending,
        State::STATUS_REVIEWING => Status::Reviewing,
        State::STATUS_APPROVED => Status::Approved,
        State::STATUS_PUBLISHED => Status::Published,
        State::STATUS_REJECTED => Status::Rejected,
        State::STATUS_HALTED => Status::Halted,
    ];

    private const array REJECTIONS = [
        'DEVELOPER_REJECTED' => Status::Canceled,
        'REPLACED_WITH_NEW_VERSION' => Status::Canceled,
        'PROCESSING_EXCEPTION' => Status::Failed,
        'EXPIRED' => Status::Failed,
    ];

    private const array RELEASED = [Step::Released->value, Step::Settled->value];

    public function __construct(
        private Database $dbForProject,
        private Document $project,
        private Stores $stores,
        private Schedules $schedules,
        private Device $deviceForDistribution,
        private DistributionSubmissionPublisher $publisher,
    ) {
    }

    public static function isActive(string $status, float $rollout): bool
    {
        return \in_array($status, self::ACTIVE, true) || ($status === Status::Published->value && $rollout < 1.0);
    }

    public static function lock(string $projectInternalId, string $submissionId): string
    {
        return "distribution:submission:{$projectInternalId}:{$submissionId}";
    }

    public static function storeLock(Document $connection, string $identifier): string
    {
        if (Stores::isExclusive($connection)) {
            return 'distribution:store:' . Provider::Google->value . ':' . \hash('sha256', Stores::identity($connection) . ':' . $identifier);
        }

        return "distribution:store:{$connection->getSequence()}:{$connection->getId()}:{$identifier}";
    }

    /**
     * Opening a Google edit invalidates the other open edits of the same service account and package.
     */
    public static function isContended(Database $dbForProject, Document $connection, string $identifier, string $submissionId = ''): bool
    {
        if (!Stores::isExclusive($connection)) {
            return false;
        }

        $identity = Stores::identity($connection);
        $targets = \array_map(
            static fn (Document $target): string => (string) $target->getSequence(),
            \array_filter(
                $dbForProject->find('distributionTargets', [
                    Query::equal('identifier', [$identifier]),
                    Query::equal('platform', [Platform::Android->value]),
                    Query::limit(APP_LIMIT_SUBQUERY),
                ]),
                static fn (Document $target): bool => Stores::identity($dbForProject->getDocument('distributionConnections', (string) $target->getAttribute('connectionId', ''))) === $identity,
            ),
        );

        if ($targets === []) {
            return false;
        }

        $others = $dbForProject->find('distributionSubmissions', [
            Query::equal('targetInternalId', \array_values($targets)),
            Query::equal('status', self::ACTIVE),
            Query::notEqual('$id', $submissionId),
            Query::limit(APP_LIMIT_SUBQUERY),
        ]);

        return \array_any($others, static function (Document $other): bool {
            $external = $other->getAttribute('external', []);

            return \is_array($external) && (self::isClaimed($external) || self::isEditing($external));
        });
    }

    public static function interval(string $status, float $rollout): int
    {
        return match (Status::tryFrom($status)) {
            Status::Waiting, Status::Validating, Status::Uploading, Status::Processing => self::INTERVAL_MOVING,
            Status::Pending, Status::Reviewing, Status::Approved, Status::Halted => self::INTERVAL_WAITING,
            Status::Published => $rollout < 1.0 ? self::INTERVAL_ROLLOUT : 0,
            default => 0,
        };
    }

    public static function backoff(int $attempt): ?int
    {
        $attempt = \max(1, $attempt);
        $rungs = \count(self::BACKOFF);
        $delay = self::BACKOFF[\min($attempt, $rungs) - 1];
        $elapsed = \array_sum(\array_slice(self::BACKOFF, 0, $attempt)) + \max(0, $attempt - $rungs) * $delay;

        return $elapsed > self::BACKOFF_LIMIT ? null : $delay;
    }

    public static function claimOf(Document $submission): string
    {
        $external = $submission->getAttribute('external', []);

        return \is_array($external) ? (string) ($external['claim']['id'] ?? '') : '';
    }

    public static function isAt(Document $submission, ?Step $step): bool
    {
        return Step::tryFrom((string) $submission->getAttribute('step', '')) === $step;
    }

    public function create(Document $app, Document $release, Document $target, Document $artifact, string $track, bool $automatic, string $key): ?Document
    {
        $platform = Platform::from((string) $target->getAttribute('platform', ''));

        try {
            $submission = $this->dbForProject->createDocument('distributionSubmissions', new Document([
                '$id' => Id::unique(),
                'appId' => $app->getId(),
                'appInternalId' => $app->getSequence(),
                'releaseId' => $release->getId(),
                'releaseInternalId' => $release->getSequence(),
                'targetId' => $target->getId(),
                'targetInternalId' => $target->getSequence(),
                'artifactId' => $artifact->getId(),
                'artifactInternalId' => $artifact->getSequence(),
                'platform' => $platform->value,
                'provider' => Stores::provider($platform)->value,
                'track' => $track,
                'rollout' => 1.0,
                'automatic' => $automatic,
                'status' => Status::Waiting->value,
                'external' => [],
                'key' => $key,
                'attempt' => 0,
            ]));
        } catch (Duplicate) {
            return null;
        }

        $this->schedules->write($this->project, SCHEDULE_RESOURCE_TYPE_DISTRIBUTION_SUBMISSION, $submission, self::interval(Status::Waiting->value, 1.0));
        $this->publisher->enqueue(new DistributionSubmissionMessage(
            project: $this->project,
            action: DistributionSubmissionAction::Advance,
            id: $submission->getId(),
        ));
        $this->derive($submission, $release);

        return $submission;
    }

    public function validate(Document $submission, Document $target, Document $artifact): Document
    {
        $submission = $this->transition($submission, Status::Validating);

        if ($artifact->getAttribute('status') !== ArtifactStatus::Ready->value) {
            return $this->terminate($submission, Exception::DISTRIBUTION_ARTIFACT_NOT_READY, 'The artifact is not ready to submit.');
        }

        if ($artifact->getAttribute('platform') !== $target->getAttribute('platform') || $artifact->getAttribute('identifier') !== $target->getAttribute('identifier')) {
            return $this->terminate($submission, Exception::DISTRIBUTION_CHECK_FAILED, 'The artifact does not match the platform and identifier of the target.');
        }

        if (empty($target->getAttribute('connectionId'))) {
            return $this->terminate($submission, Exception::DISTRIBUTION_CHECK_FAILED, 'The target has no store connection.');
        }

        return $this->advance($submission, Step::Validated);
    }

    public function locate(Document $submission, Document $target, Document $connection): Document
    {
        if ($this->contended($submission, $connection)) {
            return $submission;
        }

        $app = $this->stores->connection($connection)->app((string) $target->getAttribute('identifier', ''));

        if ($app->id !== $target->getAttribute('storeAppId')) {
            $this->dbForProject->updateDocument('distributionTargets', $target->getId(), new Document([
                'storeAppId' => $app->id,
            ]));
        }

        return $this->advance($submission, Step::Located);
    }

    public function claim(Document $submission): ?Document
    {
        $external = $this->external($submission);

        if (!self::isAt($submission, Step::Located) || !$this->isLive($submission) || self::isClaimed($external)) {
            return null;
        }

        if ($this->contended($submission, $this->connection($submission))) {
            return null;
        }

        $external['claim'] = ['id' => Id::unique(), 'expiresAt' => DateTime::addSeconds(new \DateTime(), self::CLAIM)];

        return $this->transition($submission, Status::Uploading, ['external' => $external]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function upload(Document $submission, Document $artifact, Document $connection): ?array
    {
        $external = $this->external($submission);
        $store = $this->stores->connection($connection);
        $upload = $this->stores->upload($artifact, $this->deviceForDistribution);

        if (empty($external['editId']) && empty($external['checkpoint']) && \ctype_digit($upload->build)) {
            $latest = $store->latestVersionCode($upload->identifier);

            if ($latest >= (int) $upload->build) {
                throw new Exception(Exception::DISTRIBUTION_VERSION_INVALID, "Version code {$upload->build} is not higher than {$latest}, the highest version code the store already has.");
            }
        }

        try {
            return $store->upload($upload, Checkpoint::restore($external), new Checkpoint($this->dbForProject, $submission->getId(), self::claimOf($submission)));
        } catch (Contention) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $result
     */
    public function record(Document $submission, string $claim, array $result): Document
    {
        $external = $this->external($submission);

        if (!self::isAt($submission, Step::Located) || ($external['claim']['id'] ?? null) !== $claim || !$this->isLive($submission)) {
            return $submission;
        }

        unset($external['checkpoint'], $external['claim']);

        return $this->advance($submission, Step::Uploaded, ['external' => [...$external, ...$result]], Status::Processing);
    }

    public function process(Document $submission, Document $connection): Document
    {
        $external = $this->external($submission);
        $processed = $this->stores->connection($connection)->processed($external);

        if ($processed === null) {
            return $submission;
        }

        return $this->advance($submission, Step::Processed, ['external' => [...$external, ...$processed]]);
    }

    public function release(Document $submission, Document $release, Document $target, Document $artifact, Document $connection): Document
    {
        $store = $this->stores->connection($connection);
        $external = $this->external($submission);

        if ($this->isRetried($submission) && $this->isShown($store->state($external, (string) $submission->getAttribute('track', '')))) {
            return $this->committed($submission);
        }

        $external = $store->release($this->stores->release($submission, $release, $target, $artifact), $external);

        return $this->advance($submission, Step::Released, [
            'external' => $external,
            'submittedAt' => DateTime::now(),
        ]);
    }

    public function settle(Document $submission, Document $connection): Document
    {
        return $this->reconcile($submission, $this->releaseOf($submission), $connection);
    }

    public function control(Document $submission, Document $connection, string $status): Document
    {
        if (!\in_array($submission->getAttribute('step'), self::RELEASED, true)) {
            return Status::tryFrom($status) === Status::Canceled ? $this->cancel($submission) : $submission;
        }

        if ($this->contended($submission, $connection)) {
            return $this->defer($submission, $status, $this->attemptOf($submission, $status));
        }

        return $this->command($submission, $this->releaseOf($submission), $connection, $status);
    }

    public function reconcile(Document $submission, Document $release, Document $connection): Document
    {
        if (!$this->isLive($submission)) {
            $this->schedules->stop($this->project, SCHEDULE_RESOURCE_TYPE_DISTRIBUTION_SUBMISSION, $submission->getId());

            return $submission;
        }

        if ($this->contended($submission, $connection)) {
            return $submission;
        }

        $pending = $this->external($submission)['control']['status'] ?? null;

        if (\is_string($pending)) {
            return $this->command($submission, $release, $connection, $pending);
        }

        return $this->observe($submission, $release, $connection);
    }

    public function fail(Document $submission, \Throwable $error): Document
    {
        if (!$this->isLive($submission)) {
            return $submission;
        }

        $codes = $error instanceof StoreException
            ? \array_map(static fn (Issue $issue): string => $issue->code, $error->getIssues())
            : [];

        return match (true) {
            $error instanceof Exception => $this->reject($submission, $error->getType(), $error->getMessage()),
            $error instanceof Unauthorized && ($error->isCredential() || \array_intersect(self::ISSUES_CREDENTIAL, $codes) !== []) => $this->reject($submission, Exception::DISTRIBUTION_CONNECTION_INVALID, $error->getMessage()),
            \in_array(self::ISSUE_VERSION_CONFLICT, $codes, true) => $this->terminate($submission, Exception::DISTRIBUTION_VERSION_INVALID, $error->getMessage()),
            $error instanceof NotFound && \in_array(self::ISSUE_APPLICATION_NOT_FOUND, $codes, true) => $this->terminate($submission, Code::StoreAppMissing->value, $error->getMessage()),
            $error instanceof NotFound && \in_array(self::ISSUE_EDIT_COMMITTED, $codes, true) && $submission->getAttribute('step') === Step::Processed->value => $this->committed($submission),
            $error instanceof NotFound && Stores::isReupload($error) => $this->reupload($submission, $error),
            $error instanceof Conflict && \in_array(self::ISSUE_IN_REVIEW, $codes, true) => $this->review($submission, $error),
            $error instanceof StoreException && !$error instanceof RateLimited && !$error instanceof Unavailable => $this->terminate($submission, $codes[0] ?? self::ERROR_REJECTED, $error->getMessage()),
            default => $this->retry($submission, $error),
        };
    }

    private function command(Document $submission, Document $release, Document $connection, string $status): Document
    {
        $requested = Status::tryFrom($status);
        $store = $this->stores->connection($connection);
        $external = $this->external($submission);
        $track = (string) $submission->getAttribute('track', '');

        try {
            if ($requested === Status::Canceled) {
                $store->withdraw($external);
            } elseif ($requested === Status::Halted) {
                $store->halt($external, $track);
            } elseif ($requested === Status::Published && $submission->getAttribute('status') === Status::Halted->value) {
                $store->resume($external, $track);
            } elseif ($requested === Status::Published) {
                $store->rollout($external, $track, 1.0);
            } else {
                return $this->update($submission, ['external' => $this->withoutControl($submission)]);
            }
        } catch (StoreException $error) {
            return $this->uncontrolled($submission, $status, $this->attemptOf($submission, $status) + 1, $error);
        }

        return $this->observe($submission, $release, $connection, [
            'external' => $this->withoutControl($submission),
            'errorCode' => null,
            'errorMessage' => null,
        ]);
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function observe(Document $submission, Document $release, Document $connection, array $changes = []): Document
    {
        $state = $this->stores->connection($connection)->state($this->external($submission), (string) $submission->getAttribute('track', ''));
        $submission = $this->apply($submission, $state, $changes);
        $this->derive($submission, $release);

        return $submission;
    }

    private function uncontrolled(Document $submission, string $status, int $attempt, StoreException $error): Document
    {
        $codes = \array_map(static fn (Issue $issue): string => $issue->code, $error->getIssues());
        $transient = $error instanceof RateLimited || $error instanceof Unavailable;

        $code = match (true) {
            $transient => Exception::DISTRIBUTION_STORE_UNAVAILABLE,
            $error instanceof Invalid => Exception::DISTRIBUTION_SUBMISSION_TRANSITION_INVALID,
            $error instanceof Unauthorized && ($error->isCredential() || \array_intersect(self::ISSUES_CREDENTIAL, $codes) !== []) => Exception::DISTRIBUTION_CONNECTION_INVALID,
            default => $codes[0] ?? self::ERROR_REJECTED,
        };

        $changes = [
            'errorCode' => \mb_substr($code, 0, 64),
            'errorMessage' => \mb_substr($error->getMessage(), 0, 4096),
        ];

        $delay = $transient && $attempt < self::CONTROL_ATTEMPTS ? self::backoff($attempt) : null;

        if ($delay === null) {
            return $this->update($submission, [...$changes, 'external' => $this->withoutControl($submission), 'retryAt' => null]);
        }

        return $this->defer($submission, $status, $attempt, [...$changes, 'retryAt' => DateTime::addSeconds(new \DateTime(), $delay)]);
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function defer(Document $submission, string $status, int $attempt, array $changes = []): Document
    {
        return $this->update($submission, [
            ...$changes,
            'external' => [...$this->external($submission), 'control' => ['status' => $status, 'attempt' => $attempt]],
        ]);
    }

    private function attemptOf(Document $submission, string $status): int
    {
        $pending = $this->external($submission)['control'] ?? null;

        return \is_array($pending) && ($pending['status'] ?? null) === $status ? (int) ($pending['attempt'] ?? 0) : 0;
    }

    /**
     * @return array<string, mixed>
     */
    private function withoutControl(Document $submission): array
    {
        $external = $this->external($submission);
        unset($external['control']);

        return $external;
    }

    private function cancel(Document $submission): Document
    {
        if (!$this->isLive($submission)) {
            return $submission;
        }

        $external = $this->external($submission);
        unset($external['claim'], $external['control']);

        $submission = $this->transition($submission, Status::Canceled, ['external' => $external]);
        $this->derive($submission);

        return $submission;
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function apply(Document $submission, State $state, array $changes = []): Document
    {
        $from = Status::from((string) $submission->getAttribute('status', Status::Waiting->value));
        $to = $state->status === State::STATUS_REJECTED
            ? (self::REJECTIONS[$state->raw] ?? Status::Rejected)
            : self::STATES[$state->status];

        if ($from !== $to && !$from->canTransition($to)) {
            $to = $from;
        }

        $changes = [
            ...$changes,
            'status' => $to->value,
            'storeStatus' => \mb_substr($state->raw, 0, 64),
            'rollout' => $state->fraction,
            'attempt' => 0,
            'retryAt' => null,
        ];

        $issue = $state->issues[0] ?? null;

        if ($issue !== null || $to !== $from) {
            $changes['errorCode'] = $issue === null ? null : \mb_substr($issue->code, 0, 64);
            $changes['errorMessage'] = $issue === null ? null : \mb_substr($issue->message, 0, 4096);
        }

        if (\in_array($to, [Status::Approved, Status::Rejected], true) && empty($submission->getAttribute('reviewedAt'))) {
            $changes['reviewedAt'] = DateTime::now();
        }

        if ($to === Status::Published && empty($submission->getAttribute('publishedAt'))) {
            $changes['publishedAt'] = DateTime::now();
        }

        if (!self::isActive($to->value, $state->fraction)) {
            $changes['step'] = Step::Settled->value;
        }

        return $this->update($submission, $changes);
    }

    private function isRetried(Document $submission): bool
    {
        return (int) $submission->getAttribute('attempt', 0) > 0 || ($this->external($submission)['committed'] ?? false) === true;
    }

    private function isShown(State $state): bool
    {
        return $state->status !== State::STATUS_PROCESSING && $state->raw !== self::STATE_ABSENT;
    }

    private function committed(Document $submission): Document
    {
        return $this->advance($submission, Step::Released, [
            'external' => [...$this->external($submission), 'committed' => true],
            'submittedAt' => DateTime::now(),
        ]);
    }

    private function reupload(Document $submission, \Throwable $error): Document
    {
        $external = $this->external($submission);
        unset($external['editId'], $external['expiryTimeSeconds'], $external['checkpoint'], $external['committed']);

        return $this->retry($submission, $error, [
            'step' => Step::Located->value,
            'external' => $external,
        ]);
    }

    private function review(Document $submission, \Throwable $error): Document
    {
        $expiry = $this->external($submission)['expiryTimeSeconds'] ?? null;

        if (!\is_int($expiry) || $expiry <= \time()) {
            return $this->terminate($submission, Code::StoreReviewInProgress->value, $error->getMessage());
        }

        return $this->retry($submission, $error);
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function retry(Document $submission, \Throwable $error, array $changes = []): Document
    {
        $attempt = (int) $submission->getAttribute('attempt', 0) + 1;
        $delay = self::backoff($attempt);

        if ($delay === null) {
            return $this->terminate(
                $submission,
                Exception::DISTRIBUTION_STORE_UNAVAILABLE,
                $error instanceof StoreException ? $error->getMessage() : 'The store could not be reached.',
            );
        }

        $external = $changes['external'] ?? $this->external($submission);
        unset($external['claim']);

        return $this->update($submission, [
            ...$changes,
            'external' => $external,
            'attempt' => $attempt,
            'retryAt' => DateTime::addSeconds(new \DateTime(), $delay),
        ]);
    }

    private function reject(Document $submission, string $code, string $message): Document
    {
        if ($code === Exception::DISTRIBUTION_CONNECTION_INVALID) {
            $connection = $this->connection($submission);

            if (!$connection->isEmpty()) {
                $this->dbForProject->updateDocument('distributionConnections', $connection->getId(), new Document([
                    'status' => ConnectionStatus::Invalid->value,
                    'error' => \mb_substr($message, 0, 4096),
                    'checkedAt' => DateTime::now(),
                ]));
            }
        }

        return $this->terminate($submission, $code, $message);
    }

    private function terminate(Document $submission, string $code, string $message): Document
    {
        $external = $this->external($submission);
        unset($external['claim'], $external['control']);

        $submission = $this->transition($submission, Status::Failed, [
            'errorCode' => \mb_substr($code, 0, 64),
            'errorMessage' => \mb_substr($message, 0, 4096),
            'external' => $external,
        ]);
        $this->derive($submission);

        return $submission;
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function advance(Document $submission, Step $step, array $changes = [], ?Status $status = null): Document
    {
        $changes = [...$changes, 'step' => $step->value, 'attempt' => 0, 'retryAt' => null];

        return $status === null ? $this->update($submission, $changes) : $this->transition($submission, $status, $changes);
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function transition(Document $submission, Status $to, array $changes = []): Document
    {
        $from = Status::from((string) $submission->getAttribute('status', Status::Waiting->value));

        if ($from === $to || $from->canTransition($to)) {
            $changes['status'] = $to->value;
        }

        return $this->update($submission, $changes);
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function update(Document $submission, array $changes): Document
    {
        $changes = \array_filter(
            $changes,
            static fn (mixed $value, string $key): bool => $submission->getAttribute($key) !== $value,
            ARRAY_FILTER_USE_BOTH,
        );

        if ($changes === []) {
            return $submission;
        }

        $updated = $this->dbForProject->updateDocument('distributionSubmissions', $submission->getId(), new Document($changes));
        $this->reschedule($submission, $updated);

        return $updated;
    }

    private function reschedule(Document $before, Document $after): void
    {
        $previous = self::interval((string) $before->getAttribute('status', ''), (float) $before->getAttribute('rollout', 1.0));
        $next = self::interval((string) $after->getAttribute('status', ''), (float) $after->getAttribute('rollout', 1.0));

        if ($previous === $next) {
            return;
        }

        if ($next === 0) {
            $this->schedules->stop($this->project, SCHEDULE_RESOURCE_TYPE_DISTRIBUTION_SUBMISSION, $after->getId());

            return;
        }

        $this->schedules->write($this->project, SCHEDULE_RESOURCE_TYPE_DISTRIBUTION_SUBMISSION, $after, $next);
    }

    private function derive(Document $submission, ?Document $release = null): void
    {
        $release ??= $this->releaseOf($submission);

        if ($release->isEmpty()) {
            return;
        }

        $status = ReleaseStatus::derive(\array_values($this->dbForProject->find('distributionSubmissions', [
            Query::equal('releaseInternalId', [$release->getSequence()]),
            Query::select(['status', 'track', 'targetInternalId']),
            Query::limit(APP_LIMIT_SUBQUERY),
        ])));

        if ($status->value !== $release->getAttribute('status')) {
            $this->dbForProject->updateDocument('distributionReleases', $release->getId(), new Document([
                'status' => $status->value,
            ]));
        }
    }

    private function contended(Document $submission, Document $connection): bool
    {
        if (!Stores::isExclusive($connection)) {
            return false;
        }

        $target = $this->dbForProject->getDocument('distributionTargets', (string) $submission->getAttribute('targetId', ''));

        return self::isContended($this->dbForProject, $connection, (string) $target->getAttribute('identifier', ''), $submission->getId());
    }

    private function connection(Document $submission): Document
    {
        $target = $this->dbForProject->getDocument('distributionTargets', (string) $submission->getAttribute('targetId', ''));
        $connectionId = (string) $target->getAttribute('connectionId', '');

        return $connectionId === '' ? new Document() : $this->dbForProject->getDocument('distributionConnections', $connectionId);
    }

    private function releaseOf(Document $submission): Document
    {
        return $this->dbForProject->getDocument('distributionReleases', (string) $submission->getAttribute('releaseId', ''));
    }

    private function isLive(Document $submission): bool
    {
        return self::isActive((string) $submission->getAttribute('status', ''), (float) $submission->getAttribute('rollout', 1.0));
    }

    /**
     * @return array<string, mixed>
     */
    private function external(Document $submission): array
    {
        $external = $submission->getAttribute('external', []);

        return \is_array($external) ? $external : [];
    }

    /**
     * @param array<string, mixed> $external
     */
    public static function isClaimed(array $external): bool
    {
        $expiresAt = $external['claim']['expiresAt'] ?? null;

        return \is_string($expiresAt) && new \DateTime($expiresAt) > new \DateTime();
    }

    /**
     * @param array<string, mixed> $external
     */
    public static function isEditing(array $external): bool
    {
        return !empty($external['checkpoint']) || (!empty($external['editId']) && ($external['committed'] ?? false) !== true);
    }
}

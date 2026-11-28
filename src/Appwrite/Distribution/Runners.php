<?php

namespace Appwrite\Distribution;

use Appwrite\Distribution\Runner\Platform;
use Appwrite\Distribution\Runner\Status;
use Appwrite\Distribution\Runner\Token;
use Appwrite\Extend\Exception;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Id;
use Utopia\Database\Query;
use Utopia\System\System;

/**
 * The macOS hosts that build iOS apps. A runner is enrolled once with the
 * enrolment secret, then authenticates with its own token, of which only the
 * SHA-256 is stored. A runner is available while it has been seen within SEEN.
 */
readonly class Runners
{
    public const int SEEN = 300;

    public const int TOUCH = 30;

    private const int SCAN = 100;

    public function __construct(private Database $dbForPlatform)
    {
    }

    /**
     * Empty without `_APP_OPENSSL_KEY_V1`, which makes every runner token fail to verify.
     */
    public static function secret(): string
    {
        $key = System::getEnv('_APP_OPENSSL_KEY_V1', '');

        return $key === '' ? '' : \hash_hmac('sha256', 'distribution:runner', $key);
    }

    /**
     * @param array<mixed> $labels
     * @return list<string>
     */
    public static function normalize(array $labels): array
    {
        return \array_values(\array_unique(\array_map(strval(...), $labels)));
    }

    /**
     * The token is issued before the runner is stored, so a missing key leaves no runner behind.
     *
     * @param list<string> $labels
     * @return array{runner: Document, token: string}
     * @throws \LogicException When `_APP_OPENSSL_KEY_V1` is empty
     */
    public function enrol(string $name, Platform $platform, array $labels, int $capacity, string $version): array
    {
        $runnerId = Id::unique();
        $token = Token::issue($runnerId, self::secret());

        $runner = $this->dbForPlatform->createDocument('distributionRunners', new Document([
            '$id' => $runnerId,
            'name' => $name,
            'platform' => $platform->value,
            'labels' => self::normalize($labels),
            'secret' => Leases::hash($token),
            'status' => Status::Online->value,
            'version' => $version,
            'capacity' => $capacity,
            'region' => System::getEnv('_APP_REGION', 'default'),
            'seenAt' => DateTime::now(),
        ]));

        return ['runner' => $runner, 'token' => $token];
    }

    /**
     * The runner a verified token names, as long as the token is the one its enrolment issued.
     *
     * @throws Exception When the runner is missing, or the token is not its current one
     */
    public function held(Token $verified, #[\SensitiveParameter] string $token): Document
    {
        $runner = $this->dbForPlatform->getDocument('distributionRunners', $verified->runnerId);
        if ($runner->isEmpty()) {
            throw new Exception(Exception::DISTRIBUTION_RUNNER_NOT_FOUND);
        }

        if (!\hash_equals((string) $runner->getAttribute('secret', ''), Leases::hash($token))) {
            throw new Exception(Exception::USER_UNAUTHORIZED);
        }

        return $runner;
    }

    /**
     * @param list<string>|null $labels
     */
    public function beat(Document $runner, Status $status, ?string $version = null, ?array $labels = null): Document
    {
        $changes = [
            'status' => $status->value,
            'seenAt' => DateTime::now(),
        ];

        if ($version !== null) {
            $changes['version'] = $version;
        }

        if ($labels !== null) {
            $changes['labels'] = self::normalize($labels);
        }

        return $this->dbForPlatform->updateDocument('distributionRunners', $runner->getId(), new Document($changes));
    }

    /**
     * Marks the runner seen at most every TOUCH seconds, so claims do not write on every poll.
     */
    public function touch(Document $runner, int $now): Document
    {
        if (Leases::timestamp($runner->getAttribute('seenAt')) + self::TOUCH > $now) {
            return $runner;
        }

        return $this->dbForPlatform->updateDocument('distributionRunners', $runner->getId(), new Document([
            'seenAt' => DateTime::format((new \DateTime())->setTimestamp($now)),
        ]));
    }

    /**
     * Whether a runner of the platform with every label has been seen within SEEN.
     * Draining runners count: they finish their leases and come back.
     *
     * @param list<string> $labels
     */
    public function isAvailable(Platform $platform, array $labels, int $now): bool
    {
        $runners = $this->dbForPlatform->find('distributionRunners', [
            Query::equal('platform', [$platform->value]),
            Query::equal('status', [Status::Online->value, Status::Draining->value]),
            Query::greaterThanEqual('seenAt', DateTime::format((new \DateTime())->setTimestamp($now - self::SEEN))),
            Query::limit(self::SCAN),
        ]);

        return \array_any($runners, fn (Document $runner): bool => Leases::isSubset($labels, $runner->getAttribute('labels', [])));
    }
}

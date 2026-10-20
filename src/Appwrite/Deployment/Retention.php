<?php

namespace Appwrite\Deployment;

use Appwrite\Deployment\Retention\Pass;
use Appwrite\Deployment\Retention\Reason;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Query;

readonly class Retention
{
    /**
     * Deployment statuses a build never leaves; the count cap counts and retires only these.
     */
    public const array FINISHED_STATUSES = ['ready', 'failed', 'canceled'];

    public function __construct(
        public int $days = 0,
        public int $maxDays = 0,
        public int $maxCount = 0,
    ) {
        if ($days < 0 || $maxDays < 0 || $maxCount < 0) {
            throw new \InvalidArgumentException('Deployment retention values must not be negative.');
        }
    }

    /**
     * Days a resource keeps non-active deployments: the requested days, else the default, clamped to the maximum. 0 keeps them forever.
     */
    public function resolve(int $requested): int
    {
        $days = $requested > 0 ? $requested : $this->days;

        return $this->maxDays > 0 && ($days === 0 || $days > $this->maxDays) ? $this->maxDays : $days;
    }

    /**
     * The passes that retire a resource's deployments, each selecting deployments no other pass selects. The
     * resource queries narrow every pass to that resource's deployments that may retire at all.
     *
     * Age passes come first. The count cutoff is only looked up once they have been consumed, so it sees what they
     * left behind; a failed lookup throws from the iteration after the age passes were handed out.
     *
     * @param list<Query> $resource
     * @return \Generator<int, Pass>
     */
    public function passes(Database $dbForProject, array $resource, int $requested, \DateTime $now): \Generator
    {
        yield from $this->ages($requested, $now);

        if ($this->maxCount === 0) {
            return;
        }

        $cutoff = $dbForProject->find('deployments', [...$resource, ...$this->cutoff()]);
        if (empty($cutoff)) {
            return;
        }

        yield $this->count($cutoff[0], $requested, $now);
    }

    /**
     * The age passes for a resource that requested these days. Deployments past the request retire for it; a
     * clamped request or a default gets its own pass, bounded at the request cutoff, so no deployment is in both.
     *
     * @return list<Pass>
     */
    private function ages(int $requested, \DateTime $now): array
    {
        $passes = [];
        $requestCutoff = $requested > 0 ? DateTime::addSeconds(clone $now, -86400 * $requested) : null;

        if ($requestCutoff !== null) {
            $passes[] = new Pass(Reason::Requested, [
                Query::createdBefore($requestCutoff),
                Query::orderDesc('$createdAt'),
            ]);
        }

        $days = $this->resolve($requested);
        if ($days > 0 && $days !== $requested) {
            $passes[] = new Pass(Reason::Policy, [
                Query::createdBefore(DateTime::addSeconds(clone $now, -86400 * $days)),
                ...($requestCutoff !== null ? [Query::greaterThanEqual('$createdAt', $requestCutoff)] : []),
                Query::orderDesc('$createdAt'),
            ]);
        }

        return $passes;
    }

    /**
     * Selects the newest finished deployment past the count cap.
     *
     * @return list<Query>
     */
    private function cutoff(): array
    {
        return [
            Query::equal('status', self::FINISHED_STATUSES),
            Query::orderDesc('$createdAt'),
            Query::orderDesc('$sequence'),
            Query::limit(1),
            Query::offset($this->maxCount),
        ];
    }

    /**
     * The count pass from the cutoff deployment: it and every older finished deployment the age passes leave behind.
     * A build in progress neither takes a slot nor is retired.
     */
    private function count(Document $cutoff, int $requested, \DateTime $now): Pass
    {
        $days = $this->resolve($requested);
        $createdAt = $cutoff->getCreatedAt();

        return new Pass(Reason::Count, [
            Query::equal('status', self::FINISHED_STATUSES),
            Query::or([
                Query::lessThan('$createdAt', $createdAt),
                Query::and([
                    Query::equal('$createdAt', [$createdAt]),
                    Query::lessThanEqual('$sequence', $cutoff->getSequence()),
                ]),
            ]),
            ...($days > 0 ? [Query::greaterThanEqual('$createdAt', DateTime::addSeconds(clone $now, -86400 * $days))] : []),
            Query::orderDesc('$createdAt'),
        ]);
    }
}

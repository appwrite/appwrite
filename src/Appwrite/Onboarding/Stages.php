<?php

namespace Appwrite\Onboarding;

use Appwrite\Locking\Lock;
use Closure;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;

final readonly class Stages
{
    private const string COLLECTION = 'projects';

    private const string ATTRIBUTE = 'onboarding';

    public function __construct(
        private Database $dbForPlatform,
        private Authorization $authorization,
        private Lock $lock,
    ) {
    }

    /**
     * @param array<string> $methods
     */
    public function complete(Document $project, array $methods, string $actorType): void
    {
        $known = $this->stages($project);
        $pending = \array_values(\array_filter(
            $methods,
            fn (string $method): bool => $this->status($known, $method) !== ONBOARDING_STATUS_COMPLETED,
        ));

        if ($pending === []) {
            return;
        }

        $this->update($project, function (array $stages) use ($pending, $actorType): array {
            $now = DateTime::now();

            foreach ($pending as $method) {
                if ($this->status($stages, $method) === ONBOARDING_STATUS_COMPLETED) {
                    continue;
                }

                $stages[$method] = $this->row(ONBOARDING_STATUS_COMPLETED, $now, $actorType);
            }

            return $stages;
        });
    }

    /**
     * @return array<string, mixed>|null the stage as stored after the skip
     */
    public function skip(Document $project, string $method, string $actorType): ?array
    {
        $stages = $this->update($project, function (array $stages) use ($method, $actorType): array {
            if ($this->status($stages, $method) !== ONBOARDING_STATUS_COMPLETED) {
                $stages[$method] = $this->row(ONBOARDING_STATUS_SKIPPED, DateTime::now(), $actorType);
            }

            return $stages;
        });

        return \is_array($stages[$method] ?? null) ? $stages[$method] : null;
    }

    /**
     * @param Closure(array<string, mixed>): array<string, mixed> $change
     * @return array<string, mixed>
     */
    private function update(Document $project, Closure $change): array
    {
        return $this->lock->withKey(
            'lock:platform:' . $project->getSequence() . ':' . self::ATTRIBUTE,
            function () use ($project, $change): array {
                $stored = $this->stages($this->read($project->getId()));
                $stages = $change($stored);

                if ($stages !== $stored) {
                    $this->write($project->getId(), $stages);
                }

                return $stages;
            },
            target: self::COLLECTION,
        );
    }

    private function read(string $projectId): Document
    {
        return $this->authorization->skip(fn () => $this->dbForPlatform->skipFilters(
            fn () => $this->dbForPlatform->getDocument(self::COLLECTION, $projectId, forUpdate: true),
            APP_PROJECTS_SUBQUERIES,
        ));
    }

    /**
     * @param array<string, mixed> $stages
     */
    private function write(string $projectId, array $stages): void
    {
        $this->authorization->skip(fn () => $this->dbForPlatform->skipFilters(
            fn () => $this->dbForPlatform->updateDocument(self::COLLECTION, $projectId, new Document([
                self::ATTRIBUTE => $stages,
            ])),
            APP_PROJECTS_SUBQUERIES,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function stages(Document $project): array
    {
        $stages = $project->getAttribute(self::ATTRIBUTE, []);

        return \is_array($stages) ? $stages : [];
    }

    /**
     * @param array<string, mixed> $stages
     */
    private function status(array $stages, string $method): ?string
    {
        $row = $stages[$method] ?? null;

        return \is_array($row) ? ($row['status'] ?? null) : null;
    }

    /**
     * @return array{status: string, at: string, actorType: string}
     */
    private function row(string $status, string $at, string $actorType): array
    {
        return [
            'status' => $status,
            'at' => $at,
            'actorType' => $actorType,
        ];
    }
}

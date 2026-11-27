<?php

declare(strict_types=1);

namespace Tests\Unit\Distribution\Fakes;

use Appwrite\Distribution\Checks;
use Appwrite\Distribution\Stores;
use Utopia\Database\Document;
use Utopia\Distribution\Tests\Transport;

/**
 * Preflight that answers with fixed issues, or throws, and records the track it was asked about.
 */
final readonly class ScriptedChecks extends Checks
{
    /**
     * @param list<array{code: string, severity: string, platform: string, field: string, message: string, remediation: string, manualActionRequired: bool}> $issues
     * @param \ArrayObject<int, array{string, bool}> $calls each [track, store]
     */
    public function __construct(private array $issues = [], private ?\Throwable $failure = null, public \ArrayObject $calls = new \ArrayObject())
    {
        parent::__construct(new MemoryDatabase(), new Stores(production: false, client: new Transport()));
    }

    #[\Override]
    public function run(Document $app, Document $target, string $track, ?Document $artifact = null, bool $store = true): array
    {
        $this->calls->append([$track, $store]);

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return ['ready' => true, 'issues' => $this->issues];
    }
}

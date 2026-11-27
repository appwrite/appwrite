<?php

declare(strict_types=1);

namespace Tests\Unit\Distribution\Fakes;

use Utopia\Distribution\Account;
use Utopia\Distribution\App;
use Utopia\Distribution\Progress;
use Utopia\Distribution\Release;
use Utopia\Distribution\State;
use Utopia\Distribution\Store;
use Utopia\Distribution\Tests\Transport;
use Utopia\Distribution\Upload;

final class ScriptedStore extends Store
{
    /**
     * @var list<string>
     */
    public array $calls = [];

    /**
     * @var array<string, \Throwable>
     */
    public array $failures = [];

    public int $latest = 0;

    /**
     * @var list<array<string, mixed>>
     */
    public array $progress = [];

    /**
     * @var array<string, mixed>
     */
    public array $uploaded = [
        'packageName' => 'io.example.app',
        'editId' => 'edit-1',
        'versionCode' => 42,
        'sha256' => 'digest',
    ];

    /**
     * @var array<string, mixed>
     */
    public array $promoted = [
        'packageName' => 'io.example.app',
        'editId' => 'edit-promoted',
        'versionCode' => 88,
    ];

    /**
     * @var list<array{identifier: string, build: string}>
     */
    public array $promotions = [];

    /**
     * @var array<string, mixed>|null
     */
    public ?array $processed = [];

    public State $state;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $checkpoint = null;

    public ?Upload $upload = null;

    public ?Release $release = null;

    /**
     * @var list<array{external: array<string, mixed>, track: string, fraction: float}>
     */
    public array $rollouts = [];

    public function __construct()
    {
        parent::__construct(new Transport(), 'https://store.test');

        $this->state = new State(State::STATUS_REVIEWING, 'IN_REVIEW');
    }

    #[\Override]
    public function getName(): string
    {
        return 'scripted';
    }

    #[\Override]
    public function account(): Account
    {
        $this->call('account');

        return new Account('scripted');
    }

    #[\Override]
    public function app(string $identifier): App
    {
        $this->call('app');

        return new App('store-app', $identifier);
    }

    #[\Override]
    public function latestVersionCode(string $identifier): int
    {
        $this->call('latestVersionCode');

        return $this->latest;
    }

    #[\Override]
    public function upload(Upload $upload, ?array $checkpoint, ?Progress $progress = null): array
    {
        $this->upload = $upload;
        $this->checkpoint = $checkpoint;

        foreach ($this->progress as $saved) {
            $progress?->save($saved);
        }

        $this->call('upload');

        return $this->uploaded;
    }

    #[\Override]
    public function promote(string $identifier, string $build): array
    {
        $this->promotions[] = ['identifier' => $identifier, 'build' => $build];
        $this->call('promote');

        return $this->promoted;
    }

    #[\Override]
    public function processed(array $external): ?array
    {
        $this->call('processed');

        return $this->processed;
    }

    #[\Override]
    public function release(Release $release, array $external): array
    {
        $this->release = $release;
        $this->call('release');

        return [...$external, 'track' => $release->track, 'committed' => true];
    }

    #[\Override]
    public function state(array $external, string $track): State
    {
        $this->call('state');

        return $this->state;
    }

    #[\Override]
    public function rollout(array $external, string $track, float $fraction): void
    {
        $this->rollouts[] = ['external' => $external, 'track' => $track, 'fraction' => $fraction];
        $this->call('rollout');
    }

    #[\Override]
    public function halt(array $external, string $track): void
    {
        $this->call('halt');
    }

    #[\Override]
    public function resume(array $external, string $track): void
    {
        $this->call('resume');
    }

    #[\Override]
    public function withdraw(array $external): void
    {
        $this->call('withdraw');
    }

    private function call(string $operation): void
    {
        $this->calls[] = $operation;

        if (isset($this->failures[$operation])) {
            throw $this->failures[$operation];
        }
    }
}

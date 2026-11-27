<?php

declare(strict_types=1);

namespace Tests\Unit\Distribution\Fakes;

use Utopia\Distribution\Account;
use Utopia\Distribution\App;
use Utopia\Distribution\Listing;
use Utopia\Distribution\Release;
use Utopia\Distribution\State;
use Utopia\Distribution\Store;
use Utopia\Distribution\Tests\Transport;

final class ListingStore extends Store
{
    /**
     * @var list<string>
     */
    public array $calls = [];

    /**
     * @var list<array{listing: Listing, external: array<string, mixed>}>
     */
    public array $listings = [];

    /**
     * @var list<array<string, mixed>> consumed one per listing() call; once empty, listing() answers like an open Google edit
     */
    public array $results = [];

    /**
     * @var array<string, \Throwable>
     */
    public array $failures = [];

    public State $state;

    public ?Release $release = null;

    public function __construct()
    {
        parent::__construct(new Transport(), 'https://store.test');

        $this->state = new State(State::STATUS_REVIEWING, 'IN_REVIEW');
    }

    #[\Override]
    public function getName(): string
    {
        return 'listing';
    }

    #[\Override]
    public function account(): Account
    {
        $this->call('account');

        return new Account('listing');
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

        return 0;
    }

    #[\Override]
    public function processed(array $external): array
    {
        $this->call('processed');

        return [];
    }

    #[\Override]
    public function listing(Listing $listing, array $external): array
    {
        $this->listings[] = ['listing' => $listing, 'external' => $external];
        $this->call('listing');

        return \array_shift($this->results) ?? [...$external, 'committed' => false];
    }

    #[\Override]
    public function release(Release $release, array $external): array
    {
        $this->release = $release;
        $this->call('release');

        return [...$external, 'committed' => true];
    }

    #[\Override]
    public function state(array $external, string $track): State
    {
        $this->call('state');

        return $this->state;
    }

    private function call(string $operation): void
    {
        $this->calls[] = $operation;

        if (isset($this->failures[$operation])) {
            throw $this->failures[$operation];
        }
    }
}

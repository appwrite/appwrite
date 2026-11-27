<?php

declare(strict_types=1);

namespace Tests\Unit\Distribution\Fakes;

use Appwrite\Distribution\Stores;
use Utopia\Database\Document;
use Utopia\Distribution\Store;
use Utopia\Distribution\Tests\Transport;

final readonly class ScriptedStores extends Stores
{
    public function __construct(private Store $store)
    {
        parent::__construct(production: false, client: new Transport());
    }

    #[\Override]
    public function connection(Document $connection): Store
    {
        return $this->store;
    }
}

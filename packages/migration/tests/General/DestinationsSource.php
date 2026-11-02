<?php

namespace Utopia\Migration\Tests\General;

use Utopia\Migration\Sources\Appwrite;
use Utopia\Migration\Sources\Appwrite\Client;

final class DestinationsSource extends Appwrite
{
    public function sdk(): Client
    {
        return $this->client;
    }
}

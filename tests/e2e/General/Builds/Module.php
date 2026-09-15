<?php

declare(strict_types=1);

namespace Tests\E2E\General\Builds;

use Appwrite\Platform\Modules\Functions\Services\Workers;

final class Module extends \Utopia\Platform\Module
{
    public function __construct()
    {
        $this->addService('workers', new Workers());
    }
}

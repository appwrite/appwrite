<?php

declare(strict_types=1);

namespace Utopia\Tests;

use Utopia\Platform\Action;

class TestActionWorkerStart extends Action
{
    public bool $invoked = false;

    public function __construct()
    {
        $this->type = Action::TYPE_WORKER_START;
        $this->groups(['test']);
        $this->callback(function (): void {
            $this->action();
        });
    }

    public function action(): void
    {
        $this->invoked = true;
    }
}

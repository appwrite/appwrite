<?php

declare(strict_types=1);

namespace Utopia\Platform\Tests;

use Utopia\Platform\Action;

class TestActionWorkerStop extends Action
{
    public bool $invoked = false;

    public function __construct()
    {
        $this->type = Action::TYPE_WORKER_STOP;
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

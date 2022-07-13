<?php
namespace Appwrite\Task;

use Utopia\Platform\Service;

class Tasks extends Service {
    public function __construct()
    {
        $this->type = self::TYPE_CLI;
        $this->addAction('version', new Version());
        $this->addAction('usage', new Usage());
        $this->addAction('vars', new Vars());
    }
}

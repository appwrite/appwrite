<?php

namespace Appwrite\Platform\Modules\Functions\Workers\Builds\Actions;

use Exception;
use Utopia\Platform\Action;

class Shutdown extends Action
{
    public static function getName(): string
    {
        return 'builds-shutdown';
    }

    /**
     * @throws Exception
     */
    public function __construct()
    {
        $this->setType(self::TYPE_SHUTDOWN);
        $this
            ->desc('Builds worker shutdown hook')
            ->groups(['builds'])
            ->inject('buildStartTime')
            ->callback($this->action(...));
    }

    public function action(float $startTime): void
    {
        \var_dump("Finished at {$startTime}");
        \var_dump("Finished in " . (microtime(true) - $startTime) . " seconds");
    }
}

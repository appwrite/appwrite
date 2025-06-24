<?php

namespace Appwrite\Platform\Modules\Functions\Workers\Builds\Actions;

use Exception;
use Utopia\Platform\Action;

class Init extends Action
{
    public static function getName(): string
    {
        return 'builds-init';
    }

    /**
     * @throws Exception
     */
    public function __construct()
    {
        $this->setType(self::TYPE_INIT);
        $this
            ->desc('Builds worker init hook')
            ->groups(['builds'])
            ->inject('buildStartTime')
            ->callback($this->action(...));
    }

    public function action(float $startTime): void
    {
        \var_dump("Started at {$startTime}");
    }
}

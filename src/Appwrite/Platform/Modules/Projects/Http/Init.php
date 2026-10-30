<?php

namespace Appwrite\Platform\Modules\Projects\Http;

use Appwrite\Extend\Exception;
use Utopia\Database\Document;
use Utopia\Platform\Action;

class Init extends Action
{
    public static function getName(): string
    {
        return 'init';
    }

    public function __construct()
    {
        $this
            ->setType(Action::TYPE_INIT)
            ->groups(['projects'])
            ->inject('project')
            ->callback($this->action(...));
    }

    public function action(Document $project): void
    {
        if ($project->getId() !== 'console') {
            throw new Exception(Exception::GENERAL_ACCESS_FORBIDDEN);
        }
    }
}

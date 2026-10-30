<?php

namespace Appwrite\Platform\Modules\GraphQL\Http;

use Appwrite\GraphQL\Schema;
use Utopia\Database\Document;
use Utopia\Platform\Action;

class Shutdown extends Action
{
    public static function getName(): string
    {
        return 'shutdown';
    }

    public function __construct()
    {
        $this
            ->setType(Action::TYPE_SHUTDOWN)
            ->groups(['schema'])
            ->inject('project')
            ->callback($this->action(...));
    }

    public function action(Document $project): void
    {
        Schema::setDirty($project->getId());
    }
}

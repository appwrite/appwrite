<?php

namespace Appwrite\Platform\Modules\Videos\Http;

use Appwrite\Extend\Exception;
use Utopia\Database\Document;
use Utopia\Http\Route;
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
            ->groups(['videos'])
            ->inject('route')
            ->inject('project')
            ->callback($this->action(...));
    }

    /**
     * A request without a project resolves to the console, whose database has
     * no videos collections. Refuse it here instead of failing the lookup.
     */
    public function action(Route $route, Document $project): void
    {
        if ($project->getId() !== 'console') {
            return;
        }

        $path = $route->getPath();

        // Codecs are instance configuration, not project data.
        if ($path === '/v1/videos/codecs') {
            return;
        }

        if (\str_contains($path, ':videoId')) {
            throw new Exception(Exception::VIDEO_NOT_FOUND);
        }

        throw new Exception(Exception::GENERAL_ACCESS_FORBIDDEN);
    }
}

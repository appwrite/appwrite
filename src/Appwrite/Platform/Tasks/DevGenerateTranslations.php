<?php

namespace Appwrite\Platform\Tasks;

use Utopia\CLI\Console;
use Utopia\Platform\Action;

class DevGenerateTranslations extends Action
{
    public static function getName(): string
    {
        return 'dev-generate-translations';
    }

    public function __construct()
    {
        $this
            ->desc('Generate translations in all languages')
            ->callback(fn () => $this->action());
    }

    public function action(): void
    {
        Console::info("Empty command");
    }
}

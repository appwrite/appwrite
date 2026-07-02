<?php

namespace Appwrite\Migration\Version;

use Throwable;
use Utopia\Console;

class V25 extends V24
{
    /**
     * @throws Throwable
     */
    public function execute(): void
    {
        parent::execute();

        if ($this->project->getSequence() !== 'console') {
            return;
        }

        Console::info('Migrating VCS installation tokens');

        foreach (['personalAccessToken', 'personalRefreshToken'] as $attribute) {
            try {
                $this->dbForProject->updateAttribute('installations', $attribute, size: 2048);
            } catch (Throwable $th) {
                Console::warning("Failed to resize attribute \"{$attribute}\" in collection installations: {$th->getMessage()}");
            }
        }
    }
}

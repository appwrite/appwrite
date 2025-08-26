<?php

namespace Appwrite\Platform\Modules\Databases\Http\Databases;

use Utopia\Platform\Action as UtopiaAction;

class Action extends UtopiaAction
{
    private string $context = 'legacy';

    public function getDatabaseType(): string
    {
        return $this->context;
    }

    public function setHttpPath(string $path): UtopiaAction
    {
        if (\str_contains($path, '/tablesdb')) {
            $this->context = 'tablesdb';
        }
        return parent::setHttpPath($path);
    }
}

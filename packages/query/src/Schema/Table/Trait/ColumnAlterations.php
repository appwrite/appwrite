<?php

namespace Utopia\Query\Schema\Table\Trait;

use Utopia\Query\Schema\RenameColumn;

/**
 * Dropping and renaming columns through ALTER. MongoDB has no schema-level
 * equivalent -- documents are reshaped with the $unset and $rename update
 * operators instead -- so its Table does not use this trait.
 */
trait ColumnAlterations
{
    public function renameColumn(string $from, string $to): static
    {
        $this->renameColumns[] = new RenameColumn($from, $to);

        return $this;
    }

    public function dropColumn(string $name): static
    {
        $this->dropColumns[] = $name;

        return $this;
    }
}

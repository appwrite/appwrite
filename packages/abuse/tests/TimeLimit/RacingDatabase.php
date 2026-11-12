<?php

declare(strict_types=1);

namespace Utopia\Abuse\Tests\TimeLimit;

use Utopia\Database\Database;
use Utopia\Database\Document;

/**
 * Runs a competing request once, just before the next increment reaches storage.
 */
final class RacingDatabase extends Database
{
    public ?\Closure $race = null;

    #[\Override]
    public function increaseDocumentAttribute(
        string $collection,
        string $id,
        string $attribute,
        int|float|string $value = 1,
        int|float|string|null $max = null
    ): Document {
        $race = $this->race;
        $this->race = null;
        if ($race instanceof \Closure) {
            $race();
        }

        return parent::increaseDocumentAttribute($collection, $id, $attribute, $value, $max);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Modules\Databases\Http;

use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Documents\Update;
use Utopia\Database\Database;

final class DocumentsWriteAction extends Update
{
    /**
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    public function strip(array $document): array
    {
        $stripped = $this->removeReadonlyAttributes($document);

        return \is_array($stripped) ? $stripped : $stripped->getArrayCopy();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function timestamps(array $data, Database $database): void
    {
        $this->validateTimestamps($data, $database);
    }
}

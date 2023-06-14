<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class ImportValidationError extends Any
{
    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Migration Validiation Error';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_IMPORT_VALIDATION_ERROR;
    }
}

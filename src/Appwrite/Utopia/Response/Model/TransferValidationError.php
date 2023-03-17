<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class TransferValidationError extends Any
{
    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Transfer Validiation Error';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_TRANSFER_VALIDATION_ERROR;
    }
}

<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class BulkOperation extends Model
{
    public function __construct()
    {
        $this
            ->addRule('modified', [
                'type' => self::TYPE_INTEGER,
                'description' => 'How many document were modified in this request.',
                'default' => 0,
                'example' => 10,
            ]);
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'BulkOperation';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_BULK_OPERATION;
    }
}

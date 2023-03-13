<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class SmsTemplate extends Model
{
    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'SmsTemplate';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_SMS_TEMPLATE;
    }
}

<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

use Utopia\Database\Database;

class ProjectTemplates extends BaseInMemory
{
    public const ALLOWED_ATTRIBUTES = [
        'type' => Database::VAR_STRING,
        'locale' => Database::VAR_STRING,
        'subject' => Database::VAR_STRING,
        'message' => Database::VAR_STRING,
        'senderName' => Database::VAR_STRING,
        'senderEmail' => Database::VAR_STRING,
        'replyTo' => Database::VAR_STRING,
        'custom' => Database::VAR_BOOLEAN,
    ];

    public function __construct()
    {
        parent::__construct(self::ALLOWED_ATTRIBUTES);
    }
}

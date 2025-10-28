<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class TemplateEmail extends Template
{
    public function __construct()
    {
        parent::__construct();
        $this
            ->addRule('senderName', [
                'type' => self::TYPE_STRING,
                'description' => 'Name of the sender',
                'default' => '',
                'example' => 'My User',
            ])
            ->addRule('senderEmail', [
                'type' => self::TYPE_STRING,
                'description' => 'Email of the sender',
                'default' => '',
                'example' => 'mail@appwrite.io',
            ])
            ->addRule('replyTo', [
                'type' => self::TYPE_STRING,
                'description' => 'Reply to email address',
                'default' => '',
                'example' => 'emails@appwrite.io',
            ])
            ->addRule('subject', [
                'type' => self::TYPE_STRING,
                'description' => 'Email subject',
                'default' => '',
                'example' => 'Please verify your email address',
            ])
        ;
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'EmailTemplate';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_EMAIL_TEMPLATE;
    }
}

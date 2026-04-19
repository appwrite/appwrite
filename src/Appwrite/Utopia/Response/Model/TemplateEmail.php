<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class TemplateEmail extends Model
{
    public function __construct()
    {
        $this
            ->addRule('templateId', [
                'type' => self::TYPE_STRING,
                'description' => 'Template type',
                'default' => '',
                'example' => 'verification',
            ])
            ->addRule('locale', [
                'type' => self::TYPE_STRING,
                'description' => 'Template locale',
                'default' => '',
                'example' => 'en_us',
            ])
            ->addRule('message', [
                'type' => self::TYPE_STRING,
                'description' => 'Template message',
                'default' => '',
                'example' => 'Click on the link to verify your account.',
            ])
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
            ->addRule('custom', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Whether the template has been customized for the project. Non-custom templates render from defaults.',
                'default' => false,
                'example' => false,
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

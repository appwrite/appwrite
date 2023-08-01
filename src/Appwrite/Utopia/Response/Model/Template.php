<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response\Model;

abstract class Template extends Model
{
    public function __construct()
    {
        $this
            ->addRule('type', [
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
        ;
    }
}

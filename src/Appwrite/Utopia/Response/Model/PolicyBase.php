<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response\Model;

abstract class PolicyBase extends Model
{
    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'Policy ID.',
                'default' => '',
                'example' => 'password-dictionary',
            ]);
    }
}

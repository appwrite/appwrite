<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class Subscriber extends Model
{
  /**
   * @var bool
   */
    protected bool $public = false;

    public function __construct()
    {
        $this
        ->addRule('$id', [
        'type' => self::TYPE_STRING,
        'description' => 'Subscriber ID.',
        'default' => '',
        'example' => '259125845563242502',
        ])
        ->addRule('userId', [
        'type' => self::TYPE_STRING,
        'description' => 'User ID.',
        'default' => '',
        'example' => '259125845563242502',
        ])
        ->addRule('targetId', [
        'type' => self::TYPE_STRING,
        'description' => 'Target ID.',
        'default' => '',
        'example' => '259125845563242502',
        ])
        ->addRule('topicId', [
        'type' => self::TYPE_STRING,
        'description' => 'Topic ID.',
        'default' => '',
        'example' => '259125845563242502',
        ]);
    }

  /**
   * Get Name
   *
   * @return string
   */
    public function getName(): string
    {
        return 'Subscriber';
    }

  /**
   * Get Type
   *
   * @return string
   */
    public function getType(): string
    {
        return Response::MODEL_SUBSCRIBER;
    }
}

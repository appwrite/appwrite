<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class Topic extends Model
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
        'description' => 'Topic ID.',
        'default' => '',
        'example' => '259125845563242502',
      ])
      ->addRule('providerId', [
        'type' => self::TYPE_STRING,
        'description' => 'Provider ID.',
        'default' => '',
        'example' => '259125845563242502',
      ])
      ->addRule('name', [
        'type' => self::TYPE_STRING,
        'description' => 'The name of the topic.',
        'default' => '',
        'example' => 'events',
      ])
      ->addRule('description', [
        'type' => self::TYPE_STRING,
        'description' => 'Description of the topic.',
        'default' => '',
        'required' => false,
        'example' => 'All events related messages will be sent to this topic.',
      ]);
  }

  /**
   * Get Name
   *
   * @return string
   */
  public function getName(): string
  {
    return 'Topic';
  }

  /**
   * Get Type
   *
   * @return string
   */
  public function getType(): string
  {
    return Response::MODEL_TOPIC;
  }
}

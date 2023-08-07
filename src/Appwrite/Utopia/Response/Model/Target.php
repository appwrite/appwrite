<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class Target extends Model
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
        'description' => 'Target ID.',
        'default' => '',
        'example' => '259125845563242502',
      ])
      ->addRule('userId', [
        'type' => self::TYPE_STRING,
        'description' => 'User ID.',
        'default' => '',
        'example' => '259125845563242502',
      ])
      ->addRule('providerId', [
        'type' => self::TYPE_STRING,
        'description' => 'Provider ID.',
        'required' => false,
        'default' => '',
        'example' => '259125845563242502',
      ])
      ->addRule('providerType', [
        'type' => self::TYPE_STRING,
        'description' => 'The type of provider supported by this target.',
        'default' => '',
        'example' => 'sms',
      ])
      ->addRule('identifier', [
        'type' => self::TYPE_STRING,
        'description' => 'The target identifier.',
        'default' => '',
        'example' => 'token',
      ]);
  }

  /**
   * Get Name
   *
   * @return string
   */
  public function getName(): string
  {
    return 'Target';
  }

  /**
   * Get Type
   *
   * @return string
   */
  public function getType(): string
  {
    return Response::MODEL_TARGET;
  }
}

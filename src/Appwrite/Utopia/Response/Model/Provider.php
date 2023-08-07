<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class Provider extends Model
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
        'description' => 'Provider ID.',
        'default' => '',
        'example' => '5e5ea5c16897e',
      ])
      ->addRule('name', [
        'type' => self::TYPE_STRING,
        'description' => 'The user-given name for the provider instance.',
        'default' => '',
        'example' => 'Mailgun',
      ])
      ->addRule('provider', [
        'type' => self::TYPE_STRING,
        'description' => 'Provider name setup in Utopia.',
        'default' => '',
        'example' => 'mailgun',
      ])
      ->addRule('type', [
        'type' => self::TYPE_STRING,
        'description' => 'Type of provider.',
        'default' => '',
        'example' => 'sms',
      ]);
  }

  /**
   * Get Name
   *
   * @return string
   */
  public function getName(): string
  {
    return 'Provider';
  }

  /**
   * Get Type
   *
   * @return string
   */
  public function getType(): string
  {
    return Response::MODEL_PROVIDER;
  }
}

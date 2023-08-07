<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;
use Utopia\Database\DateTime;

class Message extends Model
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
        'description' => 'Message ID.',
        'default' => '',
        'example' => '5e5ea5c16897e',
        ])
        ->addRule('providerId', [
        'type' => self::TYPE_STRING,
        'description' => 'Provider Id for the message.',
        'default' => '',
        'example' => '5e5ea5c16897e',
        ])
        ->addRule('data', [
        'type' => self::TYPE_JSON,
        'description' => 'Message Data.',
        'default' => '',
        'required' => false,
        'example' => '',
        ])
        ->addRule('to', [
        'type' => self::TYPE_STRING,
        'description' => 'Recipient of message.',
        'default' => '',
        'example' => ['user-1'],
        ])
        ->addRule('deliveryTime', [
        'type' => self::TYPE_DATETIME,
        'description' => 'Recipient of message.',
        'required' => false,
        'default' => DateTime::now(),
        'example' => DateTime::now(),
        ])
        ->addRule('deliveryError', [
        'type' => self::TYPE_STRING,
        'description' => 'Delivery error if any.',
        'required' => false,
        'default' => '',
        'example' => 'Provider not valid.',
        ])
        ->addRule('deliveredTo', [
        'type' => self::TYPE_INTEGER,
        'description' => 'Number of recipients the message was delivered to.',
        'default' => '',
        'example' => 1,
        ])
        ->addRule('delivered', [
        'type' => self::TYPE_BOOLEAN,
        'description' => 'Status of delivery.',
        'default' => '',
        'example' => true,
        ])
        ->addRule('search', [
        'type' => self::TYPE_STRING,
        'description' => 'Field that can be used for searching message.',
        'default' => '',
        'example' => 'Hello everyone',
        ]);
    }

  /**
   * Get Name
   *
   * @return string
   */
    public function getName(): string
    {
        return 'Message';
    }

  /**
   * Get Type
   *
   * @return string
   */
    public function getType(): string
    {
        return Response::MODEL_MESSAGE;
    }
}

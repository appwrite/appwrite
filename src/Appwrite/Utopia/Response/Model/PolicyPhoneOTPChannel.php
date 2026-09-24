<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class PolicyPhoneOTPChannel extends PolicyBase
{
    public array $conditions = [
        '$id' => 'phone-otp-channel',
    ];

    public function __construct()
    {
        parent::__construct();

        $this->addRule('channel', [
            'type' => self::TYPE_STRING,
            'description' => 'Channel used to deliver phone OTP messages. Can be one of: ' . PHONE_OTP_CHANNEL_SMS . ', ' . PHONE_OTP_CHANNEL_WHATSAPP . ', ' . PHONE_OTP_CHANNEL_WHATSAPP_SMS . '.',
            'default' => PHONE_OTP_CHANNEL_SMS,
            'example' => PHONE_OTP_CHANNEL_WHATSAPP_SMS,
        ]);
    }

    public function getName(): string
    {
        return 'Policy Phone OTP Channel';
    }

    public function getType(): string
    {
        return Response::MODEL_POLICY_PHONE_OTP_CHANNEL;
    }
}

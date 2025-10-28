<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class MockNumber extends Model
{
    public function __construct()
    {
        $this
            ->addRule('phone', [
                'type' => self::TYPE_STRING,
                'description' => 'Mock phone number for testing phone authentication. Useful for testing phone authentication without sending an SMS.',
                'default' => '',
                'example' => '+1612842323',
            ])
            ->addRule('otp', [
                'type' => self::TYPE_STRING,
                'description' => 'Mock OTP for the number. ',
                'default' => '',
                'example' => '123456',
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
        return 'Mock Number';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_MOCK_NUMBER;
    }
}

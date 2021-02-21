<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class Phone extends Model
{
    public function __construct()
    {
        $this
            ->addRule('code', [
                'type' => self::TYPE_STRING,
                'description' => 'Phone code.',
                'default' => '',
                'example' => '+1',
            ])
            ->addRule('countryCode', [
                'type' => self::TYPE_STRING,
                'description' => 'Country two-character ISO 3166-1 alpha code.',
                'default' => '',
                'example' => 'US',
            ])
            ->addRule('countryName', [
                'type' => self::TYPE_STRING,
                'description' => 'Country name.',
                'default' => '',
                'example' => 'United States',
            ])
        ;
    }

    /**
     * Get Name
     * 
     * @return string
     */
    public function getName():string
    {
        return 'Phone';
    }

    /**
     * Get Collection
     * 
     * @return string
     */
    public function getType():string
    {
        return Response::MODEL_PHONE;
    }
}
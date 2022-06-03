<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class Country extends Model
{
    public function __construct()
    {
        $this
            ->addRule('name', [
                'type' => self::TYPE_STRING,
                'description' => 'Country name.',
                'default' => '',
                'example' => 'United States',
            ])
            ->addRule('code', [
                'type' => self::TYPE_STRING,
                'description' => 'Country two-character ISO 3166-1 alpha code.',
                'default' => '',
                'example' => 'US',
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
        return 'Country';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_COUNTRY;
    }
}

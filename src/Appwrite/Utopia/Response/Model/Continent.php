<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class Continent extends Model
{
    public function __construct()
    {
        $this
            ->addRule('name', [
                'type' => self::TYPE_STRING,
                'description' => 'Continent name.',
                'default' => '',
                'example' => 'Europe',
            ])
            ->addRule('code', [
                'type' => self::TYPE_STRING,
                'description' => 'Continent two letter code.',
                'default' => '',
                'example' => 'EU',
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
        return 'Continent';
    }

    /**
     * Get Collection
     *
     * @return string
     */
    public function getType():string
    {
        return Response::MODEL_CONTINENT;
    }
}

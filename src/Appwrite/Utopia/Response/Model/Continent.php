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
                'type' => 'string',
                'description' => 'Continent name.',
                'example' => 'Europe',
            ])
            ->addRule('code', [
                'type' => 'string',
                'description' => 'Continent two letter code.',
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
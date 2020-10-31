<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class Language extends Model
{
    public function __construct()
    {
        $this
            ->addRule('name', [
                'type' => 'string',
                'description' => 'Language name.',
                'example' => 'Italian',
            ])
            ->addRule('code', [
                'type' => 'string',
                'description' => 'Language two-character ISO 639-1 codes.',
                'example' => 'it',
            ])
            ->addRule('nativeName', [
                'type' => 'string',
                'description' => 'Language native name.',
                'example' => 'Italiano',
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
        return 'Language';
    }

    /**
     * Get Collection
     * 
     * @return string
     */
    public function getType():string
    {
        return Response::MODEL_LANGUAGE;
    }
}
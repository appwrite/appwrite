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
                'type' => self::TYPE_STRING,
                'description' => 'Language name.',
                'example' => 'Italian',
            ])
            ->addRule('code', [
                'type' => self::TYPE_STRING,
                'description' => 'Language two-character ISO 639-1 codes.',
                'example' => 'it',
            ])
            ->addRule('nativeName', [
                'type' => self::TYPE_STRING,
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
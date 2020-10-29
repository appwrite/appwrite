<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class Currency extends Model
{
    public function __construct()
    {
        $this
            ->addRule('symbol', [
                'type' => 'string',
                'description' => 'Currency symbol.',
                'example' => '$',
            ])
            ->addRule('name', [
                'type' => 'string',
                'description' => 'Currency name.',
                'example' => 'US dollar',
            ])
            ->addRule('symbolNative', [
                'type' => 'string',
                'description' => 'Currency native symbol.',
                'example' => '$',
            ])
            ->addRule('decimalDigits', [
                'type' => 'integer',
                'description' => 'Number of decimal digits.',
                'example' => 2,
            ])
            ->addRule('rounding', [
                'type' => 'float',
                'description' => 'Currency digit rounding.',
                'example' => 0,
            ])
            ->addRule('code', [
                'type' => 'string',
                'description' => 'Currency code in [ISO 4217-1](http://en.wikipedia.org/wiki/ISO_4217) three-character format.',
                'example' => 'USD',
            ])
            ->addRule('namePlural', [
                'type' => 'string',
                'description' => 'Currency plural name',
                'example' => 'US dollars',
            ])
            // ->addRule('locations', [
            //     'type' => 'string',
            //     'description' => 'Currency locations list. List of location in two-character ISO 3166-1 alpha code.',
            //     'example' => ['US'],
            //     'array' => true,
            // ])
        ;
    }

    /**
     * Get Name
     * 
     * @return string
     */
    public function getName():string
    {
        return 'Currency';
    }

    /**
     * Get Collection
     * 
     * @return string
     */
    public function getType():string
    {
        return Response::MODEL_CURRENCY;
    }
}
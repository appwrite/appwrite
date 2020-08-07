<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class BaseList extends Model
{
    /**
     * @var string
     */
    protected $name = '';
    
    /**
     * @var string
     */
    protected $type = '';

    public function __construct(string $name, string $type, string $key, string $model)
    {
        $this->name = $name;
        $this->type = $type;

        $this
            ->addRule('sum', [
                'type' => 'integer',
                'description' => 'Total sum of items in the list.',
                'example' => '5',
            ])
            ->addRule($key, [
                'type' => $model,
                'description' => 'List of '.$key.'.',
                'example' => [],
                'array' => true,
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
        return $this->name;
    }

    /**
     * Get Collection
     * 
     * @return string
     */
    public function getType():string
    {
        return $this->type;
    }
}
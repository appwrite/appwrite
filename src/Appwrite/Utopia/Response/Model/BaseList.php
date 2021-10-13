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

    /**
     * @param string $name
     * @param string $type
     * @param string $key
     * @param string $model
     * @param bool $paging
     * @param bool $public
     */
    public function __construct(string $name, string $type, string $key, string $model, bool $paging = true, bool $public = true)
    {
        $this->name = $name;
        $this->type = $type;
        $this->public = $public;

        if ($paging) {
            $this->addRule('sum', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Total number of items available on the server.',
                'default' => 0,
                'example' => 5,
            ]);
        }
        $this->addRule($key, [
            'type' => $model,
            'description' => 'List of '.$key.'.',
            'default' => [],
            'array' => true,
        ]);
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

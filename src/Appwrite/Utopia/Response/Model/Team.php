<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class Team extends Model
{
    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => 'string',
                'description' => 'Team ID.',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('name', [
                'type' => 'string',
                'description' => 'Team name.',
                'default' => '',
                'example' => 'VIP',
            ])
            ->addRule('dateCreated', [
                'type' => 'integer',
                'description' => 'Team creation date in Unix timestamp.',
                'example' => 1592981250,
            ])
            ->addRule('sum', [ // TODO change key name?
                'type' => 'integer',
                'description' => 'Total sum of team members.',
                'example' => 7,
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
        return 'Team';
    }

    /**
     * Get Collection
     * 
     * @return string
     */
    public function getType():string
    {
        return Response::MODEL_TEAM;
    }
}
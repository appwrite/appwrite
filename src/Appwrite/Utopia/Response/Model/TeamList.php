<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;

class TeamList extends BaseList
{
    public function __construct()
    {
        $this
            ->addRule('teams', [
                'type' => Response::MODEL_TEAM,
                'description' => 'List of teams.',
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
        return 'Team List';
    }

    /**
     * Get Collection
     * 
     * @return string
     */
    public function getType():string
    {
        return Response::MODEL_TEAM_LIST;
    }
}
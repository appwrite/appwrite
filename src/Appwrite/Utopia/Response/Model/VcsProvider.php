<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class VcsProvider extends Model
{
    public function __construct()
    {
        $this
            ->addRule('name', [
                'type' => self::TYPE_STRING,
                'description' => 'VCS (Version Control System) provider name.',
                'default' => '',
                'example' => 'github',
            ])
            ->addRule('supportForRepositoryCreation', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Defines if the provider allows creating repositories. Some providers reserve repository creation for their own UI.',
                'default' => false,
                'example' => true,
            ])
            ->addRule('supportForPublicRepositories', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Defines if the provider can host repositories that are readable without authentication.',
                'default' => false,
                'example' => true,
            ]);
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'VcsProvider';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_VCS_PROVIDER;
    }
}

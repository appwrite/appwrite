<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class MigrationFirebaseProject extends Model
{
    public function __construct()
    {
        $this
            ->addRule('projectId', [
                'type' => self::TYPE_STRING,
                'description' => 'Project ID.',
                'default' => '',
                'example' => 'my-project',
            ])
            ->addRule('displayName', [
                'type' => self::TYPE_STRING,
                'description' => 'Project display name.',
                'default' => '',
                'example' => 'My Project',
            ]);
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'MigrationFirebaseProject';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_MIGRATION_FIREBASE_PROJECT;
    }
}

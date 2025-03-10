<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class DetectionFramework extends Model
{
    public function __construct()
    {
        $this
            ->addRule('framework', [
                'type' => self::TYPE_STRING,
                'description' => 'Framework',
                'default' => '',
                'example' => 'nuxt',
            ])
            ->addRule('installCommand', [
                'type' => self::TYPE_STRING,
                'description' => 'Site Install Command',
                'default' => '',
                'example' => 'npm install',
            ])
            ->addRule('buildCommand', [
                'type' => self::TYPE_STRING,
                'description' => 'Site Build Command',
                'default' => '',
                'example' => 'npm run build',
            ])
            ->addRule('outputDirectory', [
                'type' => self::TYPE_STRING,
                'description' => 'Site Output Directory',
                'default' => '',
                'example' => 'dist',
            ]);
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'DetectionFramework';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_DETECTION_FRAMEWORK;
    }
}

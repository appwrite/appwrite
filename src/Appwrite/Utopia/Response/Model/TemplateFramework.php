<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class TemplateFramework extends Model
{
    public function __construct()
    {
        $this
            ->addRule('name', [
                'type' => self::TYPE_STRING,
                'description' => 'Framework Name.',
                'default' => '',
                'example' => 'sveltekit',
            ])
            ->addRule('installCommand', [
                'type' => self::TYPE_STRING,
                'description' => 'The install command used to install the dependencies.',
                'default' => '',
                'example' => 'npm install',
            ])
            ->addRule('buildCommand', [
                'type' => self::TYPE_STRING,
                'description' => 'The build command used to build the deployment.',
                'default' => '',
                'example' => 'npm run build',
            ])
            ->addRule('outputDirectory', [
                'type' => self::TYPE_STRING,
                'description' => 'The output directory to store the build output.',
                'default' => '',
                'example' => 'build',
            ])
            ->addRule('providerRootDirectory', [
                'type' => self::TYPE_STRING,
                'description' => 'Path to site in VCS (Version Control System) repository',
                'default' => '',
                'example' => 'node/starter',
            ]);
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Template Framework';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_TEMPLATE_FRAMEWORK;
    }
}

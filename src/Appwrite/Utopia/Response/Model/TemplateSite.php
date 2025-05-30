<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class TemplateSite extends Model
{
    public function __construct()
    {
        $this
            ->addRule('key', [
                'type' => self::TYPE_STRING,
                'description' => 'Site Template ID.',
                'default' => '',
                'example' => 'starter',
            ])
            ->addRule('name', [
                'type' => self::TYPE_STRING,
                'description' => 'Site Template Name.',
                'default' => '',
                'example' => 'Starter site',
            ])
            ->addRule('tagline', [
                'type' => self::TYPE_STRING,
                'description' => 'Short description of template',
                'default' => '',
                'example' => 'Minimal web app integrating with Appwrite.',
            ])
            ->addRule('demoUrl', [
                'type' => self::TYPE_STRING,
                'description' => 'URL hosting a template demo.',
                'default' => '',
                'example' => 'https://nextjs-starter.appwrite.network/',
            ])
            ->addRule('screenshotDark', [
                'type' => self::TYPE_STRING,
                'description' => 'File URL with preview screenshot in dark theme preference.',
                'default' => '',
                'example' => 'https://cloud.appwrite.io/images/sites/templates/template-for-blog-dark.png',
            ])
            ->addRule('screenshotLight', [
                'type' => self::TYPE_STRING,
                'description' => 'File URL with preview screenshot in light theme preference.',
                'default' => '',
                'example' => 'https://cloud.appwrite.io/images/sites/templates/template-for-blog-light.png',
            ])
            ->addRule('useCases', [
                'type' => self::TYPE_STRING,
                'description' => 'Site use cases.',
                'default' => [],
                'example' => 'Starter',
                'array' => true,
            ])
            ->addRule('frameworks', [
                'type' => Response::MODEL_TEMPLATE_FRAMEWORK,
                'description' => 'List of frameworks that can be used with this template.',
                'default' => [],
                'example' => [],
                'array' => true
            ])
            ->addRule('vcsProvider', [
                'type' => self::TYPE_STRING,
                'description' => 'VCS (Version Control System) Provider.',
                'default' => '',
                'example' => 'github',
            ])
            ->addRule('providerRepositoryId', [
                'type' => self::TYPE_STRING,
                'description' => 'VCS (Version Control System) Repository ID',
                'default' => '',
                'example' => 'templates',
            ])
            ->addRule('providerOwner', [
                'type' => self::TYPE_STRING,
                'description' => 'VCS (Version Control System) Owner.',
                'default' => '',
                'example' => 'appwrite',
            ])
            ->addRule('providerVersion', [
                'type' => self::TYPE_STRING,
                'description' => 'VCS (Version Control System) branch version (tag).',
                'default' => '',
                'example' => 'main',
            ])
            ->addRule('variables', [
                'type' => Response::MODEL_TEMPLATE_VARIABLE,
                'description' => 'Site variables.',
                'default' => [],
                'example' => [],
                'array' => true
            ])
        ;
    }

    /**
     * Get Name
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Template Site';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_TEMPLATE_SITE;
    }
}

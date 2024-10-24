<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class TemplateSite extends Model
{
    public function __construct()
    {
        $this
            ->addRule('icon', [
                'type' => self::TYPE_STRING,
                'description' => 'Site Template Icon.',
                'default' => '',
                'example' => 'icon-lightning-bolt',
            ])
            ->addRule('id', [
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
                'description' => 'Site Template Tagline.',
                'default' => '',
                'example' => 'A simple site to get started.',
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
            ->addRule('instructions', [
                'type' => self::TYPE_STRING,
                'description' => 'Site Template Instructions.',
                'default' => '',
                'example' => 'For documentation and instructions check out <link>.',
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
            ->addRule('scopes', [
                'type' => self::TYPE_STRING,
                'description' => 'Site scopes.',
                'default' => [],
                'example' => 'users.read',
                'array' => true,
            ]);
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

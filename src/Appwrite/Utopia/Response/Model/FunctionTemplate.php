<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;

class FunctionTemplate extends Model
{
    public function __construct()
    {
        $this
            ->addRule('icon', [
                'type' => self::TYPE_STRING,
                'description' => 'Function Template Icon.',
                'default' => '',
                'example' => 'icon-lightning-bolt',
            ])
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'Function Template ID.',
                'default' => '',
                'example' => 'starter',
            ])
            ->addRule('name', [
                'type' => self::TYPE_STRING,
                'description' => 'Function Template Name.',
                'default' => '',
                'example' => 'Starter function',
            ])
            ->addRule('tagline', [
                'type' => self::TYPE_STRING,
                'description' => 'Function Template Tagline.',
                'default' => '',
                'example' => 'A simple function to get started.',
            ])
            ->addRule('permissions', [
                'type' => self::TYPE_STRING,
                'description' => 'Execution permissions.',
                'default' => [],
                'example' => 'any',
                'array' => true,
            ])
            ->addRule('events', [
                'type' => self::TYPE_STRING,
                'description' => 'Function trigger events.',
                'default' => [],
                'example' => 'account.create',
                'array' => true,
            ])
            ->addRule('cron', [
                'type' => self::TYPE_STRING,
                'description' => 'Function execution schedult in CRON format.',
                'default' => '',
                'example' => '0 0 * * *',
            ])
            ->addRule('timeout', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Function execution timeout in seconds.',
                'default' => 15,
                'example' => 300,
            ])
            ->addRule('usecases', [
                'type' => self::TYPE_STRING,
                'description' => 'Function usecases.',
                'default' => [],
                'example' => 'Starter',
                'array' => true,
            ])
            ->addRule('runtimes', [
                'type' => Response::MODEL_RUNTIME_TEMPLATE,
                'description' => 'List of runtimes that can be used with this template.',
                'default' => [],
                'example' => [],
                'array' => true
            ])
            ->addRule('instructions', [
                'type' => self::TYPE_STRING,
                'description' => 'Function Template Instructions.',
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
            ->addRule('providerBranch', [
                'type' => self::TYPE_STRING,
                'description' => 'VCS (Version Control System) branch name',
                'default' => '',
                'example' => 'main',
            ])
            ->addRule('variables', [
                'type' => Response::MODEL_VARIABLE_TEMPLATE,
                'description' => 'Function variables.',
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
        return 'Function Template';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
    {
        return Response::MODEL_FUNCTION_TEMPLATE;
    }
}

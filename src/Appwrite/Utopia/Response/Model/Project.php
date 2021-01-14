<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;
use Utopia\Config\Config;

class Project extends Model
{
    /**
     * @var bool
     */
    protected $public = false;
    
    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'Project ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('name', [
                'type' => self::TYPE_STRING,
                'description' => 'Project name.',
                'default' => '',
                'example' => 'New Project',
            ])
            ->addRule('description', [
                'type' => self::TYPE_STRING,
                'description' => 'Project description.',
                'default' => '',
                'example' => 'This is a new project.',
            ])
            ->addRule('teamId', [
                'type' => self::TYPE_STRING,
                'description' => 'Project team ID.',
                'default' => '',
                'example' => '1592981250',
            ])
            ->addRule('logo', [
                'type' => self::TYPE_STRING,
                'description' => 'Project logo file ID.',
                'default' => '',
                'example' => '5f5c451b403cb',
            ])
            ->addRule('url', [
                'type' => self::TYPE_STRING,
                'description' => 'Project website URL.',
                'default' => '',
                'example' => '5f5c451b403cb',
            ])
            ->addRule('legalName', [
                'type' => self::TYPE_STRING,
                'description' => 'Company legal name.',
                'default' => '',
                'example' => 'Company LTD.',
            ])
            ->addRule('legalCountry', [
                'type' => self::TYPE_STRING,
                'description' => 'Country code in [ISO 3166-1](http://en.wikipedia.org/wiki/ISO_3166-1) two-character format.',
                'default' => '',
                'example' => 'US',
            ])
            ->addRule('legalState', [
                'type' => self::TYPE_STRING,
                'description' => 'State name.',
                'default' => '',
                'example' => 'New York',
            ])
            ->addRule('legalCity', [
                'type' => self::TYPE_STRING,
                'description' => 'City name.',
                'default' => '',
                'example' => 'New York City.',
            ])
            ->addRule('legalAddress', [
                'type' => self::TYPE_STRING,
                'description' => 'Company Address.',
                'default' => '',
                'example' => '620 Eighth Avenue, New York, NY 10018',
            ])
            ->addRule('legalTaxId', [
                'type' => self::TYPE_STRING,
                'description' => 'Company Tax ID.',
                'default' => '',
                'example' => '131102020',
            ])
            ->addRule('platforms', [
                'type' => Response::MODEL_PLATFORM,
                'description' => 'List of Platforms.',
                'default' => [],
                'example' => [],
                'array' => true,
            ])
            ->addRule('webhooks', [
                'type' => Response::MODEL_WEBHOOK,
                'description' => 'List of Webhooks.',
                'default' => [],
                'example' => [],
                'array' => true,
            ])
            ->addRule('keys', [
                'type' => Response::MODEL_KEY,
                'description' => 'List of API Keys.',
                'default' => [],
                'example' => [],
                'array' => true,
            ])
            ->addRule('domains', [
                'type' => Response::MODEL_DOMAIN,
                'description' => 'List of Domains.',
                'default' => [],
                'example' => [],
                'array' => true,
            ])
            ->addRule('tasks', [
                'type' => Response::MODEL_TASK,
                'description' => 'List of Tasks.',
                'default' => [],
                'example' => [],
                'array' => true,
            ])
        ;

        $providers = Config::getParam('providers', []);

        foreach ($providers as $index => $provider) {
            if (!$provider['enabled']) {
                continue;
            }

            $name = (isset($provider['name'])) ? $provider['name'] : 'Unknown';

            $this
                ->addRule('usersOauth2'.\ucfirst($index).'Appid', [
                    'type' => self::TYPE_STRING,
                    'description' => $name.' OAuth app ID.',
                    'example' => '123247283472834787438',
                    'default' => '',
                ])
                ->addRule('usersOauth2'.\ucfirst($index).'Secret', [
                    'type' => self::TYPE_STRING,
                    'description' => $name.' OAuth secret ID.',
                    'example' => 'djsgudsdsewe43434343dd34...',
                    'default' => '',
                ])
            ;
        }
    }

    /**
     * Get Name
     * 
     * @return string
     */
    public function getName():string
    {
        return 'Project';
    }

    /**
     * Get Collection
     * 
     * @return string
     */
    public function getType():string
    {
        return Response::MODEL_PROJECT;
    }
}
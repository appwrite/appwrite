<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;
use Utopia\Config\Config;
use Utopia\Database\Document;

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
            ->addRule('authLimit', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Max users allowed. 0 is unlimited.',
                'default' => 0,
                'example' => 100,
            ])
            ->addRule('platforms', [
                'type' => Response::MODEL_PLATFORM,
                'description' => 'List of Platforms.',
                'default' => [],
                'example' => new \stdClass,
                'array' => true,
            ])
            ->addRule('webhooks', [
                'type' => Response::MODEL_WEBHOOK,
                'description' => 'List of Webhooks.',
                'default' => [],
                'example' => new \stdClass,
                'array' => true,
            ])
            ->addRule('keys', [
                'type' => Response::MODEL_KEY,
                'description' => 'List of API Keys.',
                'default' => [],
                'example' => new \stdClass,
                'array' => true,
            ])
            ->addRule('domains', [
                'type' => Response::MODEL_DOMAIN,
                'description' => 'List of Domains.',
                'default' => [],
                'example' => new \stdClass,
                'array' => true,
            ])
        ;

        $services = Config::getParam('services', []);
        $providers = Config::getParam('providers', []);
        $auth = Config::getParam('auth', []);

        foreach ($providers as $index => $provider) {
            if (!$provider['enabled']) {
                continue;
            }

            $name = (isset($provider['name'])) ? $provider['name'] : 'Unknown';

            $this
                ->addRule('provider'.\ucfirst($index).'Appid', [
                    'type' => self::TYPE_STRING,
                    'description' => $name.' OAuth app ID.',
                    'example' => '123247283472834787438',
                    'default' => '',
                ])
                ->addRule('provider'.\ucfirst($index).'Secret', [
                    'type' => self::TYPE_STRING,
                    'description' => $name.' OAuth secret ID.',
                    'example' => 'djsgudsdsewe43434343dd34...',
                    'default' => '',
                ])
            ;
        }

        foreach ($auth as $index => $method) {
            $name = $method['name'] ?? '';
            $key = $method['key'] ?? '';

            $this
                ->addRule('auth' . ucfirst($key), [
                    'type' => self::TYPE_BOOLEAN,
                    'description' => $name.' auth method status',
                    'example' => true,
                    'default' => true,
                ])
            ;
        }

        foreach ($services as $service) {
            if(!$service['optional']) {
                continue;
            }

            $name = $service['name'] ?? '';
            $key = $service['key'] ?? '';

            $this
                ->addRule('serviceStatusFor'.ucfirst($key), [
                    'type' => self::TYPE_BOOLEAN,
                    'description' => $name.' service status',
                    'example' => true,
                    'default' => true,
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

    /**
     * Get Collection
     * 
     * @return string
     */
    public function filter(Document $document): Document
    {
        $values = $document->getAttribute('services', []);
        $services = Config::getParam('services', []);

        foreach($services as $service) {
            if(!$service['optional']) {
                continue;
            }
            $key = $service['key'] ?? '';
            $value = $values[$key] ?? true;
            $document->setAttribute('serviceStatusFor'.ucfirst($key), $value);
        }

        $authValues = $document->getAttribute('auths',[]);
        $auth = Config::getParam('auth', []);

        $document->setAttribute('authLimit', $authValues['limit'] ?? 0);

        foreach ($auth as $index => $method) {
            $key = $method['key'];
            $value = $authValues[$key] ?? true;
            $document->setAttribute('auth' . ucfirst($key), $value);
        }

        $providers = Config::getParam('providers', []);
        $providerValues = $document->getAttribute('providers', []);

        foreach ($providers as $key => $provider) {
            if (!$provider['enabled']) {
                continue;
            }
            $appId = $providerValues[$key . 'Appid'] ?? '';
            $secret = $providerValues[$key . 'Secret'] ?? '';
            $document->setAttribute('provider' . ucfirst($key) . 'Appid', $appId)->setAttribute('provider' . ucfirst($key) . 'Secret', $secret);
        }
        return $document;
    }
}

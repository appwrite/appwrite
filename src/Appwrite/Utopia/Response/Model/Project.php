<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Auth\Auth;
use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;
use Utopia\Config\Config;
use Utopia\Database\Document;

class Project extends Model
{
    /**
     * @var bool
     */
    protected bool $public = false;

    public function __construct()
    {
        $this
            ->addRule('$id', [
                'type' => self::TYPE_STRING,
                'description' => 'Project ID.',
                'default' => '',
                'example' => '5e5ea5c16897e',
            ])
            ->addRule('$createdAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Project creation date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])
            ->addRule('$updatedAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Project update date in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
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
            ->addRule('authDuration', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Session duration in seconds.',
                'default' => Auth::TOKEN_EXPIRATION_LOGIN_LONG,
                'example' => 60,
            ])
            ->addRule('authLimit', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Max users allowed. 0 is unlimited.',
                'default' => 0,
                'example' => 100,
            ])
            ->addRule('providers', [
                'type' => Response::MODEL_PROVIDER,
                'description' => 'List of Providers.',
                'default' => [],
                'example' => new \stdClass(),
                'array' => true,
            ])
            ->addRule('platforms', [
                'type' => Response::MODEL_PLATFORM,
                'description' => 'List of Platforms.',
                'default' => [],
                'example' => new \stdClass(),
                'array' => true,
            ])
            ->addRule('webhooks', [
                'type' => Response::MODEL_WEBHOOK,
                'description' => 'List of Webhooks.',
                'default' => [],
                'example' => new \stdClass(),
                'array' => true,
            ])
            ->addRule('keys', [
                'type' => Response::MODEL_KEY,
                'description' => 'List of API Keys.',
                'default' => [],
                'example' => new \stdClass(),
                'array' => true,
            ])
            ->addRule('domains', [
                'type' => Response::MODEL_DOMAIN,
                'description' => 'List of Domains.',
                'default' => [],
                'example' => new \stdClass(),
                'array' => true,
            ])
        ;

        $services = Config::getParam('services', []);
        $auth = Config::getParam('auth', []);

        foreach ($auth as $index => $method) {
            $name = $method['name'] ?? '';
            $key = $method['key'] ?? '';

            $this
                ->addRule('auth' . ucfirst($key), [
                    'type' => self::TYPE_BOOLEAN,
                    'description' => $name . ' auth method status',
                    'example' => true,
                    'default' => true,
                ])
            ;
        }

        foreach ($services as $service) {
            if (!$service['optional']) {
                continue;
            }

            $name = $service['name'] ?? '';
            $key = $service['key'] ?? '';

            $this
                ->addRule('serviceStatusFor' . ucfirst($key), [
                    'type' => self::TYPE_BOOLEAN,
                    'description' => $name . ' service status',
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
    public function getName(): string
    {
        return 'Project';
    }

    /**
     * Get Type
     *
     * @return string
     */
    public function getType(): string
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
        // Services
        $values = $document->getAttribute('services', []);
        $services = Config::getParam('services', []);

        foreach ($services as $service) {
            if (!$service['optional']) {
                continue;
            }
            $key = $service['key'] ?? '';
            $value = $values[$key] ?? true;
            $document->setAttribute('serviceStatusFor' . ucfirst($key), $value);
        }

        // Auth
        $authValues = $document->getAttribute('auths', []);
        $auth = Config::getParam('auth', []);

        $document->setAttribute('authLimit', $authValues['limit'] ?? 0);
        $document->setAttribute('authDuration', $authValues['duration'] ?? Auth::TOKEN_EXPIRATION_LOGIN_LONG);

        foreach ($auth as $index => $method) {
            $key = $method['key'];
            $value = $authValues[$key] ?? true;
            $document->setAttribute('auth' . ucfirst($key), $value);
        }

        // Providers
        $providers = Config::getParam('providers', []);
        $providerValues = $document->getAttribute('authProviders', []);
        $projectProviders = [];

        foreach ($providers as $key => $provider) {
            if (!$provider['enabled']) {
                // Disabled by Appwrite configuration, exclude from response
                continue;
            }

            $projectProviders[] = new Document([
                'name' => ucfirst($key),
                'appId' => $providerValues[$key . 'Appid'] ?? '',
                'secret' => $providerValues[$key . 'Secret'] ?? '',
                'enabled' => $providerValues[$key . 'Enabled'] ?? false,
            ]);
        }

        $document->setAttribute("providers", $projectProviders);

        return $document;
    }
}

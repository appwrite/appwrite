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
            ->addRule('authSessionsLimit', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Max sessions allowed per user. 100 maximum.',
                'default' => 10,
                'example' => 10,
            ])
            ->addRule('authPasswordHistory', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Max allowed passwords in the history list per user. Max passwords limit allowed in history is 20. Use 0 for disabling password history.',
                'default' => 0,
                'example' => 5,
            ])
            ->addRule('authPasswordDictionary', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Whether or not to check user\'s password against most commonly used passwords.',
                'default' => false,
                'example' => true,
            ])
            ->addRule('providers', [
                'type' => Response::MODEL_PROVIDER,
                'description' => 'List of Providers.',
                'default' => [],
                'example' => [new \stdClass()],
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
            ->addRule('smtpEnabled', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Status for custom SMTP',
                'default' => false,
                'example' => false,
                'array' => false
            ])
            ->addRule('smtpSender', [
                'type' => self::TYPE_STRING,
                'description' => 'SMTP sender email',
                'default' => '',
                'example' => 'john@appwrite.io',
            ])
            ->addRule('smtpHost', [
                'type' => self::TYPE_STRING,
                'description' => 'SMTP server host name',
                'default' => '',
                'example' => 'mail.appwrite.io',
            ])
            ->addRule('smtpPort', [
                'type' => self::TYPE_INTEGER,
                'description' => 'SMTP server port',
                'default' => '',
                'example' => 25,
            ])
            ->addRule('smtpUsername', [
                'type' => self::TYPE_STRING,
                'description' => 'SMTP server username',
                'default' => '',
                'example' => 'emailuser',
            ])
            ->addRule('smtpPassword', [
                'type' => self::TYPE_STRING,
                'description' => 'SMTP server password',
                'default' => '',
                'example' => 'securepassword',
            ])
            ->addRule('smtpSecure', [
                'type' => self::TYPE_STRING,
                'description' => 'SMTP server secure protocol',
                'default' => '',
                'example' => 'tls',
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
        // SMTP
        $smtp = $document->getAttribute('smtp', []);
        $document->setAttribute('smtpEnabled', $smtp['enabled'] ?? false);
        $document->setAttribute('smtpSender', $smtp['sender'] ?? '');
        $document->setAttribute('smtpHost', $smtp['host'] ?? '');
        $document->setAttribute('smtpPort', $smtp['port'] ?? '');
        $document->setAttribute('smtpUsername', $smtp['username'] ?? '');
        $document->setAttribute('smtpPassword', $smtp['password'] ?? '');
        $document->setAttribute('smtpSecure', $smtp['secure'] ?? '');

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
        $document->setAttribute('authSessionsLimit', $authValues['maxSessions'] ?? APP_LIMIT_USER_SESSIONS_DEFAULT);
        $document->setAttribute('authPasswordHistory', $authValues['passwordHistory'] ?? 0);
        $document->setAttribute('authPasswordDictionary', $authValues['passwordDictionary'] ?? false);

        foreach ($auth as $index => $method) {
            $key = $method['key'];
            $value = $authValues[$key] ?? true;
            $document->setAttribute('auth' . ucfirst($key), $value);
        }

        // Providers
        $providers = Config::getParam('authProviders', []);
        $providerValues = $document->getAttribute('authProviders', []);
        $projectProviders = [];

        foreach ($providers as $key => $provider) {
            if (!$provider['enabled']) {
                // Disabled by Appwrite configuration, exclude from response
                continue;
            }

            $projectProviders[] = new Document([
                'key' => $key,
                'name' => $provider['name'] ?? '',
                'appId' => $providerValues[$key . 'Appid'] ?? '',
                'secret' => $providerValues[$key . 'Secret'] ?? '',
                'enabled' => $providerValues[$key . 'Enabled'] ?? false,
            ]);
        }

        $document->setAttribute("providers", $projectProviders);

        return $document;
    }
}

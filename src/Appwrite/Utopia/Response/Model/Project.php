<?php

namespace Appwrite\Utopia\Response\Model;

use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model;
use Utopia\Config\Config;
use Utopia\Database\Document;

class Project extends Model
{
    public function __construct()
    {
        $this
            // Basic project information
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
            ->addRule('teamId', [
                'type' => self::TYPE_STRING,
                'description' => 'Project team ID.',
                'default' => '',
                'example' => '1592981250',
            ])

            // Resource: Dev Keys
            ->addRule('devKeys', [
                'type' => Response::MODEL_DEV_KEY,
                'description' => 'Deprecated since 1.9.5: List of dev keys.',
                'default' => [],
                'example' => new \stdClass(),
                'array' => true,
            ])

            // Resource: SMTP
            ->addRule('smtpEnabled', [
                'type' => self::TYPE_BOOLEAN,
                'description' => 'Status for custom SMTP',
                'default' => false,
                'example' => false,
                'array' => false
            ])
            ->addRule('smtpSenderName', [
                'type' => self::TYPE_STRING,
                'description' => 'SMTP sender name',
                'default' => '',
                'example' => 'John Appwrite',
            ])
            ->addRule('smtpSenderEmail', [
                'type' => self::TYPE_STRING,
                'description' => 'SMTP sender email',
                'default' => '',
                'example' => 'john@appwrite.io',
            ])
            ->addRule('smtpReplyToName', [
                'type' => self::TYPE_STRING,
                'description' => 'SMTP reply to name',
                'default' => '',
                'example' => 'Support Team',
            ])
            ->addRule('smtpReplyToEmail', [
                'type' => self::TYPE_STRING,
                'description' => 'SMTP reply to email',
                'default' => '',
                'example' => 'support@appwrite.io',
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
                'description' => 'SMTP server password. This property is write-only and always returned empty.',
                'default' => '',
                'example' => '',
            ])
            ->addRule('smtpSecure', [
                'type' => self::TYPE_STRING,
                'description' => 'SMTP server secure protocol',
                'default' => '',
                'example' => 'tls',
            ])

            // Resource: Ping
            ->addRule('pingCount', [
                'type' => self::TYPE_INTEGER,
                'description' => 'Number of times the ping was received for this project.',
                'default' => 0,
                'example' => 1,
            ])
            ->addRule('pingedAt', [
                'type' => self::TYPE_DATETIME,
                'description' => 'Last ping datetime in ISO 8601 format.',
                'default' => '',
                'example' => self::TYPE_DATETIME_EXAMPLE,
            ])

            // Resource: Labels
            ->addRule('labels', [
                'type' => self::TYPE_STRING,
                'description' => 'Labels for the project.',
                'default' => [],
                'example' => ['vip'],
                'array' => true,
            ])

            // Resource: Billing
            ->addRule('status', [
                'type' => self::TYPE_STRING,
                'description' => 'Project status.',
                'default' => 'active',
                'example' => 'active',
            ])

            // Resource: Auth methods
            ->addRule('authMethods', [
                'type' => Response::MODEL_PROJECT_AUTH_METHOD,
                'description' => 'List of auth methods.',
                'default' => [],
                'example' => new \stdClass(),
                'array' => true,
            ])

            // Resource: Services
            ->addRule('services', [
                'type' => Response::MODEL_PROJECT_SERVICE,
                'description' => 'List of services.',
                'default' => [],
                'example' => new \stdClass(),
                'array' => true,
            ])

            // Resource: Protocols
            ->addRule('protocols', [
                'type' => Response::MODEL_PROJECT_PROTOCOL,
                'description' => 'List of protocols.',
                'default' => [],
                'example' => new \stdClass(),
                'array' => true,
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
     * Filter document structure
     *
     * @return Document
     */
    public function filter(Document $document): Document
    {
        $this->expandSmtpFields($document);
        $this->expandServices($document);
        $this->expandProtocols($document);
        $this->expandAuthMethods($document);

        return $document;
    }

    private function expandSmtpFields(Document $document): void
    {
        if (!$document->isSet('smtp')) {
            return;
        }

        $smtp = $document->getAttribute('smtp', []);

        $document->setAttribute('smtpEnabled', $smtp['enabled'] ?? false);
        $document->setAttribute('smtpSenderEmail', $smtp['senderEmail'] ?? '');
        $document->setAttribute('smtpSenderName', $smtp['senderName'] ?? '');
        $document->setAttribute('smtpReplyToEmail', $smtp['replyToEmail'] ?? $smtp['replyTo'] ?? ''); // Includes backwards compatibility
        $document->setAttribute('smtpReplyToName', $smtp['replyToName'] ?? '');
        $document->setAttribute('smtpHost', $smtp['host'] ?? '');
        $document->setAttribute('smtpPort', $smtp['port'] ?? '');
        $document->setAttribute('smtpUsername', $smtp['username'] ?? '');
        $document->setAttribute('smtpPassword', ''); // Write-only: never expose the stored value
        $document->setAttribute('smtpSecure', $smtp['secure'] ?? '');
    }

    private function expandServices(Document $document): void
    {
        $values = $document->getAttribute('services', []);
        $services = [];

        foreach (Config::getParam('services', []) as $id => $service) {
            if (!$service['optional']) {
                continue;
            }

            $services[] = new Document([
                '$id' => $id,
                'enabled' => $values[$service['key']] ?? true,
            ]);
        }

        $document->setAttribute('services', $services);
    }

    private function expandProtocols(Document $document): void
    {
        $values = $document->getAttribute('apis', []);
        $protocols = [];

        foreach (Config::getParam('protocols', []) as $id => $api) {
            $protocols[] = new Document([
                '$id' => $id,
                'enabled' => $values[$api['key']] ?? true,
            ]);
        }

        $document->setAttribute('protocols', $protocols);
    }

    private function expandAuthMethods(Document $document): void
    {
        $values = $document->getAttribute('auths', []);
        $authMethods = [];

        foreach (Config::getParam('auth', []) as $id => $method) {
            $authMethods[] = new Document([
                '$id' => $id,
                'enabled' => $values[$method['key']] ?? true
            ]);
        }

        $document->setAttribute('authMethods', $authMethods);
    }
}

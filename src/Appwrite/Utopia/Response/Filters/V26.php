<?php

namespace Appwrite\Utopia\Response\Filters;

use Appwrite\Network\Platform;
use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Filter;
use Utopia\Config\Config;
use Utopia\Database\Document;

// Convert 1.9.5 Data format to 1.9.4 format
class V26 extends Filter
{
    public function parse(array $content, string $model): array
    {
        return match ($model) {
            Response::MODEL_PROJECT => $this->parseProject($content, $this->rawContent),
            Response::MODEL_PROJECT_LIST => $this->handleList($content, 'projects', function ($item) {
                $projectId = $item['$id'] ?? '';

                $rawProjects = $this->rawContent->getAttribute('projects', []);
                $rawProject = new Document();
                foreach ($rawProjects as $rawItem) {
                    if ($rawItem->getId() === $projectId) {
                        $rawProject = $rawItem;
                        break;
                    }
                }

                return $this->parseProject($item, $rawProject);
            }),
            default => $content,
        };
    }

    private function parseProject(array $content, Document $raw): array
    {
        $this->expandAuthMethods($content);
        $this->expandServices($content);
        $this->expandProtocols($content);

        unset($content['authMethods'], $content['services'], $content['protocols']);

        $auths = new Document($raw->getAttribute('auths', []));
        $content['authLimit'] = $auths->getAttribute('limit', 0);
        $content['authDuration'] = $auths->getAttribute('duration', TOKEN_EXPIRATION_LOGIN_LONG);
        $content['authSessionsLimit'] = $auths->getAttribute('maxSessions', 0);
        $content['authPasswordHistory'] = $auths->getAttribute('passwordHistory', 0);
        $content['authPasswordDictionary'] = $auths->getAttribute('passwordDictionary', false);
        $content['authPersonalDataCheck'] = $auths->getAttribute('personalDataCheck', false);
        $content['authDisposableEmails'] = $auths->getAttribute('disposableEmails', false);
        $content['authCanonicalEmails'] = $auths->getAttribute('canonicalEmails', false);
        $content['authFreeEmails'] = $auths->getAttribute('freeEmails', false);
        $content['authMockNumbers'] = $auths->getAttribute('mockNumbers', []);
        $content['authSessionAlerts'] = $auths->getAttribute('sessionAlerts', false);
        $content['authMembershipsUserName'] = $auths->getAttribute('membershipsUserName', false);
        $content['authMembershipsUserEmail'] = $auths->getAttribute('membershipsUserEmail', false);
        $content['authMembershipsMfa'] = $auths->getAttribute('membershipsMfa', false);
        $content['authMembershipsUserId'] = $auths->getAttribute('membershipsUserId', false);
        $content['authMembershipsUserPhone'] = $auths->getAttribute('membershipsUserPhone', false);
        $content['authInvalidateSessions'] = $auths->getAttribute('invalidateSessions', false);

        $content['description'] = $raw->getAttribute('description', '');
        $content['logo'] = $raw->getAttribute('logo', '');
        $content['url'] = $raw->getAttribute('url', '');
        $content['legalName'] = $raw->getAttribute('legalName', '');
        $content['legalCountry'] = $raw->getAttribute('legalCountry', '');
        $content['legalState'] = $raw->getAttribute('legalState', '');
        $content['legalCity'] = $raw->getAttribute('legalCity', '');
        $content['legalAddress'] = $raw->getAttribute('legalAddress', '');
        $content['legalTaxId'] = $raw->getAttribute('legalTaxId', '');

        $content['oAuthProviders'] = $this->expandOAuthProviders($raw);

        $content['platforms'] = [];
        foreach ($raw->getAttribute('platforms', []) as $platform) {
            $content['platforms'][] = $this->parsePlatform($platform);
        }

        $content['webhooks'] = [];
        foreach ($raw->getAttribute('webhooks', []) as $webhook) {
            $content['webhooks'][] = $this->parseWebhook($webhook);
        }

        $content['keys'] = [];
        foreach ($raw->getAttribute('keys', []) as $key) {
            $content['keys'][] = $this->parseKey($key);
        }

        return $content;
    }

    private function parsePlatform(Document $platform): array
    {
        $type = $platform->getAttribute('type', '');
        $key = $platform->getAttribute('key', '');

        $result = [
            '$id' => $platform->getAttribute('$id', ''),
            '$createdAt' => $platform->getAttribute('$createdAt', ''),
            '$updatedAt' => $platform->getAttribute('$updatedAt', ''),
            'name' => $platform->getAttribute('name', ''),
            'type' => $type,
        ];

        switch ($type) {
            case Platform::TYPE_ANDROID:
                $result['applicationId'] = $key;
                break;
            case Platform::TYPE_APPLE:
                $result['bundleIdentifier'] = $key;
                break;
            case Platform::TYPE_LINUX:
                $result['packageName'] = $key;
                break;
            case Platform::TYPE_WINDOWS:
                $result['packageIdentifierName'] = $key;
                break;
            default:
                // Web and backwards-compatibility types are mapped to web
                $result['hostname'] = $platform->getAttribute('hostname', '');
                $result['key'] = $key;
                break;
        }

        return $result;
    }

    private function parseWebhook(Document $webhook): array
    {
        return [
            '$id' => $webhook->getAttribute('$id', ''),
            '$createdAt' => $webhook->getAttribute('$createdAt', ''),
            '$updatedAt' => $webhook->getAttribute('$updatedAt', ''),
            'name' => $webhook->getAttribute('name', ''),
            'url' => $webhook->getAttribute('url', ''),
            'events' => $webhook->getAttribute('events', []),
            'tls' => $webhook->getAttribute('security', true),
            'authUsername' => $webhook->getAttribute('httpUser', ''),
            'authPassword' => $webhook->getAttribute('httpPass', ''),
            'secret' => $webhook->getAttribute('signatureKey', ''),
            'enabled' => $webhook->getAttribute('enabled', true),
            'logs' => $webhook->getAttribute('logs', ''),
            'attempts' => $webhook->getAttribute('attempts', 0),
        ];
    }

    private function parseKey(Document $key): array
    {
        return [
            '$id' => $key->getAttribute('$id', ''),
            '$createdAt' => $key->getAttribute('$createdAt', ''),
            '$updatedAt' => $key->getAttribute('$updatedAt', ''),
            'name' => $key->getAttribute('name', ''),
            'expire' => $key->getAttribute('expire', ''),
            'scopes' => $key->getAttribute('scopes', []),
            'secret' => $key->getAttribute('secret', ''),
            'accessedAt' => $key->getAttribute('accessedAt', ''),
            'sdks' => $key->getAttribute('sdks', []),
        ];
    }

    private function expandAuthMethods(array &$content): void
    {
        $authMethods = [];
        foreach ($content['authMethods'] ?? [] as $method) {
            $authMethods[$method['$id'] ?? ''] = $method['enabled'] ?? true;
        }

        foreach (Config::getParam('auth', []) as $id => $method) {
            $key = $method['key'] ?? '';
            $content['auth' . ucfirst($key)] = $authMethods[$id] ?? true;
        }
    }

    private function expandServices(array &$content): void
    {
        $services = [];
        foreach ($content['services'] ?? [] as $service) {
            $services[$service['$id'] ?? ''] = $service['enabled'] ?? true;
        }

        foreach (Config::getParam('services', []) as $id => $service) {
            if (!($service['optional'] ?? false)) {
                continue;
            }
            $key = $service['key'] ?? '';
            $content['serviceStatusFor' . ucfirst($key)] = $services[$id] ?? true;
        }
    }

    private function expandProtocols(array &$content): void
    {
        $protocols = [];
        foreach ($content['protocols'] ?? [] as $protocol) {
            $protocols[$protocol['$id'] ?? ''] = $protocol['enabled'] ?? true;
        }

        foreach (Config::getParam('protocols', []) as $id => $api) {
            $key = $api['key'] ?? '';
            $content['protocolStatusFor' . ucfirst($key)] = $protocols[$id] ?? true;
        }
    }

    private function expandOAuthProviders(Document $raw): array
    {
        $providers = Config::getParam('oAuthProviders', []);
        $providerValues = $raw->getAttribute('oAuthProviders', []);
        $projectProviders = [];

        foreach ($providers as $key => $provider) {
            if (!($provider['enabled'] ?? false)) {
                continue;
            }

            $projectProviders[] = [
                'key' => $key,
                'name' => $provider['name'] ?? '',
                'appId' => $providerValues[$key . 'Appid'] ?? '',
                'secret' => '',
                'enabled' => $providerValues[$key . 'Enabled'] ?? false,
            ];
        }

        return $projectProviders;
    }
}

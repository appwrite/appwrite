<?php

namespace Appwrite\Messaging;

use Appwrite\Network\Validator\PublicHostname;
use Appwrite\SMTP\Validator\Host;
use Utopia\Database\Document;
use Utopia\Database\Id;
use Utopia\DSN\DSN;
use Utopia\Messaging\Adapter\Email as EmailAdapter;
use Utopia\Messaging\Adapter\Email\Mailgun;
use Utopia\Messaging\Adapter\Email\Resend;
use Utopia\Messaging\Adapter\Email\Sendgrid;
use Utopia\Messaging\Adapter\Email\SES;
use Utopia\Messaging\Adapter\Email\SMTP;
use Utopia\Messaging\Adapter\Push\APNS;
use Utopia\Messaging\Adapter\Push as PushAdapter;
use Utopia\Messaging\Adapter\Push\FCM;
use Utopia\Messaging\Adapter\Push\Mock as PushMock;
use Utopia\Messaging\Adapter\SMS as SMSAdapter;
use Utopia\Messaging\Adapter\SMS\Bird;
use Utopia\Messaging\Adapter\SMS\Fast2SMS;
use Utopia\Messaging\Adapter\SMS\GEOSMS;
use Utopia\Messaging\Adapter\SMS\Inforu;
use Utopia\Messaging\Adapter\SMS\Mock;
use Utopia\Messaging\Adapter\SMS\Msg91;
use Utopia\Messaging\Adapter\SMS\Telesign;
use Utopia\Messaging\Adapter\SMS\TextMagic;
use Utopia\Messaging\Adapter\SMS\Twilio;
use Utopia\Messaging\Adapter\SMS\Vonage;
use Utopia\System\System;
use Utopia\Telemetry\Adapter as Telemetry;

/**
 * Builds the adapter a messaging provider sends through.
 *
 * A provider is either one a project stored as a document or the platform's own, described
 * by _APP_SMS_PROVIDER. Both reach the same adapter, so the DSN becomes a provider document
 * and takes the same path.
 *
 * A project's push providers are absent on purpose: the Appwrite provider needs the message
 * and the project database, so a provider document alone does not describe it. The platform's
 * own push, which alerts console users' devices, is described by _APP_NOTIFICATIONS_PUSH_APNS
 * and _APP_NOTIFICATIONS_PUSH_FCM.
 */
class Provider
{
    public function __construct(
        private readonly Telemetry $telemetry,
        private readonly PublicHostname $hostname,
    ) {
    }

    public function sms(Document $provider): ?SMSAdapter
    {
        $credentials = $provider->getAttribute('credentials');
        $options = $provider->getAttribute('options', []);

        $adapter = match ($provider->getAttribute('provider')) {
            'mock' => (new Mock('username', 'password'))->setEndpoint('http://request-catcher-sms:5000/'),
            'twilio' => new Twilio(
                $credentials['accountSid'] ?? '',
                $credentials['authToken'] ?? '',
                null,
                $credentials['messagingServiceSid'] ?? null
            ),
            'textmagic' => new TextMagic(
                $credentials['username'] ?? '',
                $credentials['apiKey'] ?? ''
            ),
            'telesign' => new Telesign(
                $credentials['customerId'] ?? '',
                $credentials['apiKey'] ?? ''
            ),
            'msg91' => new Msg91(
                $credentials['senderId'] ?? '',
                $credentials['authKey'] ?? '',
                $credentials['templateId'] ?? ''
            ),
            'vonage' => new Vonage(
                $credentials['apiKey'] ?? '',
                $credentials['apiSecret'] ??  ''
            ),
            'fast2sms' => new Fast2SMS(
                $credentials['apiKey'] ?? '',
                $credentials['senderId'] ?? '',
                $credentials['messageId'] ?? '',
                $credentials['useDLT'] ?? true
            ),
            'inforu' => new Inforu(
                $credentials['senderId'] ?? '',
                $credentials['apiKey'] ?? '',
            ),
            'bird' => !empty($credentials['apiKey']) ? new Bird(
                $credentials['apiKey'],
                $options['from'] ?? null
            ) : null,
            default => null
        };

        if ($adapter !== null) {
            $adapter->setTelemetry($this->telemetry);
        }

        return $adapter;
    }

    public function email(Document $provider): ?EmailAdapter
    {
        $credentials = $provider->getAttribute('credentials', []);
        $options = $provider->getAttribute('options', []);
        $apiKey = $credentials['apiKey'] ?? '';

        $adapter = match ($provider->getAttribute('provider')) {
            'mock' => new Mock('username', 'password'),
            'smtp' => new SMTP(
                $credentials['host'] ?? '',
                $credentials['port'] ?? 25,
                $credentials['username'] ?? '',
                $credentials['password'] ?? '',
                $options['encryption'] ?? '',
                $options['autoTLS'] ?? false,
                $options['mailer'] ?? '',
                addresses: new Host($this->hostname)->addresses($credentials['host'] ?? ''),
            ),
            'mailgun' => new Mailgun(
                $apiKey,
                $credentials['domain'] ?? '',
                $credentials['isEuRegion'] ?? false
            ),
            'sendgrid' => new Sendgrid($apiKey),
            'resend' => new Resend($apiKey),
            'ses' => new SES(
                $credentials['accessKey'] ?? '',
                $credentials['secretKey'] ?? '',
                $credentials['region'] ?? '',
                $credentials['sessionToken'] ?? null,
            ),
            default => null
        };

        if ($adapter !== null) {
            $adapter->setTelemetry($this->telemetry);
        }

        return $adapter;
    }

    public function internalSMS(): ?SMSAdapter
    {
        if (empty(System::getEnv('_APP_SMS_PROVIDER')) || empty(System::getEnv('_APP_SMS_FROM'))) {
            return null;
        }

        $providers = System::getEnv('_APP_SMS_PROVIDER', '');

        $dsns = [];
        if (!empty($providers)) {
            $providers = explode(',', $providers);
            foreach ($providers as $provider) {
                $dsns[] = new DSN($provider);
            }
        }

        if (count($dsns) === 1) {
            $provider = $this->fromDSN($dsns[0]);
            $adapter = $this->sms($provider);
            return $adapter;
        }

        $defaultDSN = null;
        $localDSNs = [];

        /** @var DSN $dsn */
        foreach ($dsns as $dsn) {
            if ($dsn->getParam('local', '') === 'default') {
                $defaultDSN = $dsn;
            } else {
                $localDSNs[] = $dsn;
            }
        }

        if ($defaultDSN === null) {
            throw new \Exception('No default SMS provider found');
        }

        $defaultProvider = $this->fromDSN($defaultDSN);
        $adapter = $this->sms($defaultProvider);
        $geosms = new GEOSMS($adapter);
        $geosms->setTelemetry($this->telemetry);

        /** @var DSN $localDSN */
        foreach ($localDSNs as $localDSN) {
            try {
                $provider = $this->fromDSN($localDSN);
                $adapter = $this->sms($provider);
            } catch (\Exception) {
                continue;
            }

            $callingCode = $localDSN->getParam('local', '');
            if (empty($callingCode)) {
                continue;
            }

            $geosms->setLocal($callingCode, $adapter);
        }
        return $geosms;
    }

    /**
     * The adapter console notifications push to `$service`'s devices through, from its variable:
     * `_APP_NOTIFICATIONS_PUSH_APNS` as `apns://[KEY_ID]:[TEAM_ID]@[BUNDLE_ID]?sandbox=true` with the
     * .p8 auth key in `_APP_NOTIFICATIONS_PUSH_APNS_KEY`, `_APP_NOTIFICATIONS_PUSH_FCM` as
     * `fcm://[FIREBASE_PROJECT_ID]` with the service account JSON in `_APP_NOTIFICATIONS_PUSH_FCM_KEY`,
     * or either as `mock://[HOST]:[PORT]/[PATH]` to post every push to a request catcher. Unset
     * disables pushes to that service's devices.
     */
    public function internalPush(PushService $service): ?PushAdapter
    {
        $value = System::getEnv($service->variable(), '');
        if ($value === '') {
            return null;
        }

        $dsn = new DSN($value);
        $key = System::getEnv($service->variable() . '_KEY', '');

        $adapter = match ([$service, $dsn->getScheme()]) {
            [PushService::APNS, 'apns'] => new APNS(
                authKey: $key,
                authKeyId: (string) $dsn->getUser(),
                teamId: (string) $dsn->getPassword(),
                bundleId: $dsn->getHost(),
                sandbox: $dsn->getParam('sandbox') === 'true',
            ),
            [PushService::FCM, 'fcm'] => $this->fcm($dsn->getHost(), $key),
            [PushService::APNS, 'mock'], [PushService::FCM, 'mock'] => (new PushMock())->setEndpoint('http://' . $dsn->getHost() . ($dsn->getPort() ? ':' . $dsn->getPort() : '') . '/' . $dsn->getPath()),
            default => throw new \InvalidArgumentException('Unsupported push adapter for ' . $service->variable() . ': ' . $dsn->getScheme()),
        };

        $adapter->setTelemetry($this->telemetry);

        return $adapter;
    }

    /**
     * The FCM adapter for the service account in `$key`, which has to belong to the Firebase
     * project the DSN names, so a key pasted from another project fails at boot, not on every push.
     */
    private function fcm(string $project, string $key): FCM
    {
        $account = \json_decode($key, true);
        if (!\is_array($account) || ($account['project_id'] ?? null) !== $project) {
            throw new \InvalidArgumentException('_APP_NOTIFICATIONS_PUSH_FCM_KEY is not a service account of the Firebase project ' . $project);
        }

        return new FCM($key);
    }

    private function fromDSN(DSN $dsn): Document
    {
        $host = $dsn->getHost();
        $password = $dsn->getPassword();
        $user = $dsn->getUser();
        $from = System::getEnv('_APP_SMS_FROM');

        $provider = new Document([
            '$id' => Id::unique(),
            'provider' => $host,
            'type' => MESSAGE_TYPE_SMS,
            'name' => 'Internal SMS',
            'enabled' => true,
            'credentials' => match ($host) {
                'twilio' => [
                    'accountSid' => $user,
                    'authToken' => $password,
                    // Messaging Service SIDs are always 34 characters; alphanumeric sender IDs, at most 11, can also start with MG
                    // https://www.twilio.com/docs/messaging/api/service-resource
                    'messagingServiceSid' => \str_starts_with($from, 'MG') && \strlen($from) === 34 ? $from : null
                ],
                'textmagic' => [
                    'username' => $user,
                    'apiKey' => $password
                ],
                'telesign' => [
                    'customerId' => $user,
                    'apiKey' => $password
                ],
                'msg91' => [
                    'senderId' => $user,
                    'authKey' => $password,
                    'templateId' => $dsn->getParam('templateId', $from),
                ],
                'vonage' => [
                    'apiKey' => $user,
                    'apiSecret' => $password
                ],
                'fast2sms' => [
                    'senderId' => $user,
                    'apiKey' => $password,
                    'messageId' => $dsn->getParam('messageId'),
                    'useDLT' => $dsn->getParam('useDLT'),
                ],
                'inforu' => [
                    'senderId' => $user,
                    'apiKey' => $password,
                ],
                default => null
            },
            'options' => match ($host) {
                'twilio' => [
                    'from' => \str_starts_with($from, 'MG') && \strlen($from) === 34 ? null : $from
                ],
                default => [
                    'from' => $from
                ]
            }
        ]);

        return $provider;
    }
}

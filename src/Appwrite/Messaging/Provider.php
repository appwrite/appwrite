<?php

namespace Appwrite\Messaging;

use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
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
use Utopia\Messaging\Adapter\SMS as SMSAdapter;
use Utopia\Messaging\Adapter\SMS\Fast2SMS;
use Utopia\Messaging\Adapter\SMS\GEOSMS;
use Utopia\Messaging\Adapter\SMS\Inforu;
use Utopia\Messaging\Adapter\SMS\Mock;
use Utopia\Messaging\Adapter\SMS\Msg91;
use Utopia\Messaging\Adapter\SMS\Telesign;
use Utopia\Messaging\Adapter\SMS\TextMagic;
use Utopia\Messaging\Adapter\SMS\Twilio;
use Utopia\Messaging\Adapter\SMS\Vonage;
use Utopia\Messaging\Adapter\SMS\WhatsApp;
use Utopia\System\System;
use Utopia\Telemetry\Adapter as Telemetry;

/**
 * Builds the sending adapter a messaging provider needs.
 *
 * A provider is either one a project configured and stored as a document, or the
 * platform's own, described by the _APP_SMS_PROVIDER and _APP_WHATSAPP_PROVIDER
 * environment variables and used for the one-time passcodes and invites Appwrite
 * sends on a project's behalf. Both end up as the same adapter, so the DSN is
 * turned into a provider document and takes the same path.
 */
class Provider
{
    public function __construct(private readonly Telemetry $telemetry)
    {
    }

    public function sms(Document $provider): ?SMSAdapter
    {
        $credentials = $provider->getAttribute('credentials');

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
            'whatsapp' => new WhatsApp(
                $credentials['accessToken'] ?? '',
                $credentials['phoneNumberId'] ?? '',
                $credentials['template'] ?? '',
                $credentials['language'] ?? WhatsApp::DEFAULT_LANGUAGE,
            ),
            default => null
        };

        if ($adapter !== null) {
            $adapter->setTelemetry($this->telemetry);
        }

        return $adapter;
    }

    public function push(Document $provider): ?PushAdapter
    {
        $credentials = $provider->getAttribute('credentials');
        $options = $provider->getAttribute('options');

        $adapter = match ($provider->getAttribute('provider')) {
            'mock' => new Mock('username', 'password'),
            'apns' => new APNS(
                $credentials['authKey'] ?? '',
                $credentials['authKeyId'] ?? '',
                $credentials['teamId'] ?? '',
                $credentials['bundleId'] ?? '',
                $options['sandbox'] ?? false
            ),
            'fcm' => new FCM(\json_encode($credentials['serviceAccountJSON'])),
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
                $credentials['host'] ??  '',
                $credentials['port'] ?? 25,
                $credentials['username'] ?? '',
                $credentials['password'] ?? '',
                $options['encryption'] ?? '',
                $options['autoTLS'] ??  false,
                $options['mailer'] ??  '',
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
     * Build the adapter that carries internal OTPs over WhatsApp. A single DSN, since one business number reaches every country.
     */
    public function internalWhatsApp(): ?SMSAdapter
    {
        $provider = System::getEnv('_APP_WHATSAPP_PROVIDER', '');

        if (empty($provider)) {
            return null;
        }

        $dsn = new DSN($provider);

        // The mock username lets e2e tests tell the two channels apart at the request catcher.
        $adapter = $dsn->getHost() === 'mock'
            ? (new Mock($dsn->getUser() ?? '', $dsn->getPassword() ?? ''))->setEndpoint('http://request-catcher-sms:5000/')
            : $this->sms($this->fromDSN($dsn));

        $adapter?->setTelemetry($this->telemetry);

        return $adapter;
    }

    private function fromDSN(DSN $dsn): Document
    {
        $host = $dsn->getHost();
        $password = $dsn->getPassword();
        $user = $dsn->getUser();
        // WhatsApp sends from the phone number behind the DSN's phone number ID, so a
        // deployment that only configures WhatsApp never sets a sender.
        $from = System::getEnv('_APP_SMS_FROM', '');

        $provider = new Document([
            '$id' => ID::unique(),
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
                'whatsapp' => [
                    'phoneNumberId' => $user,
                    'accessToken' => $password,
                    'template' => $dsn->getParam('template'),
                    'language' => $dsn->getParam('language', WhatsApp::DEFAULT_LANGUAGE),
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

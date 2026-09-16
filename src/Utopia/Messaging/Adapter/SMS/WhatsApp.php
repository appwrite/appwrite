<?php

declare(strict_types=1);

namespace Utopia\Messaging\Adapter\SMS;

use Closure;
use Psr\Http\Client\ClientInterface;
use Utopia\Messaging\Adapter\SMS as SMSAdapter;
use Utopia\Messaging\Adapter\SMS\WhatsApp\App;
use Utopia\Messaging\Adapter\SMS\WhatsApp\MetadataParameter;
use Utopia\Messaging\Adapter\SMS\WhatsApp\OtpType;
use Utopia\Messaging\Messages\SMS as SMSMessage;
use Utopia\Messaging\Response;

// Reference Material
// https://developers.facebook.com/documentation/business-messaging/whatsapp/templates/authentication-templates/authentication-templates/
// https://developers.facebook.com/docs/whatsapp/cloud-api/reference/messages/
// https://developers.facebook.com/docs/whatsapp/cloud-api/support/error-codes
// https://developers.facebook.com/docs/whatsapp/business-management-api/message-templates

/**
 * Delivers one-time passcodes over WhatsApp with a Meta Cloud API authentication template.
 *
 * Authentication templates carry a fixed body chosen by Meta, so the message content
 * is the code itself, not free text. The code appears twice in the request, once in
 * the body and once in the copy-code button, as the Cloud API requires.
 */
class WhatsApp extends SMSAdapter
{
    protected const NAME = 'WhatsApp';

    public const string DEFAULT_LANGUAGE = 'en_US';

    public const string DEFAULT_VERSION = 'v26.0';

    /**
     * Longest code an authentication template accepts.
     */
    public const int CODE_MAX_LENGTH = 15;

    /**
     * Longest validity an authentication template footer can state, in minutes.
     */
    public const int EXPIRATION_MAX_MINUTES = 90;

    /**
     * Shortest and longest time an undelivered message may wait, in seconds.
     */
    public const int TIME_TO_LIVE_MIN_SECONDS = 60;

    public const int TIME_TO_LIVE_MAX_SECONDS = 600;

    /**
     * Sentinel asking Meta to keep an undelivered message for a full day.
     */
    public const int TIME_TO_LIVE_DAY = -1;

    /**
     * Longest correlation string Meta echoes back in status webhooks.
     */
    public const int CALLBACK_DATA_MAX_LENGTH = 512;

    /**
     * Longest template name Meta accepts.
     */
    public const int TEMPLATE_NAME_MAX_LENGTH = 512;

    /**
     * Most Android apps one template can hand the code to.
     */
    public const int APPS_MAX = 5;

    private const string ENDPOINT = 'https://graph.facebook.com';

    private const string PRODUCT = 'whatsapp';

    private const string CATEGORY = 'authentication';

    private const string BUTTON_TYPE = 'otp';

    private const string BUTTON_SUB_TYPE = 'url';

    private const string CODE_PATTERN = '/^[A-Za-z0-9]{1,15}$/';

    private const string TEMPLATE_NAME_PATTERN = '/^[a-z0-9_]{1,512}$/';

    private const string TEMPLATE_FIELDS = 'id,name,language,status,category';

    /**
     * @param  string  $accessToken System User access token with the `whatsapp_business_messaging` permission.
     * @param  string  $phoneNumberId ID of the business phone number that sends the code.
     * @param  string  $template Name of an approved authentication template. Must be lowercase letters, digits and underscores.
     * @param  string  $language Template language code the template was created in.
     * @param  string  $version Graph API version to call.
     * @param  (Closure(): ClientInterface)|null  $clientFactory Factory for the PSR-18 client used to reach the Graph API; defaults to cURL.
     */
    public function __construct(
        private readonly string $accessToken,
        private readonly string $phoneNumberId,
        private readonly string $template,
        private readonly string $language = self::DEFAULT_LANGUAGE,
        private readonly string $version = self::DEFAULT_VERSION,
        ?Closure $clientFactory = null,
    ) {
        $this->assertTemplateName($template);

        parent::__construct(clientFactory: $clientFactory);
    }

    public function getName(): string
    {
        return static::NAME;
    }

    public function getMaxMessagesPerRequest(): int
    {
        return 1;
    }

    /**
     * Create or update the authentication template in every given language.
     *
     * Meta fixes the body text of authentication templates and approves them
     * without review, so the response normally reports each language as approved.
     *
     * @param  string  $businessAccountId WhatsApp Business Account ID that owns the template.
     * @param  array<string>  $languages Language codes to create the template in; empty creates every supported language.
     * @param  int|null  $expirationMinutes Validity stated in the footer, between 1 and 90; null omits the footer.
     * @param  bool  $securityRecommendation Whether the body tells the recipient not to share the code.
     * @param  int|null  $timeToLive Seconds after which an undelivered message is dropped, between 60 and 600, or -1 for 24 hours.
     * @param  OtpType  $otpType How the recipient moves the code into the app; one-tap and zero-tap need at least one app.
     * @param  array<App>  $apps Android apps that may receive the code through one-tap or zero-tap autofill, at most five.
     * @return array<string, mixed> Decoded Graph API response.
     *
     * @throws \InvalidArgumentException If an option is outside what Meta accepts.
     * @throws \RuntimeException If the Graph API rejects the request.
     */
    public function upsertTemplate(
        string $businessAccountId,
        array $languages = [],
        ?int $expirationMinutes = null,
        bool $securityRecommendation = true,
        ?int $timeToLive = null,
        OtpType $otpType = OtpType::COPY_CODE,
        array $apps = [],
    ): array {
        if ($expirationMinutes !== null && ($expirationMinutes < 1 || $expirationMinutes > self::EXPIRATION_MAX_MINUTES)) {
            throw new \InvalidArgumentException('WhatsApp code expiration must be between 1 and ' . self::EXPIRATION_MAX_MINUTES . ' minutes.');
        }

        if ($timeToLive !== null && $timeToLive !== self::TIME_TO_LIVE_DAY && ($timeToLive < self::TIME_TO_LIVE_MIN_SECONDS || $timeToLive > self::TIME_TO_LIVE_MAX_SECONDS)) {
            throw new \InvalidArgumentException('WhatsApp time to live must be between ' . self::TIME_TO_LIVE_MIN_SECONDS . ' and ' . self::TIME_TO_LIVE_MAX_SECONDS . ' seconds, or ' . self::TIME_TO_LIVE_DAY . ' for 24 hours.');
        }

        if ($otpType->requiresApps() && $apps === []) {
            throw new \InvalidArgumentException('WhatsApp ' . $otpType->value . ' templates must name at least one app that receives the code.');
        }

        if (\count($apps) > self::APPS_MAX) {
            throw new \InvalidArgumentException('WhatsApp templates may name at most ' . self::APPS_MAX . ' apps.');
        }

        $components = [
            [
                'type' => 'body',
                'add_security_recommendation' => $securityRecommendation,
            ],
        ];

        if ($expirationMinutes !== null) {
            $components[] = [
                'type' => 'footer',
                'code_expiration_minutes' => $expirationMinutes,
            ];
        }

        $button = [
            'type' => self::BUTTON_TYPE,
            'otp_type' => $otpType->value,
        ];

        if ($apps !== []) {
            $button['supported_apps'] = array_map(static fn(App $app): array => $app->toArray(), array_values($apps));
        }

        if ($otpType === OtpType::ZERO_TAP) {
            $button['zero_tap_terms_accepted'] = true;
        }

        $components[] = [
            'type' => 'buttons',
            'buttons' => [$button],
        ];

        $body = [
            'name' => $this->template,
            'category' => self::CATEGORY,
            'components' => $components,
        ];

        if ($languages !== []) {
            $body['languages'] = array_values($languages);
        }

        if ($timeToLive !== null) {
            $body['message_send_ttl_seconds'] = $timeToLive;
        }

        $result = $this->request(
            method: 'POST',
            url: $this->url($businessAccountId, 'upsert_message_templates'),
            headers: $this->headers(),
            body: $body,
        );

        if ($result['statusCode'] < 200 || $result['statusCode'] >= 300) {
            throw new \RuntimeException($this->error($result));
        }

        return \is_array($result['response']) ? $result['response'] : [];
    }

    /**
     * List every language of a template with its approval status.
     *
     * @param  string  $businessAccountId WhatsApp Business Account ID that owns the template.
     * @param  string|null  $name Template name; defaults to the adapter's template.
     * @return array<int, array{id: string, name: string, language: string, status: string, category: string}>
     *
     * @throws \InvalidArgumentException If the name is not a valid template name.
     * @throws \RuntimeException If the Graph API rejects the request.
     */
    public function getTemplate(string $businessAccountId, ?string $name = null): array
    {
        $name ??= $this->template;
        $this->assertTemplateName($name);

        $result = $this->request(
            method: 'GET',
            url: $this->url($businessAccountId, 'message_templates') . '?' . http_build_query([
                'name' => $name,
                'fields' => self::TEMPLATE_FIELDS,
            ]),
            headers: $this->headers(),
        );

        if ($result['statusCode'] < 200 || $result['statusCode'] >= 300) {
            throw new \RuntimeException($this->error($result));
        }

        $data = \is_array($result['response']) ? ($result['response']['data'] ?? []) : [];

        return \is_array($data) ? array_values($data) : [];
    }

    /**
     * Delete a template in every language it exists in.
     *
     * @param  string  $businessAccountId WhatsApp Business Account ID that owns the template.
     * @param  string|null  $name Template name; defaults to the adapter's template.
     *
     * @throws \InvalidArgumentException If the name is not a valid template name.
     * @throws \RuntimeException If the Graph API rejects the request.
     */
    public function deleteTemplate(string $businessAccountId, ?string $name = null): void
    {
        $name ??= $this->template;
        $this->assertTemplateName($name);

        $result = $this->request(
            method: 'DELETE',
            url: $this->url($businessAccountId, 'message_templates') . '?' . http_build_query(['name' => $name]),
            headers: $this->headers(),
        );

        if ($result['statusCode'] < 200 || $result['statusCode'] >= 300) {
            throw new \RuntimeException($this->error($result));
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function process(SMSMessage $message): array
    {
        $code = $message->getContent();

        if (preg_match(self::CODE_PATTERN, $code) !== 1) {
            throw new \InvalidArgumentException('WhatsApp authentication templates only accept a code of up to ' . self::CODE_MAX_LENGTH . ' letters and digits as the message content.');
        }

        $metadata = $message->getMetadata() ?? [];
        $metadata = array_intersect_key($metadata, array_flip(array_column(MetadataParameter::cases(), 'value')));

        foreach ($metadata as $key => $value) {
            if (!\is_string($value) || $value === '') {
                throw new \InvalidArgumentException("WhatsApp {$key} metadata must be a non-empty string.");
            }
        }

        $language = $metadata[MetadataParameter::LANGUAGE->value] ?? $this->language;
        $template = $metadata[MetadataParameter::TEMPLATE->value] ?? $this->template;
        $callbackData = $metadata[MetadataParameter::CALLBACK_DATA->value] ?? null;

        $this->assertTemplateName($template);

        if ($callbackData !== null && \strlen($callbackData) > self::CALLBACK_DATA_MAX_LENGTH) {
            throw new \InvalidArgumentException('WhatsApp callback data must be at most ' . self::CALLBACK_DATA_MAX_LENGTH . ' characters.');
        }

        $to = $message->getTo()[0] ?? null;

        if (!\is_string($to) || $to === '') {
            throw new \InvalidArgumentException('WhatsApp requires exactly one recipient phone number.');
        }

        $body = [
            'messaging_product' => self::PRODUCT,
            'recipient_type' => 'individual',
            'to' => $this->normalize($to),
            'type' => 'template',
            'template' => [
                'name' => $template,
                'language' => [
                    'code' => $language,
                ],
                'components' => [
                    [
                        'type' => 'body',
                        'parameters' => [
                            [
                                'type' => 'text',
                                'text' => $code,
                            ],
                        ],
                    ],
                    [
                        'type' => 'button',
                        'sub_type' => self::BUTTON_SUB_TYPE,
                        'index' => '0',
                        'parameters' => [
                            [
                                'type' => 'text',
                                'text' => $code,
                            ],
                        ],
                    ],
                ],
            ],
        ];

        if ($callbackData !== null) {
            $body['biz_opaque_callback_data'] = $callbackData;
        }

        $result = $this->request(
            method: 'POST',
            url: $this->url($this->phoneNumberId, 'messages'),
            headers: $this->headers(),
            body: $body,
        );

        $response = new Response($this->getType());

        if ($result['statusCode'] >= 200 && $result['statusCode'] < 300) {
            $response->setDeliveredTo(1);
            $response->addResult($to);
        } else {
            $response->addResult($to, $this->error($result));
        }

        return $response->toArray();
    }

    /**
     * @throws \InvalidArgumentException If the name is not lowercase letters, digits and underscores.
     */
    private function assertTemplateName(string $name): void
    {
        if (preg_match(self::TEMPLATE_NAME_PATTERN, $name) !== 1) {
            throw new \InvalidArgumentException('WhatsApp template names must be 1 to ' . self::TEMPLATE_NAME_MAX_LENGTH . ' lowercase letters, digits and underscores.');
        }
    }

    /**
     * The Cloud API wants the number as digits only, country code first, without a plus sign.
     */
    private function normalize(string $number): string
    {
        return preg_replace('/\D+/', '', $number) ?? '';
    }

    private function url(string $id, string $edge): string
    {
        return self::ENDPOINT . '/' . $this->version . '/' . $id . '/' . $edge;
    }

    /**
     * @return array<string>
     */
    private function headers(): array
    {
        return [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->accessToken,
        ];
    }

    /**
     * Turn a Graph API error into one line keeping the numeric code, which is
     * what distinguishes retryable throughput limits from configuration mistakes.
     *
     * @param  array{statusCode: int, response: array<string, mixed>|string|null, error: string}  $result
     */
    private function error(array $result): string
    {
        if ($result['statusCode'] === 0) {
            return $result['error'] !== '' ? $result['error'] : 'Unknown error';
        }

        $error = \is_array($result['response']) ? ($result['response']['error'] ?? null) : null;

        if (!\is_array($error)) {
            return 'Unknown error';
        }

        $parts = [];

        if (isset($error['code'])) {
            $parts[] = 'Error ' . $error['code'];
        }

        if (isset($error['message']) && \is_string($error['message'])) {
            $parts[] = $error['message'];
        }

        $details = $error['error_data']['details'] ?? null;

        if (\is_string($details) && $details !== '' && !\in_array($details, $parts, true)) {
            $parts[] = $details;
        }

        return $parts === [] ? 'Unknown error' : implode(': ', $parts);
    }
}

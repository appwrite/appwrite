<?php

namespace Appwrite\Platform\Modules\Project\Services;

use Appwrite\Platform\Modules\Project\Http\Init;
use Appwrite\Platform\Modules\Project\Http\Project\AuthMethods\Update as UpdateAuthMethod;
use Appwrite\Platform\Modules\Project\Http\Project\Delete as DeleteProject;
use Appwrite\Platform\Modules\Project\Http\Project\Get as GetProject;
use Appwrite\Platform\Modules\Project\Http\Project\Keys\Create as CreateKey;
use Appwrite\Platform\Modules\Project\Http\Project\Keys\Delete as DeleteKey;
use Appwrite\Platform\Modules\Project\Http\Project\Keys\Ephemeral\Create as CreateEphemeralKey;
use Appwrite\Platform\Modules\Project\Http\Project\Keys\Get as GetKey;
use Appwrite\Platform\Modules\Project\Http\Project\Keys\Update as UpdateKey;
use Appwrite\Platform\Modules\Project\Http\Project\Keys\XList as ListKeys;
use Appwrite\Platform\Modules\Project\Http\Project\Labels\Update as UpdateProjectLabels;
use Appwrite\Platform\Modules\Project\Http\Project\MockPhone\Create as CreateMockPhone;
use Appwrite\Platform\Modules\Project\Http\Project\MockPhone\Delete as DeleteMockPhone;
use Appwrite\Platform\Modules\Project\Http\Project\MockPhone\Get as GetMockPhone;
use Appwrite\Platform\Modules\Project\Http\Project\MockPhone\Update as UpdateMockPhone;
use Appwrite\Platform\Modules\Project\Http\Project\MockPhone\XList as ListMockPhones;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Amazon\Update as UpdateOAuth2Amazon;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Apple\Update as UpdateOAuth2Apple;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Appwrite\Update as UpdateOAuth2Appwrite;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Auth0\Update as UpdateOAuth2Auth0;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Authentik\Update as UpdateOAuth2Authentik;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Autodesk\Update as UpdateOAuth2Autodesk;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Bitbucket\Update as UpdateOAuth2Bitbucket;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Bitly\Update as UpdateOAuth2Bitly;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Box\Update as UpdateOAuth2Box;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Cloudflare\Update as UpdateOAuth2Cloudflare;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Dailymotion\Update as UpdateOAuth2Dailymotion;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Discord\Update as UpdateOAuth2Discord;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Disqus\Update as UpdateOAuth2Disqus;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Dropbox\Update as UpdateOAuth2Dropbox;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Etsy\Update as UpdateOAuth2Etsy;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Facebook\Update as UpdateOAuth2Facebook;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Figma\Update as UpdateOAuth2Figma;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\FusionAuth\Update as UpdateOAuth2FusionAuth;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Get as GetOAuth2Provider;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\GitHub\Update as UpdateOAuth2GitHub;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Gitlab\Update as UpdateOAuth2Gitlab;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Google\Update as UpdateOAuth2Google;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\HuggingFace\Update as UpdateOAuth2HuggingFace;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Kakao\Update as UpdateOAuth2Kakao;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Keycloak\Update as UpdateOAuth2Keycloak;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Kick\Update as UpdateOAuth2Kick;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Linkedin\Update as UpdateOAuth2Linkedin;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Microsoft\Update as UpdateOAuth2Microsoft;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Notion\Update as UpdateOAuth2Notion;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Oidc\Update as UpdateOAuth2Oidc;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Okta\Update as UpdateOAuth2Okta;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Paypal\Update as UpdateOAuth2Paypal;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\PaypalSandbox\Update as UpdateOAuth2PaypalSandbox;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Podio\Update as UpdateOAuth2Podio;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Resend\Update as UpdateOAuth2Resend;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Salesforce\Update as UpdateOAuth2Salesforce;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Slack\Update as UpdateOAuth2Slack;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Spotify\Update as UpdateOAuth2Spotify;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Stripe\Update as UpdateOAuth2Stripe;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\TikTok\Update as UpdateOAuth2TikTok;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Tradeshift\Update as UpdateOAuth2Tradeshift;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\TradeshiftSandbox\Update as UpdateOAuth2TradeshiftSandbox;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Twitch\Update as UpdateOAuth2Twitch;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Webflow\Update as UpdateOAuth2Webflow;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\WordPress\Update as UpdateOAuth2WordPress;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\X\Update as UpdateOAuth2X;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\XList as ListOAuth2Providers;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Yahoo\Update as UpdateOAuth2Yahoo;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Yandex\Update as UpdateOAuth2Yandex;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Zoho\Update as UpdateOAuth2Zoho;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Zoom\Update as UpdateOAuth2Zoom;
use Appwrite\Platform\Modules\Project\Http\Project\Platforms\Android\Create as CreateAndroidPlatform;
use Appwrite\Platform\Modules\Project\Http\Project\Platforms\Android\Update as UpdateAndroidPlatform;
use Appwrite\Platform\Modules\Project\Http\Project\Platforms\Apple\Create as CreateApplePlatform;
use Appwrite\Platform\Modules\Project\Http\Project\Platforms\Apple\Update as UpdateApplePlatform;
use Appwrite\Platform\Modules\Project\Http\Project\Platforms\Delete as DeletePlatform;
use Appwrite\Platform\Modules\Project\Http\Project\Platforms\Get as GetPlatform;
use Appwrite\Platform\Modules\Project\Http\Project\Platforms\Linux\Create as CreateLinuxPlatform;
use Appwrite\Platform\Modules\Project\Http\Project\Platforms\Linux\Update as UpdateLinuxPlatform;
use Appwrite\Platform\Modules\Project\Http\Project\Platforms\Web\Create as CreateWebPlatform;
use Appwrite\Platform\Modules\Project\Http\Project\Platforms\Web\Update as UpdateWebPlatform;
use Appwrite\Platform\Modules\Project\Http\Project\Platforms\Windows\Create as CreateWindowsPlatform;
use Appwrite\Platform\Modules\Project\Http\Project\Platforms\Windows\Update as UpdateWindowsPlatform;
use Appwrite\Platform\Modules\Project\Http\Project\Platforms\XList as ListPlatforms;
use Appwrite\Platform\Modules\Project\Http\Project\Policies\DenyAliasedEmail\Update as UpdateDenyAliasedEmailPolicy;
use Appwrite\Platform\Modules\Project\Http\Project\Policies\DenyCorporateEmail\Update as UpdateDenyCorporateEmailPolicy;
use Appwrite\Platform\Modules\Project\Http\Project\Policies\DenyDisposableEmail\Update as UpdateDenyDisposableEmailPolicy;
use Appwrite\Platform\Modules\Project\Http\Project\Policies\DenyFreeEmail\Update as UpdateDenyFreeEmailPolicy;
use Appwrite\Platform\Modules\Project\Http\Project\Policies\DenyRealtimeGuests\Update as UpdateDenyRealtimeGuestsPolicy;
use Appwrite\Platform\Modules\Project\Http\Project\Policies\Get as GetPolicy;
use Appwrite\Platform\Modules\Project\Http\Project\Policies\MembershipPrivacy\Update as UpdateMembershipPrivacyPolicy;
use Appwrite\Platform\Modules\Project\Http\Project\Policies\MFAFactors\Update as UpdateMFAFactorsPolicy;
use Appwrite\Platform\Modules\Project\Http\Project\Policies\Passkey\Update as UpdatePasskeyPolicy;
use Appwrite\Platform\Modules\Project\Http\Project\Policies\PasswordDictionary\Update as UpdatePasswordDictionaryPolicy;
use Appwrite\Platform\Modules\Project\Http\Project\Policies\PasswordHistory\Update as UpdatePasswordHistoryPolicy;
use Appwrite\Platform\Modules\Project\Http\Project\Policies\PasswordPersonalData\Update as UpdatePasswordPersonalDataPolicy;
use Appwrite\Platform\Modules\Project\Http\Project\Policies\PasswordPwned\Update as UpdatePasswordPwnedPolicy;
use Appwrite\Platform\Modules\Project\Http\Project\Policies\PasswordRotation\Update as UpdatePasswordRotationPolicy;
use Appwrite\Platform\Modules\Project\Http\Project\Policies\PasswordStrength\Update as UpdatePasswordStrengthPolicy;
use Appwrite\Platform\Modules\Project\Http\Project\Policies\SessionAlert\Update as UpdateSessionAlertPolicy;
use Appwrite\Platform\Modules\Project\Http\Project\Policies\SessionDuration\Update as UpdateSessionDurationPolicy;
use Appwrite\Platform\Modules\Project\Http\Project\Policies\SessionInvalidation\Update as UpdateSessionInvalidationPolicy;
use Appwrite\Platform\Modules\Project\Http\Project\Policies\SessionLimit\Update as UpdateSessionLimitPolicy;
use Appwrite\Platform\Modules\Project\Http\Project\Policies\UserLimit\Update as UpdateUserLimitPolicy;
use Appwrite\Platform\Modules\Project\Http\Project\Policies\XList as ListPolicies;
use Appwrite\Platform\Modules\Project\Http\Project\Prefs\Get as GetPrefs;
use Appwrite\Platform\Modules\Project\Http\Project\Prefs\Update as UpdatePrefs;
use Appwrite\Platform\Modules\Project\Http\Project\Protocols\Update as UpdateProjectProtocol;
use Appwrite\Platform\Modules\Project\Http\Project\Services\Update as UpdateProjectService;
use Appwrite\Platform\Modules\Project\Http\Project\Sessions\Delete as DeleteSessions;
use Appwrite\Platform\Modules\Project\Http\Project\SMTP\Tests\Create as CreateSMTPTest;
use Appwrite\Platform\Modules\Project\Http\Project\SMTP\Update as UpdateSMTP;
use Appwrite\Platform\Modules\Project\Http\Project\Templates\Email\Get as GetTemplate;
use Appwrite\Platform\Modules\Project\Http\Project\Templates\Email\Update as UpdateTemplate;
use Appwrite\Platform\Modules\Project\Http\Project\Templates\Email\XList as ListTemplates;
use Appwrite\Platform\Modules\Project\Http\Project\Variables\Create as CreateVariable;
use Appwrite\Platform\Modules\Project\Http\Project\Variables\Delete as DeleteVariable;
use Appwrite\Platform\Modules\Project\Http\Project\Variables\Get as GetVariable;
use Appwrite\Platform\Modules\Project\Http\Project\Variables\Update as UpdateVariable;
use Appwrite\Platform\Modules\Project\Http\Project\Variables\XList as ListVariables;
use Utopia\Platform\Service;

class Http extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_HTTP;

        // Hooks
        $this->addAction(Init::getName(), Init::class);

        // Project
        $this->addAction(DeleteProject::getName(), DeleteProject::class);
        $this->addAction(GetProject::getName(), GetProject::class);
        $this->addAction(UpdateProjectLabels::getName(), UpdateProjectLabels::class);
        $this->addAction(UpdateProjectProtocol::getName(), UpdateProjectProtocol::class);
        $this->addAction(UpdateProjectService::getName(), UpdateProjectService::class);

        // Prefs
        $this->addAction(GetPrefs::getName(), GetPrefs::class);
        $this->addAction(UpdatePrefs::getName(), UpdatePrefs::class);

        // Sessions
        $this->addAction(DeleteSessions::getName(), DeleteSessions::class);

        // SMTP
        $this->addAction(UpdateSMTP::getName(), UpdateSMTP::class);
        $this->addAction(CreateSMTPTest::getName(), CreateSMTPTest::class);

        // Templates
        $this->addAction(ListTemplates::getName(), ListTemplates::class);
        $this->addAction(GetTemplate::getName(), GetTemplate::class);
        $this->addAction(UpdateTemplate::getName(), UpdateTemplate::class);

        // Variables
        $this->addAction(CreateVariable::getName(), CreateVariable::class);
        $this->addAction(ListVariables::getName(), ListVariables::class);
        $this->addAction(GetVariable::getName(), GetVariable::class);
        $this->addAction(DeleteVariable::getName(), DeleteVariable::class);
        $this->addAction(UpdateVariable::getName(), UpdateVariable::class);

        // Keys
        // TODO: Remove once the Console, CLI and SDKs use the Organization API instead
        $this->addAction(CreateKey::getName(), CreateKey::class);
        $this->addAction(CreateEphemeralKey::getName(), CreateEphemeralKey::class);
        $this->addAction(ListKeys::getName(), ListKeys::class);
        $this->addAction(GetKey::getName(), GetKey::class);
        $this->addAction(DeleteKey::getName(), DeleteKey::class);
        $this->addAction(UpdateKey::getName(), UpdateKey::class);

        // Platforms
        $this->addAction(ListPlatforms::getName(), ListPlatforms::class);
        $this->addAction(GetPlatform::getName(), GetPlatform::class);
        $this->addAction(CreateWebPlatform::getName(), CreateWebPlatform::class);
        $this->addAction(CreateApplePlatform::getName(), CreateApplePlatform::class);
        $this->addAction(CreateAndroidPlatform::getName(), CreateAndroidPlatform::class);
        $this->addAction(CreateWindowsPlatform::getName(), CreateWindowsPlatform::class);
        $this->addAction(CreateLinuxPlatform::getName(), CreateLinuxPlatform::class);
        $this->addAction(UpdateWebPlatform::getName(), UpdateWebPlatform::class);
        $this->addAction(UpdateApplePlatform::getName(), UpdateApplePlatform::class);
        $this->addAction(UpdateAndroidPlatform::getName(), UpdateAndroidPlatform::class);
        $this->addAction(UpdateWindowsPlatform::getName(), UpdateWindowsPlatform::class);
        $this->addAction(UpdateLinuxPlatform::getName(), UpdateLinuxPlatform::class);
        $this->addAction(DeletePlatform::getName(), DeletePlatform::class);

        // Mock Phones
        $this->addAction(CreateMockPhone::getName(), CreateMockPhone::class);
        $this->addAction(ListMockPhones::getName(), ListMockPhones::class);
        $this->addAction(GetMockPhone::getName(), GetMockPhone::class);
        $this->addAction(UpdateMockPhone::getName(), UpdateMockPhone::class);
        $this->addAction(DeleteMockPhone::getName(), DeleteMockPhone::class);

        // Policies
        $this->addAction(ListPolicies::getName(), ListPolicies::class);
        $this->addAction(GetPolicy::getName(), GetPolicy::class);
        $this->addAction(UpdateMembershipPrivacyPolicy::getName(), UpdateMembershipPrivacyPolicy::class);
        $this->addAction(UpdateMFAFactorsPolicy::getName(), UpdateMFAFactorsPolicy::class);
        $this->addAction(UpdatePasskeyPolicy::getName(), UpdatePasskeyPolicy::class);
        $this->addAction(UpdatePasswordDictionaryPolicy::getName(), UpdatePasswordDictionaryPolicy::class);
        $this->addAction(UpdatePasswordHistoryPolicy::getName(), UpdatePasswordHistoryPolicy::class);
        $this->addAction(UpdatePasswordStrengthPolicy::getName(), UpdatePasswordStrengthPolicy::class);
        $this->addAction(UpdatePasswordPersonalDataPolicy::getName(), UpdatePasswordPersonalDataPolicy::class);
        $this->addAction(UpdatePasswordPwnedPolicy::getName(), UpdatePasswordPwnedPolicy::class);
        $this->addAction(UpdatePasswordRotationPolicy::getName(), UpdatePasswordRotationPolicy::class);
        $this->addAction(UpdateSessionAlertPolicy::getName(), UpdateSessionAlertPolicy::class);
        $this->addAction(UpdateSessionDurationPolicy::getName(), UpdateSessionDurationPolicy::class);
        $this->addAction(UpdateSessionInvalidationPolicy::getName(), UpdateSessionInvalidationPolicy::class);
        $this->addAction(UpdateSessionLimitPolicy::getName(), UpdateSessionLimitPolicy::class);
        $this->addAction(UpdateUserLimitPolicy::getName(), UpdateUserLimitPolicy::class);
        $this->addAction(UpdateDenyAliasedEmailPolicy::getName(), UpdateDenyAliasedEmailPolicy::class);
        $this->addAction(UpdateDenyDisposableEmailPolicy::getName(), UpdateDenyDisposableEmailPolicy::class);
        $this->addAction(UpdateDenyFreeEmailPolicy::getName(), UpdateDenyFreeEmailPolicy::class);
        $this->addAction(UpdateDenyCorporateEmailPolicy::getName(), UpdateDenyCorporateEmailPolicy::class);
        $this->addAction(UpdateDenyRealtimeGuestsPolicy::getName(), UpdateDenyRealtimeGuestsPolicy::class);

        // Auth Methods
        $this->addAction(UpdateAuthMethod::getName(), UpdateAuthMethod::class);

        // OAuth2
        $this->addAction(ListOAuth2Providers::getName(), ListOAuth2Providers::class);
        $this->addAction(GetOAuth2Provider::getName(), GetOAuth2Provider::class);
        $this->addAction(UpdateOAuth2GitHub::getName(), UpdateOAuth2GitHub::class);
        $this->addAction(UpdateOAuth2Discord::getName(), UpdateOAuth2Discord::class);
        $this->addAction(UpdateOAuth2Figma::getName(), UpdateOAuth2Figma::class);
        $this->addAction(UpdateOAuth2Dropbox::getName(), UpdateOAuth2Dropbox::class);
        $this->addAction(UpdateOAuth2Dailymotion::getName(), UpdateOAuth2Dailymotion::class);
        $this->addAction(UpdateOAuth2Bitbucket::getName(), UpdateOAuth2Bitbucket::class);
        $this->addAction(UpdateOAuth2Bitly::getName(), UpdateOAuth2Bitly::class);
        $this->addAction(UpdateOAuth2Box::getName(), UpdateOAuth2Box::class);
        $this->addAction(UpdateOAuth2Autodesk::getName(), UpdateOAuth2Autodesk::class);
        $this->addAction(UpdateOAuth2Google::getName(), UpdateOAuth2Google::class);
        $this->addAction(UpdateOAuth2Zoom::getName(), UpdateOAuth2Zoom::class);
        $this->addAction(UpdateOAuth2Zoho::getName(), UpdateOAuth2Zoho::class);
        $this->addAction(UpdateOAuth2Yandex::getName(), UpdateOAuth2Yandex::class);
        $this->addAction(UpdateOAuth2X::getName(), UpdateOAuth2X::class);
        $this->addAction(UpdateOAuth2WordPress::getName(), UpdateOAuth2WordPress::class);
        $this->addAction(UpdateOAuth2Webflow::getName(), UpdateOAuth2Webflow::class);
        $this->addAction(UpdateOAuth2Twitch::getName(), UpdateOAuth2Twitch::class);
        $this->addAction(UpdateOAuth2Stripe::getName(), UpdateOAuth2Stripe::class);
        $this->addAction(UpdateOAuth2Spotify::getName(), UpdateOAuth2Spotify::class);
        $this->addAction(UpdateOAuth2Slack::getName(), UpdateOAuth2Slack::class);
        $this->addAction(UpdateOAuth2Podio::getName(), UpdateOAuth2Podio::class);
        $this->addAction(UpdateOAuth2Notion::getName(), UpdateOAuth2Notion::class);
        $this->addAction(UpdateOAuth2Salesforce::getName(), UpdateOAuth2Salesforce::class);
        $this->addAction(UpdateOAuth2Yahoo::getName(), UpdateOAuth2Yahoo::class);
        $this->addAction(UpdateOAuth2Linkedin::getName(), UpdateOAuth2Linkedin::class);
        $this->addAction(UpdateOAuth2Disqus::getName(), UpdateOAuth2Disqus::class);
        $this->addAction(UpdateOAuth2Amazon::getName(), UpdateOAuth2Amazon::class);
        $this->addAction(UpdateOAuth2Etsy::getName(), UpdateOAuth2Etsy::class);
        $this->addAction(UpdateOAuth2Facebook::getName(), UpdateOAuth2Facebook::class);
        $this->addAction(UpdateOAuth2Tradeshift::getName(), UpdateOAuth2Tradeshift::class);
        $this->addAction(UpdateOAuth2TradeshiftSandbox::getName(), UpdateOAuth2TradeshiftSandbox::class);
        $this->addAction(UpdateOAuth2Paypal::getName(), UpdateOAuth2Paypal::class);
        $this->addAction(UpdateOAuth2PaypalSandbox::getName(), UpdateOAuth2PaypalSandbox::class);
        $this->addAction(UpdateOAuth2Gitlab::getName(), UpdateOAuth2Gitlab::class);
        $this->addAction(UpdateOAuth2Appwrite::getName(), UpdateOAuth2Appwrite::class);
        $this->addAction(UpdateOAuth2Authentik::getName(), UpdateOAuth2Authentik::class);
        $this->addAction(UpdateOAuth2Auth0::getName(), UpdateOAuth2Auth0::class);
        $this->addAction(UpdateOAuth2FusionAuth::getName(), UpdateOAuth2FusionAuth::class);
        $this->addAction(UpdateOAuth2Keycloak::getName(), UpdateOAuth2Keycloak::class);
        $this->addAction(UpdateOAuth2Oidc::getName(), UpdateOAuth2Oidc::class);
        $this->addAction(UpdateOAuth2Okta::getName(), UpdateOAuth2Okta::class);
        $this->addAction(UpdateOAuth2Kick::getName(), UpdateOAuth2Kick::class);
        $this->addAction(UpdateOAuth2Apple::getName(), UpdateOAuth2Apple::class);
        $this->addAction(UpdateOAuth2Microsoft::getName(), UpdateOAuth2Microsoft::class);
        $this->addAction(UpdateOAuth2HuggingFace::getName(), UpdateOAuth2HuggingFace::class);
        $this->addAction(UpdateOAuth2Resend::getName(), UpdateOAuth2Resend::class);
        $this->addAction(UpdateOAuth2TikTok::getName(), UpdateOAuth2TikTok::class);
        $this->addAction(UpdateOAuth2Kakao::getName(), UpdateOAuth2Kakao::class);
        $this->addAction(UpdateOAuth2Cloudflare::getName(), UpdateOAuth2Cloudflare::class);
    }
}

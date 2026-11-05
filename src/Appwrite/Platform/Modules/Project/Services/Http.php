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
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Vipps\Update as UpdateOAuth2Vipps;
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
use Appwrite\Platform\Modules\Project\Http\Project\Policies\AccountDevices\Update as UpdateAccountDevicesPolicy;
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
        $this->addAction('init', Init::class);

        // Project
        $this->addAction('deleteProject', DeleteProject::class);
        $this->addAction('getProject', GetProject::class);
        $this->addAction('updateProjectLabels', UpdateProjectLabels::class);
        $this->addAction('updateProjectProtocol', UpdateProjectProtocol::class);
        $this->addAction('updateProjectService', UpdateProjectService::class);

        // Prefs
        $this->addAction('getPrefs', GetPrefs::class);
        $this->addAction('updatePrefs', UpdatePrefs::class);

        // Sessions
        $this->addAction('deleteProjectSessions', DeleteSessions::class);

        // SMTP
        $this->addAction('updateProjectSMTP', UpdateSMTP::class);
        $this->addAction('createProjectSMTPTest', CreateSMTPTest::class);

        // Templates
        $this->addAction('listProjectEmailTemplates', ListTemplates::class);
        $this->addAction('getProjectEmailTemplate', GetTemplate::class);
        $this->addAction('updateProjectEmailTemplate', UpdateTemplate::class);

        // Variables
        $this->addAction('createProjectVariable', CreateVariable::class);
        $this->addAction('listProjectVariables', ListVariables::class);
        $this->addAction('getProjectVariable', GetVariable::class);
        $this->addAction('deleteProjectVariable', DeleteVariable::class);
        $this->addAction('updateProjectVariable', UpdateVariable::class);

        // Keys
        // TODO: Remove once the Console, CLI and SDKs use the Organization API instead
        $this->addAction('createProjectKey', CreateKey::class);
        $this->addAction('createEphemeralProjectKey', CreateEphemeralKey::class);
        $this->addAction('listProjectKeys', ListKeys::class);
        $this->addAction('getProjectKey', GetKey::class);
        $this->addAction('deleteProjectKey', DeleteKey::class);
        $this->addAction('updateProjectKey', UpdateKey::class);

        // Platforms
        $this->addAction('listProjectPlatforms', ListPlatforms::class);
        $this->addAction('getProjectPlatform', GetPlatform::class);
        $this->addAction('createProjectWebPlatform', CreateWebPlatform::class);
        $this->addAction('createProjectApplePlatform', CreateApplePlatform::class);
        $this->addAction('createProjectAndroidPlatform', CreateAndroidPlatform::class);
        $this->addAction('createProjectWindowsPlatform', CreateWindowsPlatform::class);
        $this->addAction('createProjectLinuxPlatform', CreateLinuxPlatform::class);
        $this->addAction('updateProjectWebPlatform', UpdateWebPlatform::class);
        $this->addAction('updateProjectApplePlatform', UpdateApplePlatform::class);
        $this->addAction('updateProjectAndroidPlatform', UpdateAndroidPlatform::class);
        $this->addAction('updateProjectWindowsPlatform', UpdateWindowsPlatform::class);
        $this->addAction('updateProjectLinuxPlatform', UpdateLinuxPlatform::class);
        $this->addAction('deleteProjectPlatform', DeletePlatform::class);

        // Mock Phones
        $this->addAction('createProjectMockPhone', CreateMockPhone::class);
        $this->addAction('listProjectMockPhones', ListMockPhones::class);
        $this->addAction('getProjectMockPhone', GetMockPhone::class);
        $this->addAction('updateProjectMockPhone', UpdateMockPhone::class);
        $this->addAction('deleteProjectMockPhone', DeleteMockPhone::class);

        // Policies
        $this->addAction('listPolicies', ListPolicies::class);
        $this->addAction('getProjectPolicy', GetPolicy::class);
        $this->addAction('updateProjectMembershipPrivacyPolicy', UpdateMembershipPrivacyPolicy::class);
        $this->addAction('updateProjectMFAFactorsPolicy', UpdateMFAFactorsPolicy::class);
        $this->addAction('updateProjectPasskeyPolicy', UpdatePasskeyPolicy::class);
        $this->addAction('updateProjectPasswordDictionaryPolicy', UpdatePasswordDictionaryPolicy::class);
        $this->addAction('updateProjectPasswordHistoryPolicy', UpdatePasswordHistoryPolicy::class);
        $this->addAction('updateProjectPasswordStrengthPolicy', UpdatePasswordStrengthPolicy::class);
        $this->addAction('updateProjectPasswordPersonalDataPolicy', UpdatePasswordPersonalDataPolicy::class);
        $this->addAction('updateProjectPasswordPwnedPolicy', UpdatePasswordPwnedPolicy::class);
        $this->addAction('updatePasswordRotationPolicy', UpdatePasswordRotationPolicy::class);
        $this->addAction('updateProjectSessionAlertPolicy', UpdateSessionAlertPolicy::class);
        $this->addAction('updateProjectSessionDurationPolicy', UpdateSessionDurationPolicy::class);
        $this->addAction('updateProjectSessionInvalidationPolicy', UpdateSessionInvalidationPolicy::class);
        $this->addAction('updateProjectSessionLimitPolicy', UpdateSessionLimitPolicy::class);
        $this->addAction('updateProjectUserLimitPolicy', UpdateUserLimitPolicy::class);
        $this->addAction('updateProjectDenyAliasedEmailPolicy', UpdateDenyAliasedEmailPolicy::class);
        $this->addAction('updateProjectDenyDisposableEmailPolicy', UpdateDenyDisposableEmailPolicy::class);
        $this->addAction('updateProjectDenyFreeEmailPolicy', UpdateDenyFreeEmailPolicy::class);
        $this->addAction('updateProjectDenyCorporateEmailPolicy', UpdateDenyCorporateEmailPolicy::class);
        $this->addAction('updateProjectDenyRealtimeGuestsPolicy', UpdateDenyRealtimeGuestsPolicy::class);
        $this->addAction('updateProjectAccountDevicesPolicy', UpdateAccountDevicesPolicy::class);

        // Auth Methods
        $this->addAction('updateProjectAuthMethod', UpdateAuthMethod::class);

        // OAuth2
        $this->addAction('listProjectOAuth2', ListOAuth2Providers::class);
        $this->addAction('getProjectOAuth2', GetOAuth2Provider::class);
        $this->addAction('updateProjectOAuth2GitHub', UpdateOAuth2GitHub::class);
        $this->addAction('updateProjectOAuth2Discord', UpdateOAuth2Discord::class);
        $this->addAction('updateProjectOAuth2Figma', UpdateOAuth2Figma::class);
        $this->addAction('updateProjectOAuth2Dropbox', UpdateOAuth2Dropbox::class);
        $this->addAction('updateProjectOAuth2Dailymotion', UpdateOAuth2Dailymotion::class);
        $this->addAction('updateProjectOAuth2Bitbucket', UpdateOAuth2Bitbucket::class);
        $this->addAction('updateProjectOAuth2Bitly', UpdateOAuth2Bitly::class);
        $this->addAction('updateProjectOAuth2Box', UpdateOAuth2Box::class);
        $this->addAction('updateProjectOAuth2Autodesk', UpdateOAuth2Autodesk::class);
        $this->addAction('updateProjectOAuth2Google', UpdateOAuth2Google::class);
        $this->addAction('updateProjectOAuth2Zoom', UpdateOAuth2Zoom::class);
        $this->addAction('updateProjectOAuth2Zoho', UpdateOAuth2Zoho::class);
        $this->addAction('updateProjectOAuth2Yandex', UpdateOAuth2Yandex::class);
        $this->addAction('updateProjectOAuth2X', UpdateOAuth2X::class);
        $this->addAction('updateProjectOAuth2WordPress', UpdateOAuth2WordPress::class);
        $this->addAction('updateProjectOAuth2Webflow', UpdateOAuth2Webflow::class);
        $this->addAction('updateProjectOAuth2Twitch', UpdateOAuth2Twitch::class);
        $this->addAction('updateProjectOAuth2Stripe', UpdateOAuth2Stripe::class);
        $this->addAction('updateProjectOAuth2Spotify', UpdateOAuth2Spotify::class);
        $this->addAction('updateProjectOAuth2Slack', UpdateOAuth2Slack::class);
        $this->addAction('updateProjectOAuth2Podio', UpdateOAuth2Podio::class);
        $this->addAction('updateProjectOAuth2Notion', UpdateOAuth2Notion::class);
        $this->addAction('updateProjectOAuth2Salesforce', UpdateOAuth2Salesforce::class);
        $this->addAction('updateProjectOAuth2Yahoo', UpdateOAuth2Yahoo::class);
        $this->addAction('updateProjectOAuth2Linkedin', UpdateOAuth2Linkedin::class);
        $this->addAction('updateProjectOAuth2Disqus', UpdateOAuth2Disqus::class);
        $this->addAction('updateProjectOAuth2Amazon', UpdateOAuth2Amazon::class);
        $this->addAction('updateProjectOAuth2Etsy', UpdateOAuth2Etsy::class);
        $this->addAction('updateProjectOAuth2Facebook', UpdateOAuth2Facebook::class);
        $this->addAction('updateProjectOAuth2Tradeshift', UpdateOAuth2Tradeshift::class);
        $this->addAction('updateProjectOAuth2Tradeshift Sandbox', UpdateOAuth2TradeshiftSandbox::class);
        $this->addAction('updateProjectOAuth2Paypal', UpdateOAuth2Paypal::class);
        $this->addAction('updateProjectOAuth2PaypalSandbox', UpdateOAuth2PaypalSandbox::class);
        $this->addAction('updateProjectOAuth2Gitlab', UpdateOAuth2Gitlab::class);
        $this->addAction('updateProjectOAuth2Appwrite', UpdateOAuth2Appwrite::class);
        $this->addAction('updateProjectOAuth2Authentik', UpdateOAuth2Authentik::class);
        $this->addAction('updateProjectOAuth2Auth0', UpdateOAuth2Auth0::class);
        $this->addAction('updateProjectOAuth2FusionAuth', UpdateOAuth2FusionAuth::class);
        $this->addAction('updateProjectOAuth2Keycloak', UpdateOAuth2Keycloak::class);
        $this->addAction('updateProjectOAuth2Oidc', UpdateOAuth2Oidc::class);
        $this->addAction('updateProjectOAuth2Okta', UpdateOAuth2Okta::class);
        $this->addAction('updateProjectOAuth2Kick', UpdateOAuth2Kick::class);
        $this->addAction('updateProjectOAuth2Apple', UpdateOAuth2Apple::class);
        $this->addAction('updateProjectOAuth2Microsoft', UpdateOAuth2Microsoft::class);
        $this->addAction('updateProjectOAuth2Hugging Face', UpdateOAuth2HuggingFace::class);
        $this->addAction('updateProjectOAuth2Resend', UpdateOAuth2Resend::class);
        $this->addAction('updateProjectOAuth2TikTok', UpdateOAuth2TikTok::class);
        $this->addAction('updateProjectOAuth2Kakao', UpdateOAuth2Kakao::class);
        $this->addAction('updateProjectOAuth2Cloudflare', UpdateOAuth2Cloudflare::class);
        $this->addAction('updateProjectOAuth2Vipps MobilePay', UpdateOAuth2Vipps::class);
    }
}

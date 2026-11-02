<?php

namespace Appwrite\Platform\Modules\Mock\Services;

use Appwrite\Platform\Modules\Mock\Http\Mock\APIKey\Unprefixed\Create as CreateAPIKeyUnprefixed;
use Appwrite\Platform\Modules\Mock\Http\Mock\GitHub\Callback\Get as GetGitHubCallback;
use Appwrite\Platform\Modules\Mock\Http\Mock\Tests\Avatars\BadIcon\Get as GetBadIcon;
use Appwrite\Platform\Modules\Mock\Http\Mock\Tests\Avatars\FaviconPage\Get as GetFaviconPage;
use Appwrite\Platform\Modules\Mock\Http\Mock\Tests\Avatars\FaviconPage\Truncated\Get as GetFaviconPageTruncated;
use Appwrite\Platform\Modules\Mock\Http\Mock\Tests\Avatars\TruncatedIcon\Get as GetTruncatedIcon;
use Appwrite\Platform\Modules\Mock\Http\Mock\Tests\General\OAuth2\Failure\Get as GetOAuth2Failure;
use Appwrite\Platform\Modules\Mock\Http\Mock\Tests\General\OAuth2\Get as GetOAuth2;
use Appwrite\Platform\Modules\Mock\Http\Mock\Tests\General\OAuth2\IdToken\Get as GetOAuth2IdToken;
use Appwrite\Platform\Modules\Mock\Http\Mock\Tests\General\OAuth2\JWKS\Get as GetOAuth2JWKS;
use Appwrite\Platform\Modules\Mock\Http\Mock\Tests\General\OAuth2\Native\Update as UpdateOAuth2Native;
use Appwrite\Platform\Modules\Mock\Http\Mock\Tests\General\OAuth2\Photo\Get as GetOAuth2Photo;
use Appwrite\Platform\Modules\Mock\Http\Mock\Tests\General\OAuth2\Photo\Refreshed\Get as GetOAuth2PhotoRefreshed;
use Appwrite\Platform\Modules\Mock\Http\Mock\Tests\General\OAuth2\Success\Get as GetOAuth2Success;
use Appwrite\Platform\Modules\Mock\Http\Mock\Tests\General\OAuth2\Token\Get as GetOAuth2Token;
use Appwrite\Platform\Modules\Mock\Http\Mock\Tests\General\OAuth2\User\Get as GetOAuth2User;
use Appwrite\Platform\Modules\Mock\Http\Mock\Tests\General\OAuth2\User\NoEmail\Get as GetOAuth2UserNoEmail;
use Appwrite\Platform\Modules\Mock\Http\Mock\Tests\General\OAuth2\User\Unverified\Get as GetOAuth2UserUnverified;
use Appwrite\Platform\Modules\Mock\Http\Mock\Tests\Locale\Get as GetLocale;
use Appwrite\Platform\Modules\Mock\Http\Shutdown;
use Utopia\Platform\Service;

class Http extends Service
{
    public function __construct()
    {
        $this->type = Service::TYPE_HTTP;

        $this->addAction(GetOAuth2::getName(), GetOAuth2::class);
        $this->addAction(GetLocale::getName(), GetLocale::class);
        $this->addAction(GetOAuth2Token::getName(), GetOAuth2Token::class);
        $this->addAction(GetOAuth2Photo::getName(), GetOAuth2Photo::class);
        $this->addAction(GetFaviconPage::getName(), GetFaviconPage::class);
        $this->addAction(GetBadIcon::getName(), GetBadIcon::class);
        $this->addAction(GetFaviconPageTruncated::getName(), GetFaviconPageTruncated::class);
        $this->addAction(GetTruncatedIcon::getName(), GetTruncatedIcon::class);
        $this->addAction(GetOAuth2PhotoRefreshed::getName(), GetOAuth2PhotoRefreshed::class);
        $this->addAction(GetOAuth2User::getName(), GetOAuth2User::class);
        $this->addAction(GetOAuth2UserUnverified::getName(), GetOAuth2UserUnverified::class);
        $this->addAction(GetOAuth2UserNoEmail::getName(), GetOAuth2UserNoEmail::class);
        $this->addAction(UpdateOAuth2Native::getName(), UpdateOAuth2Native::class);
        $this->addAction(GetOAuth2JWKS::getName(), GetOAuth2JWKS::class);
        $this->addAction(GetOAuth2IdToken::getName(), GetOAuth2IdToken::class);
        $this->addAction(GetOAuth2Success::getName(), GetOAuth2Success::class);
        $this->addAction(GetOAuth2Failure::getName(), GetOAuth2Failure::class);
        $this->addAction(CreateAPIKeyUnprefixed::getName(), CreateAPIKeyUnprefixed::class);
        $this->addAction(GetGitHubCallback::getName(), GetGitHubCallback::class);
        $this->addAction(Shutdown::getName(), Shutdown::class);
    }
}

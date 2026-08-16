<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\OAuth2\TradeshiftSandbox;

use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Tradeshift\Update as TradeshiftUpdate;
use Utopia\Auth\OAuth2\Providers\TradeshiftBox;

class Update extends TradeshiftUpdate
{
    public static function getProviderId(): string
    {
        return 'tradeshiftBox';
    }

    public static function getProviderClass(): string
    {
        return TradeshiftBox::class;
    }

    public static function getProviderLabel(): string
    {
        return 'Tradeshift Sandbox';
    }

    public static function getProviderSDKMethod(): string
    {
        return 'updateOAuth2TradeshiftSandbox';
    }
}

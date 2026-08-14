<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\OAuth2\TradeshiftSandbox;

use Appwrite\Auth\OAuth2\TradeshiftBox;
use Appwrite\Platform\Modules\Project\Http\Project\OAuth2\Tradeshift\Update as TradeshiftUpdate;

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

<?php

namespace Appwrite\Platform\Modules\Locale\Http\Locale;

use Utopia\Platform\Action as UtopiaAction;

abstract class Action extends UtopiaAction
{
    protected function getSDKNamespace(): string
    {
        return 'locale';
    }

    protected function getSDKGroup(): ?string
    {
        return null;
    }
}

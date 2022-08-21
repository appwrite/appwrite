<?php

namespace Appwrite\Extend\SDK;

use Appwrite\SDK\Language;
use Appwrite\SDK\SDK as BaseSDK;
use Appwrite\Spec\Spec;

class SDK extends BaseSDK {
    public function __construct(Language $language, Spec $spec, string $overridePath = '')
    {
        parent::__construct($language, $spec);
        if(!empty($overridePath)) {
            $this->loader->prependPath($overridePath, 'appwrite');
        }
    }
}
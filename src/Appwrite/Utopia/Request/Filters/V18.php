<?php

namespace Appwrite\Utopia\Request\Filters;

use Appwrite\Utopia\Request\Filter;

class V18 extends Filter
{
    // Convert 1.5 params to 1.6
    public function parse(array $content, string $model): array
    {
        switch ($model) {
            case 'account.deleteMfaAuthenticator':
                unset($content['otp']);
                break;
            case 'functions.create':
                $content['templateVersion'] = $content['templateBranch'] ?? "";
                unset($content['templateBranch']);
                break;
        }

        return $content;
    }
}

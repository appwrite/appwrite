<?php

namespace Appwrite\Platform\Modules\Projects\Http\Projects;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action as AppwriteAction;
use Appwrite\Platform\Permission as AppwritePermission;

class Action extends AppwriteAction
{
    use AppwritePermission;

    protected function requireNonWhitespaceValue(string $field, string $value): void
    {
        if (\trim($value) === '') {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, \ucfirst($field).' cannot be empty or whitespace only.');
        }
    }
}

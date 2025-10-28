<?php

namespace Appwrite\Event\Validator;

class FunctionEvent extends Event
{
    /**
     * Is valid.
     *
     * @param mixed $value
     *
     * @return bool
     */
    public function isValid($value): bool
    {
        if (str_starts_with($value ?? false, 'functions.')) {
            $this->message = 'Triggering a function on a function event is not allowed.';
            return false;
        }

        return parent::isValid($value);
    }
}

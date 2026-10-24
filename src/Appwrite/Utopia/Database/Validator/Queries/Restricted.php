<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

use Utopia\Query\Method;

/**
 * A queries validator that publishes the attributes and methods it accepts,
 * so the API specification can carry them in structured form.
 */
interface Restricted
{
    /**
     * Attributes its filter and order queries accept.
     *
     * @return array<string>
     */
    public function getAllowedAttributes(): array;

    /**
     * @return array<Method>
     */
    public function getAllowedMethods(): array;
}

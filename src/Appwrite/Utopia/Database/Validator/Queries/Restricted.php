<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

/**
 * A queries validator that can publish which attributes and methods it accepts,
 * so the API specification can carry them in structured form.
 */
interface Restricted
{
    /**
     * Attributes the validator accepts, or null when it does not restrict them.
     *
     * @return string[]|null
     */
    public function getAllowedAttributes(): ?array;

    /**
     * Query methods (e.g. equal, orderAsc, limit) the validator accepts.
     *
     * @return string[]
     */
    public function getAllowedMethods(): array;
}
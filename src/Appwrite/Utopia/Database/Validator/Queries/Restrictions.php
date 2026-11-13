<?php

namespace Appwrite\Utopia\Database\Validator\Queries;

use Utopia\Database\Validator\Query\Base as QueryValidator;
use Utopia\Query\Method;

/**
 * Implements {@see Restricted} from the query validators a queries validator is composed of,
 * so what it publishes cannot drift from what it accepts.
 */
trait Restrictions
{
    /**
     * @var array<string>
     */
    protected array $allowedAttributes = [];

    public function getAllowedAttributes(): array
    {
        $attributes = $this->allowedAttributes;

        foreach ($this->validators as $validator) {
            if ($validator instanceof Restricted) {
                $attributes = [...$attributes, ...$validator->getAllowedAttributes()];
            }
        }

        return $attributes;
    }

    public function getAllowedMethods(): array
    {
        $methods = [];

        foreach ($this->validators as $validator) {
            if ($validator instanceof Restricted) {
                $methods = [...$methods, ...$validator->getAllowedMethods()];
                continue;
            }

            $methods = [...$methods, ...match ($validator->getMethodType()) {
                QueryValidator::METHOD_TYPE_LIMIT => [Method::Limit],
                QueryValidator::METHOD_TYPE_OFFSET => [Method::Offset],
                QueryValidator::METHOD_TYPE_CURSOR => [Method::CursorAfter, Method::CursorBefore],
                QueryValidator::METHOD_TYPE_ORDER => [Method::OrderAsc, Method::OrderDesc, Method::OrderRandom],
                QueryValidator::METHOD_TYPE_SELECT => [Method::Select],
                // Filter checks a query against its attribute's type rather than its method,
                // so every method a scalar attribute supports is listed.
                QueryValidator::METHOD_TYPE_FILTER => [
                    ...\array_filter(Method::cases(), fn (Method $method) => $method->isFilter()),
                    Method::ContainsAll,
                    Method::And,
                    Method::Or,
                ],
                default => [],
            }];
        }

        return $methods;
    }
}

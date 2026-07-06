<?php

namespace Appwrite\Utopia\Request;

use Utopia\Http\Request;

final class CacheIdentifier
{
    private function __construct(private string $value)
    {
    }

    /**
     * @param array<string>|null $allowedParams
     */
    public static function fromRequest(Request $request, ?array $allowedParams = null): self
    {
        $params = $request->getParams();
        if ($allowedParams !== null) {
            $params = \array_intersect_key($params, \array_flip($allowedParams));
        }
        if (!isset($params['project'])) {
            $params['project'] = $request->getHeaderLine('x-appwrite-project', '');
        }
        \ksort($params);

        return new self(\md5($request->getURI() . '*' . \serialize($params) . '*' . APP_CACHE_BUSTER));
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}

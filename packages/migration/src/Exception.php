<?php

namespace Utopia\Migration;

class Exception extends \Exception implements \JsonSerializable
{
    public const CODE_VALIDATION = 400;
    public const CODE_UNAUTHORIZED = 401;
    public const CODE_FORBIDDEN = 403;
    public const CODE_NOT_FOUND = 404;
    public const CODE_CONFLICT = 409;
    public const CODE_RATE_LIMITED = 429;
    public const CODE_INTERNAL = 500;

    public string $resourceName;

    public string $resourceGroup;

    public ?string $resourceId;

    public function __construct(
        string $resourceName,
        string $resourceGroup,
        ?string $resourceId = null,
        string $message = '',
        mixed $code = 0,
        ?\Throwable $previous = null,
    ) {
        $this->resourceName = $resourceName;
        $this->resourceId = $resourceId;
        $this->resourceGroup = $resourceGroup;

        if (\is_string($code)) {
            if (\is_numeric($code)) {
                $code = (int) $code;
            } else {
                $code = self::CODE_INTERNAL; // PDOException
            }
        }

        parent::__construct($message, $code, $previous);
    }

    public function getResourceName(): string
    {
        return $this->resourceName;
    }

    public function getResourceGroup(): string
    {
        return $this->resourceGroup;
    }

    public function getResourceId(): string
    {
        return $this->resourceId ?? '';
    }

    /**
     * @return array<string, string|int|null>
     */
    public function jsonSerialize(): array
    {
        return [
            'code' => $this->getCode(),
            'message' => $this->getMessage(),
            'resourceName' => $this->resourceName,
            'resourceGroup' => $this->resourceGroup,
            'resourceId' => $this->resourceId,
            'trace' => $this->getPrevious()?->getTraceAsString(),
        ];
    }
}

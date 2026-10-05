<?php

namespace Appwrite\Platform\Modules\Compute\Validator;

use Utopia\Validator\Text;

/**
 * Function, site and project variable keys become container environment
 * variable names at build and runtime. Those must be C_IDENTIFIERs; a stray
 * tab or an accented letter passes a plain length check but makes the build
 * job manifest invalid, which fails every deployment for that resource.
 * Reject at the input boundary instead.
 *
 * The endpoint rule here is deliberately stricter than the one Kubernetes
 * enforces on an env var name (see isEnvVarName()), so that every accepted
 * key is also portable to dotenv files, docker --env and POSIX shells.
 */
class VariableKey extends Text
{
    public function __construct(int $length = 0)
    {
        parent::__construct($length, 1);
    }

    public function getDescription(): string
    {
        $description = 'Value must contain only letters, digits and underscores and must not start with a digit';

        if ($this->length !== 0) {
            $description .= ', and be at most ' . $this->length . ' chars';
        }

        return $description . '.';
    }

    public function isValid(mixed $value): bool
    {
        return parent::isValid($value) && \preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $value) === 1;
    }

    /**
     * The rule Kubernetes apimachinery enforces on an EnvVar name
     * (IsEnvVarName): C_IDENTIFIER extended with hyphens and dots, refusing
     * '.', '..' and any '..' prefix. Keys that predate the endpoint rule may
     * violate the endpoint rule yet still deploy fine (e.g. MY-VAR); the
     * build-layer guard uses this weaker rule so it only refuses keys the
     * cluster would refuse anyway.
     */
    public static function isEnvVarName(string $key): bool
    {
        if ($key === '.' || $key === '..' || \str_starts_with($key, '..')) {
            return false;
        }

        return \preg_match('/^[-._a-zA-Z][-._a-zA-Z0-9]*$/', $key) === 1;
    }
}

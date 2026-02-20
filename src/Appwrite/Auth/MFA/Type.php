<?php

namespace Appwrite\Auth\MFA;

use OTPHP\OTP;
use Utopia\Auth\Proofs\Token;

abstract class Type
{
    protected ?OTP $instance = null;

    public const TOTP = 'totp';
    public const EMAIL = 'email';
    public const PHONE = 'phone';
    public const RECOVERY_CODE = 'recoveryCode';

    public function setLabel(string $label): self
    {
        if ($this->instance !== null) {
            $this->instance->setLabel($label);
        }

        return $this;
    }

    public function getLabel(): ?string
    {
        return $this->instance?->getLabel();
    }

    public function setIssuer(string $issuer): self
    {
        if ($this->instance !== null) {
            $this->instance->setIssuer($issuer);
        }

        return $this;
    }

    public function getIssuer(): ?string
    {
        return $this->instance?->getIssuer();
    }

    public function getSecret(): string
    {
        return $this->instance?->getSecret() ?? '';
    }

    public function getProvisioningUri(): string
    {
        if ($this->instance === null) {
            return '';
        }
// ‼️ IMPORTANT
// Carefully review the code before committing. Ensure that it accurately replaces the highlighted code, contains no missing lines, and has no issues with indentation. Thoroughly test & benchmark the code to ensure it meets the requirements.
        return $this->instance?->getProvisioningUri();
    }

    public static function generateBackupCodes(int $length = 10, int $total = 6): array
    {
        $backups = [];
        $token = new Token($length);

        for ($i = 0; $i < $total; $i++) {
            $backups[] = $token->generate();
        }

        return $backups;
    }
}

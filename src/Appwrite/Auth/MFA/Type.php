<?php

namespace Appwrite\Auth\MFA;

use Appwrite\Auth\Auth;
use OTPHP\OTP;

abstract class Type
{
    protected OTP $instance;

    public const TOTP = 'totp';
    public const EMAIL = 'email';
    public const PHONE = 'phone';
    public const RECOVERY_CODE = 'recoveryCode';

    public function setLabel(string $label): self
    {
        $this->instance->setLabel($label);

        return $this;
    }

    public function getLabel(): ?string
    {
        return $this->instance->getLabel();
    }

    public function setIssuer(string $issuer): self
    {
        $this->instance->setIssuer($issuer);

        return $this;
    }

    public function getIssuer(): ?string
    {
        return $this->instance->getIssuer();
    }

    public function getSecret(): string
    {
        return $this->instance->getSecret();
    }

    public function getProvisioningUri(): string
    {
        return $this->instance->getProvisioningUri();
    }

    public static function generateBackupCodes(int $length = 10, int $total = 6): array
    {
        $backups = [];

        for ($i = 0; $i < $total; $i++) {
            $backups[] = Auth::tokenGenerator($length);
        }

        return $backups;
    }
}

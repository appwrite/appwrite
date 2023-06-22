<?php

namespace Appwrite\Auth\MFA;

use Appwrite\Auth\Auth;
use OTPHP\OTP;

abstract class Provider
{
    protected OTP $instance;

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

    static function generateBackupCodes(int $length = 6, int $total = 6): array
    {
        $backups = [];

        for ($i = 0; $i < $total; $i++) {
            $backups[] = Auth::codeGenerator($length);
        }

        return $backups;
    }
}

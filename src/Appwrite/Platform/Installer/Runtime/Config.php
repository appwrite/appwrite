<?php

namespace Appwrite\Platform\Installer\Runtime;

final class Config
{
    private const array KNOWN_KEYS = [
        'defaultHttpPort',
        'defaultHttpsPort',
        'organization',
        'image',
        'noStart',
        'isUpgrade',
        'isLocal',
        'isMock',
        'hostPath',
        'lockedDatabase',
        'vars',
    ];

    private string $defaultHttpPort = '80';
    private string $defaultHttpsPort = '443';
    private string $organization = 'appwrite';
    private string $image = 'appwrite';
    private bool $noStart = false;
    private bool $isUpgrade = false;
    private bool $isLocal = false;
    private bool $isMock = false;
    private ?string $hostPath = null;
    private ?string $lockedDatabase = null;
    private array $vars = [];

    public function __construct(array $values = [])
    {
        if (!$this->containsKnownKeys($values)) {
            $this->setVars($values);
            return;
        }
        $this->apply($values);
    }

    public function apply(array $values): void
    {
        if (array_key_exists('defaultHttpPort', $values) && $values['defaultHttpPort'] !== null && $values['defaultHttpPort'] !== '') {
            $this->setDefaultHttpPort((string) $values['defaultHttpPort']);
        }
        if (array_key_exists('defaultHttpsPort', $values) && $values['defaultHttpsPort'] !== null && $values['defaultHttpsPort'] !== '') {
            $this->setDefaultHttpsPort((string) $values['defaultHttpsPort']);
        }
        if (array_key_exists('organization', $values) && $values['organization'] !== null && $values['organization'] !== '') {
            $this->setOrganization((string) $values['organization']);
        }
        if (array_key_exists('image', $values) && $values['image'] !== null && $values['image'] !== '') {
            $this->setImage((string) $values['image']);
        }
        if (array_key_exists('noStart', $values) && $values['noStart'] !== null) {
            $this->setNoStart((bool) $values['noStart']);
        }
        if (array_key_exists('isUpgrade', $values) && $values['isUpgrade'] !== null) {
            $this->setIsUpgrade((bool) $values['isUpgrade']);
        }
        if (array_key_exists('isLocal', $values) && $values['isLocal'] !== null) {
            $this->setIsLocal((bool) $values['isLocal']);
        }
        if (array_key_exists('isMock', $values) && $values['isMock'] !== null) {
            $this->setIsMock((bool) $values['isMock']);
        }
        if (array_key_exists('hostPath', $values)) {
            $hostPath = $values['hostPath'];
            $this->setHostPath($hostPath !== null && $hostPath !== '' ? (string) $hostPath : null);
        }
        if (array_key_exists('lockedDatabase', $values) && $values['lockedDatabase'] !== null && $values['lockedDatabase'] !== '') {
            $locked = $values['lockedDatabase'];
            $this->setLockedDatabase($locked !== null && $locked !== '' ? (string) $locked : null);
        }
        if (array_key_exists('vars', $values) && is_array($values['vars'])) {
            $this->setVars($values['vars']);
        }
    }

    private function containsKnownKeys(array $values): bool
    {
        foreach (self::KNOWN_KEYS as $key) {
            if (array_key_exists($key, $values)) {
                return true;
            }
        }
        return false;
    }

    public function toArray(): array
    {
        return [
            'defaultHttpPort' => $this->defaultHttpPort,
            'defaultHttpsPort' => $this->defaultHttpsPort,
            'organization' => $this->organization,
            'image' => $this->image,
            'noStart' => $this->noStart,
            'vars' => $this->vars,
            'isUpgrade' => $this->isUpgrade,
            'isLocal' => $this->isLocal,
            'isMock' => $this->isMock,
            'hostPath' => $this->hostPath,
            'lockedDatabase' => $this->lockedDatabase,
        ];
    }

    public function getDefaultHttpPort(): string
    {
        return $this->defaultHttpPort;
    }

    public function setDefaultHttpPort(string $value): void
    {
        $this->defaultHttpPort = $value;
    }

    public function getDefaultHttpsPort(): string
    {
        return $this->defaultHttpsPort;
    }

    public function setDefaultHttpsPort(string $value): void
    {
        $this->defaultHttpsPort = $value;
    }

    public function getOrganization(): string
    {
        return $this->organization;
    }

    public function setOrganization(string $value): void
    {
        $this->organization = $value;
    }

    public function getImage(): string
    {
        return $this->image;
    }

    public function setImage(string $value): void
    {
        $this->image = $value;
    }

    public function getNoStart(): bool
    {
        return $this->noStart;
    }

    public function setNoStart(bool $value): void
    {
        $this->noStart = $value;
    }

    public function getVars(): array
    {
        return $this->vars;
    }

    public function setVars(array $vars): void
    {
        $this->vars = $vars;
    }

    public function isUpgrade(): bool
    {
        return $this->isUpgrade;
    }

    public function setIsUpgrade(bool $value): void
    {
        $this->isUpgrade = $value;
    }

    public function isLocal(): bool
    {
        return $this->isLocal;
    }

    public function setIsLocal(bool $value): void
    {
        $this->isLocal = $value;
    }

    public function isMock(): bool
    {
        return $this->isMock;
    }

    public function setIsMock(bool $value): void
    {
        $this->isMock = $value;
    }

    public function getHostPath(): ?string
    {
        return $this->hostPath;
    }

    public function setHostPath(?string $value): void
    {
        $this->hostPath = $value;
    }

    public function getLockedDatabase(): ?string
    {
        return $this->lockedDatabase;
    }

    public function setLockedDatabase(?string $value): void
    {
        $this->lockedDatabase = $value;
    }
}

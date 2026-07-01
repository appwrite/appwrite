<?php

namespace Utopia\Avatars;

use Utopia\Avatars\Adapter\Human\Github;
use Utopia\Avatars\Adapter\Human\Gravatar;
use Utopia\Avatars\Exception\InvalidIdentifier;
use Utopia\Avatars\Exception\NotFound;
use Utopia\Fetch\Client;

class Avatars
{
    /** @var Adapter[] */
    protected array $humanAdapters;

    /** @var Adapter[] */
    protected array $companyAdapters;

    /**
     * @param Adapter[] $humanAdapters
     * @param Adapter[] $companyAdapters
     */
    public function __construct(
        protected Client $client,
        array $humanAdapters = [],
        array $companyAdapters = [],
    ) {
        $this->humanAdapters = $humanAdapters;
        $this->companyAdapters = $companyAdapters;
    }

    public static function withDefaults(?Client $client = null): self
    {
        $client ??= new Client();

        return new self(
            client: $client,
            humanAdapters: [
                new Github($client),
                new Gravatar($client),
            ],
            companyAdapters: [],
        );
    }

    public function getHuman(Human $human, int $size = 512): string
    {
        if (!$human->hasIdentifier()) {
            throw new InvalidIdentifier('At least one human identifier is required.');
        }

        $this->validateHuman($human);

        $image = $this->fetchFromAdapters($this->humanAdapters, $human, $size);

        if ($image === null) {
            throw new NotFound('Human avatar not found.');
        }

        return $image;
    }

    public function getCompany(Company $company, int $size = 512): string
    {
        if (!$company->hasIdentifier()) {
            throw new InvalidIdentifier('At least one company identifier is required.');
        }

        $this->validateCompany($company);

        $image = $this->fetchFromAdapters($this->companyAdapters, $company, $size);

        if ($image === null) {
            throw new NotFound('Company avatar not found.');
        }

        return $image;
    }

    protected function validateHuman(Human $human): void
    {
        foreach ($this->humanAdapters as $adapter) {
            $value = $human->getIdentifier($adapter->getParam());

            if (empty($value)) {
                continue;
            }

            if (!$adapter->isValid($value)) {
                throw new InvalidIdentifier('Invalid ' . $adapter->getParam() . ' value.');
            }
        }
    }

    protected function validateCompany(Company $company): void
    {
        foreach ($this->companyAdapters as $adapter) {
            $value = $company->getIdentifier($adapter->getParam());

            if (empty($value)) {
                continue;
            }

            if (!$adapter->isValid($value)) {
                throw new InvalidIdentifier('Invalid ' . $adapter->getParam() . ' value.');
            }
        }
    }

    /**
     * @param Adapter[] $adapters
     */
    protected function fetchFromAdapters(array $adapters, Human|Company $subject, int $size): ?string
    {
        foreach ($adapters as $adapter) {
            $value = $subject->getIdentifier($adapter->getParam());

            if (empty($value)) {
                continue;
            }

            $image = $adapter->fetch($value, $size);

            if ($image !== null) {
                return $image;
            }
        }

        return null;
    }
}

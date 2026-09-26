<?php

declare(strict_types=1);

namespace Utopia\Domains\Registrar;

class Contact
{
    public function __construct(
        public string $firstname,
        public string $lastname,
        public string $phone,
        public string $email,
        public string $address1,
        public string $address2,
        public string $address3,
        public string $city,
        public string $state,
        public string $country,
        public string $postalcode,
        public string $org,
        public ?string $owner = null,
    ) {}

    public function toArray(): array
    {
        $owner = $this->owner ?? $this->firstname . ' ' . $this->lastname;

        return [
            'firstname' => $this->firstname,
            'lastname' => $this->lastname,
            'phone' => $this->phone,
            'email' => $this->email,
            'address1' => $this->address1,
            'address2' => $this->address2,
            'address3' => $this->address3,
            'city' => $this->city,
            'state' => $this->state,
            'country' => $this->country,
            'postalcode' => $this->postalcode,
            'org' => $this->org,
            'owner' => $owner,
        ];
    }
}

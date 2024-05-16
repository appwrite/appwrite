<?php

namespace Appwrite\Auth\Validator;

/**
 * Validates user password string against their personal data
 */
class PersonalData extends Password
{
    public function __construct(
        protected ?string $userId = null,
        protected ?string $email = null,
        protected ?string $name = null,
        protected ?string $phone = null,
        protected bool $strict = false,
        protected bool $allowEmpty = false,
    ) {
        parent::__construct($allowEmpty);
    }

    /**
     * Get Description.
     *
     * Returns validator description
     *
     * @return string
     */
    public function getDescription(): string
    {
        return 'Password must not include any personal data like your name, email, phone number, etc.';
    }

    /**
     * Is valid.
     *
     * @param mixed $value
     *
     * @return bool
     */
    public function isValid($password): bool
    {
        if (!parent::isValid($password)) {
            return false;
        }

        if (!$this->strict) {
            $password = strtolower($password);
            $this->userId = strtolower($this->userId ?? '');
            $this->email = strtolower($this->email ?? '');
            $this->name = strtolower($this->name ?? '');
            $this->phone = strtolower($this->phone ?? '');
        }

        if ($this->userId && strpos($password, $this->userId) !== false) {
            return false;
        }

        if ($this->email && strpos($password, $this->email) !== false) {
            return false;
        }

        if ($this->email && strpos($password, explode('@', $this->email)[0] ?? '') !== false) {
            return false;
        }

        if ($this->name && strpos($password, $this->name) !== false) {
            return false;
        }

        if ($this->phone && strpos($password, str_replace('+', '', $this->phone)) !== false) {
            return false;
        }

        if ($this->phone && strpos($password, $this->phone) !== false) {
            return false;
        }

        return true;
    }

    /**
     * Is array
     *
     * Function will return true if object is array.
     *
     * @return bool
     */
    public function isArray(): bool
    {
        return false;
    }

    /**
     * Get Type
     *
     * Returns validator type.
     *
     * @return string
     */
    public function getType(): string
    {
        return self::TYPE_STRING;
    }
}

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
     * @param mixed $password
     *
     * @return bool
     */
    public function isValid($password): bool
    {
        if (!parent::isValid($password)) {
            return false;
        }

        $userId = $this->userId ?? '';
        $email = $this->email ?? '';
        $name = $this->name ?? '';
        $phone = $this->phone ?? '';

        if (!$this->strict) {
            $password = strtolower($password);
            $userId = strtolower($userId);
            $email = strtolower($email);
            $name = strtolower($name);
            $phone = strtolower($phone);
        }

        if ($userId && strpos($password, $userId) !== false) {
            return false;
        }

        if ($email && strpos($password, $email) !== false) {
            return false;
        }

        if ($email && strpos($password, explode('@', $email)[0]) !== false) {
            return false;
        }

        if ($name && strpos($password, $name) !== false) {
            return false;
        }

        if ($phone && strpos($password, str_replace('+', '', $phone)) !== false) {
            return false;
        }

        if ($phone && strpos($password, $phone) !== false) {
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

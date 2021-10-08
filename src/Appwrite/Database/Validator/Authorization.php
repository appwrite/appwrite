<?php

namespace Appwrite\Database\Validator;

use Appwrite\Database\Document;
use Utopia\Validator;

class Authorization extends Validator
{
    /**
     * @var array
     */
    public static $roles = ['*' => true];

    /**
     * @var Document
     */
    protected $document;

    /**
     * @var string
     */
    protected $action = '';

    /**
     * @var string
     */
    protected $message = 'Authorization Error';

    /**
     * Structure constructor.
     *
     * @param Document $document
     * @param string   $action
     */
    public function __construct(Document $document, $action)
    {
        $this->document = $document;
        $this->action = $action;
    }

    /**
     * Get Description.
     *
     * Returns validator description
     *
     * @return string
     */
    public function getDescription()
    {
        return $this->message;
    }

    /**
     * Is valid.
     *
     * Returns true if valid or false if not.
     *
     * @param mixed $permissions
     *
     * @return bool
     */
    public function isValid($permissions)
    {
        if (!self::$status) {
            return true;
        }

        if (!isset($permissions[$this->action])) {
            $this->message = 'Missing action key: "'.$this->action.'"';

            return false;
        }

        $permission = null;

        foreach ($permissions[$this->action] as $permission) {
            $permission = \str_replace(':{self}', ':'.$this->document->getId(), $permission);

            if (\array_key_exists($permission, self::$roles)) {
                return true;
            }
        }

        $this->message = 'Missing "'.$this->action.'" permission for role "'.$permission.'". Only this scopes "'.\json_encode(self::getRoles()).'" are given and only this are allowed "'.\json_encode($permissions[$this->action]).'".';

        return false;
    }

    /**
     * @param string $role
     *
     * @return void
     */
    public static function setRole(string $role): void
    {
        self::$roles[$role] = true;
    }

    /**
     * @param string $role
     *
     * @return void
     */
    public static function unsetRole(string $role): void
    {
        unset(self::$roles[$role]);
    }

    /**
     * @return array
     */
    public static function getRoles(): array
    {
        return \array_keys(self::$roles);
    }

    /**
     * @return void
     */
    public static function cleanRoles(): void
    {
        self::$roles = [];
    }

    /**
     * @param string $role
     *
     * @return bool
     */
    public static function isRole(string $role): bool
    {
        return (\array_key_exists($role, self::$roles));
    }

    /**
     * @var bool
     */
    public static $status = true;
    
    /**
     * Default value in case we need
     *  to reset Authorization status
     *
     * @var bool
     */
    public static $statusDefault = true;

    /**
     * Change default status.
     * This will be used for the
     *  value set on the self::reset() method
     *
     * @return void
     */
    public static function setDefaultStatus($status): void
    {
        self::$statusDefault = $status;
        self::$status = $status;
    }

    /**
     * Enable Authorization checks
     *
     * @return void
     */
    public static function enable(): void
    {
        self::$status = true;
    }

    /**
     * Disable Authorization checks
     *
     * @return void
     */
    public static function disable(): void
    {
        self::$status = false;
    }

    /**
     * Disable Authorization checks
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$status = self::$statusDefault;
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
        return self::TYPE_ARRAY;
    }
}

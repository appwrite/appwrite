<?php

namespace Appwrite\Database\Validator;

use Appwrite\Database\Document;
use Utopia\Validator;

class Authorization extends Validator
{
    /**
     * @var array
     */
    static $roles = ['*'];

    /**
     * @var Document
     */
    protected $document = null;

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
     * @param array $permissions
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

            if (\in_array($permission, self::getRoles())) {
                return true;
            }
        }

        $this->message = 'User is missing '.$this->action.' for "'.$permission.'" permission. Only this scopes "'.\json_encode(self::getRoles()).'" is given and only this are allowed "'.\json_encode($permissions[$this->action]).'".';

        return false;
    }

    /**
     * @param string $role
     *
     * @return void
     */
    public static function setRole($role): void
    {
        self::$roles[] = $role;
    }

    /**
     * @return array
     */
    public static function getRoles()
    {
        return self::$roles;
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
}

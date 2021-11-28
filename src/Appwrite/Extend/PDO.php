<?php

namespace Appwrite\Extend;

use PDO as PDONative;

class PDO extends PDONative
{
    /**
     * @var PDONative
     */
    protected $pdo;

    /**
     * @var mixed
     */
    protected $dsn;

    /**
     * @var mixed
     */
    protected $username;

    /**
     * @var mixed
     */
    protected $passwd;

    /**
     * @var mixed
     */
    protected $options;

    /**
     * Create A Proxy PDO Object
     */
    public function __construct($dsn, $username = null, $passwd = null, $options = null)
    {
        $this->dsn = $dsn;
        $this->username = $username;
        $this->passwd = $passwd;
        $this->options = $options;

        $this->pdo = new PDONative($dsn, $username, $passwd, $options);
    }

    public function setAttribute($attribute, $value)
    {
        return $this->pdo->setAttribute($attribute, $value);
    }

    public function prepare($statement, $driver_options = null)
    {
        return new PDOStatement($this, $this->pdo->prepare($statement, []));
    }

    public function quote($string, $parameter_type = PDONative::PARAM_STR)
    {
        return $this->pdo->quote($string, $parameter_type);
    }

    public function reconnect(): PDONative
    {
        $this->pdo = new PDONative($this->dsn, $this->username, $this->passwd, $this->options);

        echo '[PDO] MySQL connection restarted'.PHP_EOL;
        
        // Connection settings
        $this->pdo->setAttribute(PDONative::ATTR_DEFAULT_FETCH_MODE, PDONative::FETCH_ASSOC);   // Return arrays
        $this->pdo->setAttribute(PDONative::ATTR_ERRMODE, PDONative::ERRMODE_EXCEPTION);        // Handle all errors with exceptions

        return $this->pdo;
    }
}

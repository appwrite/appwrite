<?php

namespace Appwrite\Extend;

use PDO as PDONative;
use PDOStatement as PDOStatementNative;

class PDOStatement
{
    /**
     * @var PDO
     */
    protected $pdo;

    /**
     * @var PDOStatementNative
     */
    protected $PDOStatement;

    public function __construct(PDO &$pdo, PDOStatementNative $PDOStatement)
    {
        $this->pdo = $pdo;
        $this->PDOStatement = $PDOStatement;
    }

    public function bindValue($parameter, $value, $data_type = PDONative::PARAM_STR)
    {
        $result = $this->PDOStatement->bindValue($parameter, $value, $data_type);

        return $result;
    }

    public function bindParam($parameter, &$variable, $data_type = PDONative::PARAM_STR, $length = null, $driver_options = null)
    {
        $result = $this->PDOStatement->bindParam($parameter, $variable, $data_type, $length, $driver_options);

        return $result;
    }

    public function bindColumn($column, &$param, $type = null, $maxlen = null, $driverdata = null)
    {
        $result = $this->PDOStatement->bindColumn($column, $param, $type, $maxlen, $driverdata);

        return $result;
    }

    public function execute($input_parameters = null)
    {
        try {
            $result = $this->PDOStatement->execute($input_parameters);
        } catch (\Throwable $th) {
            // throw new Exception('My Error: ' .$th->getMessage());
            $this->pdo->reconnect();
            $result = $this->PDOStatement->execute($input_parameters);
        }

        return $result;
    }

    public function fetch($fetch_style = PDONative::FETCH_ASSOC, $cursor_orientation = PDONative::FETCH_ORI_NEXT, $cursor_offset = 0)
    {
        $result = $this->PDOStatement->fetch($fetch_style, $cursor_orientation, $cursor_offset);

        return $result;
    }

    public function fetchAll()
    {
        $result = $this->PDOStatement->fetchAll();
        
        return $result;
    }
}
<?php

namespace Appwrite\Extend;

use PDO as PDONative;
use PDOStatement as PDOStatementNative;

class PDOStatement extends PDOStatementNative
{
    /**
     * @var PDO
     */
    protected $pdo;

    /**
     * Params
     */
    protected $params = [];

    /**
     * Values
     */
    protected $values = [];

    /**
     * Columns
     */
    protected $columns = [];

    /**
     * @var PDOStatementNative
     */
    protected $PDOStatement;

    public function __construct(PDO &$pdo, PDOStatementNative $PDOStatement)
    {
        $this->pdo = &$pdo;
        $this->PDOStatement = $PDOStatement;
    }

    public function bindValue($parameter, $value, $data_type = PDONative::PARAM_STR)
    {
        $this->values[$parameter] = ['value' => $value, 'data_type' => $data_type];

        $result = $this->PDOStatement->bindValue($parameter, $value, $data_type);

        return $result;
    }

    public function bindParam($parameter, &$variable, $data_type = PDONative::PARAM_STR, $length = null, $driver_options = null)
    {
        $this->params[$parameter] = ['value' => &$variable, 'data_type' => $data_type, 'length' => $length, 'driver_options' => $driver_options];

        $result = $this->PDOStatement->bindParam($parameter, $variable, $data_type, $length, $driver_options);

        return $result;
    }

    public function bindColumn($column, &$param, $type = null, $maxlen = null, $driverdata = null)
    {
        $this->columns[$column] = ['param' => &$param, 'type' => $type, 'maxlen' => $maxlen, 'driverdata' => $driverdata];

        $result = $this->PDOStatement->bindColumn($column, $param, $type, $maxlen, $driverdata);

        return $result;
    }

    public function execute($input_parameters = null)
    {
        try {
            $result = $this->PDOStatement->execute($input_parameters);
        } catch (\Throwable $th) {
            $this->pdo = $this->pdo->reconnect();
            $this->PDOStatement = $this->pdo->prepare($this->PDOStatement->queryString, []);

            foreach ($this->values as $key => $set) {
                $this->PDOStatement->bindValue($key, $set['value'], $set['data_type']);
            }

            foreach ($this->params as $key => $set) {
                $this->PDOStatement->bindParam($key, $set['variable'], $set['data_type'], $set['length'], $set['driver_options']);
            }

            foreach ($this->columns as $key => $set) {
                $this->PDOStatement->bindColumn($key, $set['param'], $set['type'], $set['maxlen'], $set['driverdata']);
            }

            $result = $this->PDOStatement->execute($input_parameters);
        }

        return $result;
    }

    public function fetch($fetch_style = PDONative::FETCH_ASSOC, $cursor_orientation = PDONative::FETCH_ORI_NEXT, $cursor_offset = 0)
    {
        $result = $this->PDOStatement->fetch($fetch_style, $cursor_orientation, $cursor_offset);

        return $result;
    }

    /**
     * Fetch All
     *
     * @param  int  $fetch_style
     * @param  mixed  $fetch_args
     * @return array|false
     */
    public function fetchAll(int $fetch_style = PDO::FETCH_BOTH, mixed ...$fetch_args)
    {
        $result = $this->PDOStatement->fetchAll();

        return $result;
    }
}

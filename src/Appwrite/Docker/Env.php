<?php

namespace Appwrite\Docker;

use Exception;

class Env
{
    /**
     * @var array
     */
    protected $vars = [];

    /**
     * @var string $data
     */
    public function __construct(string $data)
    {
        $data = explode("\n", $data);

        foreach ($data as &$row) {
            $row = explode('=', $row);
            $key = (isset($row[0])) ? trim($row[0]) : null;
            $value = (isset($row[1])) ? trim($row[1]) : null;

            if ($key) {
                $this->vars[$key] = $value;
            }
        }
    }

    /**
     * @param string $key
     * @param mixed  $value
     *
     * @return $this
     */
    public function setVar(string $key, $value): self
    {
        $this->vars[$key] = $value;

        return $this;
    }

    /**
     * @param string $key
     *
     * @return string
     */
    public function getVar(string $key): string
    {
        return (isset($this->vars[$key])) ? $this->vars[$key] : '';
    }

    /**
     * Get All Vars
     * 
     * @return array
     */
    public function list(): array
    {
        return $this->vars;
    }

    /**
     * @return string
     */
    public function export(): string
    {
        $output = '';

        foreach ($this->vars as $key => $value) {
            $output .= $key.'='.$value."\n";
        }

        return $output;
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Databases\Fakes;

use PDOException;

/**
 * A PDOException as a driver raises it: the SQLSTATE as its code and the driver's own code in errorInfo.
 */
final class DriverException extends PDOException
{
    public function __construct(string $sqlState, ?int $driverCode, string $message)
    {
        parent::__construct($message);

        $this->code = $sqlState;
        $this->errorInfo = [$sqlState, $driverCode, $message];
    }
}

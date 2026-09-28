<?php

namespace Utopia\Query\Schema\Trait;

use Utopia\Query\Builder\Statement;
use Utopia\Query\Exception\ValidationException;
use Utopia\Query\Schema\ParameterDirection;

trait Procedures
{
    /**
     * Validate and compile a procedure parameter list.
     *
     * @param  list<array{0: ParameterDirection, 1: string, 2: string}>  $params
     * @return list<string>
     */
    protected function compileProcedureParams(array $params): array
    {
        $paramList = [];
        foreach ($params as $param) {
            $direction = $param[0]->value;
            $name = $this->quote($param[1]);

            if (! \preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\s+[A-Za-z_][A-Za-z0-9_]*)*(\s*\(\s*[A-Za-z0-9_,\s]+\s*\))?$/', $param[2])) {
                throw new ValidationException('Invalid procedure parameter type: ' . $param[2]);
            }

            $paramList[] = $direction . ' ' . $name . ' ' . $param[2];
        }

        return $paramList;
    }

    /**
     * Create a stored procedure.
     *
     * $body is emitted verbatim into the generated DDL and must come from
     * trusted (developer-controlled) source — never from untrusted input.
     *
     * @param  list<array{0: ParameterDirection, 1: string, 2: string}>  $params
     */
    public function createProcedure(string $name, array $params, string $body): Statement
    {
        $paramList = $this->compileProcedureParams($params);

        $sql = 'CREATE PROCEDURE ' . $this->quote($name)
            . '(' . \implode(', ', $paramList) . ')'
            . ' BEGIN ' . $body . ' END';

        return new Statement($sql, [], executor: $this->executor);
    }

    public function dropProcedure(string $name): Statement
    {
        return new Statement('DROP PROCEDURE ' . $this->quote($name), [], executor: $this->executor);
    }
}

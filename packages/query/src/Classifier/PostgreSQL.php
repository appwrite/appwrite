<?php

namespace Utopia\Query\Classifier;

use Utopia\Query\Type;

/**
 * PostgreSQL Wire Protocol Parser
 *
 * Parses PostgreSQL frontend (client → server) messages and classifies queries.
 *
 * Wire protocol message format:
 * - Byte 0: Message type character
 * - Bytes 1-4: Length (big-endian int32, includes self but not type byte)
 * - Bytes 5+: Message body
 *
 * Supported message types:
 * - 'Q' (Query): Simple query — body is null-terminated SQL string
 * - 'P' (Parse): Extended query — prepared statement, routed to primary
 * - 'B' (Bind): Extended query — parameter binding, routed to primary
 * - 'E' (Execute): Extended query — execute prepared, routed to primary
 */
class PostgreSQL extends SQL
{
    public function classify(string $data): Type
    {
        $len = \strlen($data);
        if ($len < 6) {
            return Type::Unknown;
        }

        $type = $data[0];

        // Simple Query protocol
        if ($type === 'Q') {
            $query = \substr($data, 5);

            $nullPos = \strpos($query, "\x00");
            if ($nullPos !== false) {
                $query = \substr($query, 0, $nullPos);
            }

            return $this->classifySQL($query);
        }

        // Extended Query protocol — always route to primary for safety
        if ($type === 'P' || $type === 'B' || $type === 'E') {
            return Type::Write;
        }

        return Type::Unknown;
    }
}

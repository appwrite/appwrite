<?php

namespace Utopia\Query\Classifier;

use Utopia\Query\Type;

/**
 * MySQL Wire Protocol Parser
 *
 * Parses MySQL client protocol packets and classifies queries.
 *
 * Packet format:
 * - Bytes 0-2: Payload length (little-endian 3-byte int)
 * - Byte 3: Sequence ID
 * - Byte 4: Command type
 * - Bytes 5+: Command payload
 *
 * Supported commands:
 * - COM_QUERY (0x03): Text query — followed by query string
 * - COM_STMT_PREPARE (0x16): Prepared statement — routed to primary
 * - COM_STMT_EXECUTE (0x17): Execute prepared — routed to primary
 * - COM_STMT_SEND_LONG_DATA (0x18): Long data — routed to primary
 * - COM_STMT_CLOSE (0x19): Close statement — routed to primary
 * - COM_STMT_RESET (0x1A): Reset statement — routed to primary
 */
class MySQL extends SQL
{
    private const COM_QUERY = 0x03;

    private const COM_STMT_PREPARE = 0x16;

    private const COM_STMT_EXECUTE = 0x17;

    private const COM_STMT_SEND_LONG_DATA = 0x18;

    private const COM_STMT_CLOSE = 0x19;

    private const COM_STMT_RESET = 0x1A;

    public function classify(string $data): Type
    {
        $len = \strlen($data);
        if ($len < 5) {
            return Type::Unknown;
        }

        $command = \ord($data[4]);

        // COM_QUERY: classify the SQL text
        if ($command === self::COM_QUERY) {
            $query = \substr($data, 5);

            return $this->classifySQL($query);
        }

        // Prepared statement commands — always route to primary
        if (
            $command === self::COM_STMT_PREPARE
            || $command === self::COM_STMT_EXECUTE
            || $command === self::COM_STMT_SEND_LONG_DATA
        ) {
            return Type::Write;
        }

        // COM_STMT_CLOSE and COM_STMT_RESET are maintenance — route to primary
        if ($command === self::COM_STMT_CLOSE || $command === self::COM_STMT_RESET) {
            return Type::Write;
        }

        return Type::Unknown;
    }
}

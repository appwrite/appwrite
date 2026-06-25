<?php

namespace Appwrite\Event\Resource;

class Parser
{
    /**
     * Split a rendered resource path (e.g. `database/db1/collection/col1/document/doc1`)
     * into ordered {type, id} segments. Trailing odd segment (e.g.
     * `bucket/b1/files`) is treated as a collection name with no id and dropped.
     *
     * @return array<int, array{type: string, id: string}>
     */
    public static function parse(string $rendered): array
    {
        if ($rendered === '') {
            return [];
        }

        $parts = explode('/', trim($rendered, '/'));
        $segments = [];
        for ($i = 0, $n = count($parts); $i + 1 < $n; $i += 2) {
            $segments[] = ['type' => $parts[$i], 'id' => $parts[$i + 1]];
        }
        return $segments;
    }

    /**
     * Pick the audit-log target — the deepest known segment, plus the joined
     * parent chain. Matches the granular shape the audit worker writes (e.g.
     * type=document, id=doc1, parent=database/db1/collection/col1).
     *
     * @param array<int, array{type: string, id: string}> $segments
     * @return array{type: string, id: string, parent: string}
     */
    public static function auditTarget(array $segments): array
    {
        if ($segments === []) {
            return ['type' => '', 'id' => '', 'parent' => ''];
        }

        $count = count($segments);
        $leaf = $segments[$count - 1];
        $parent = '';
        if ($count > 1) {
            $parts = [];
            for ($i = 0; $i < $count - 1; $i++) {
                $parts[] = $segments[$i]['type'];
                $parts[] = $segments[$i]['id'];
            }
            $parent = implode('/', $parts);
        }

        return ['type' => $leaf['type'], 'id' => $leaf['id'], 'parent' => $parent];
    }

    /**
     * Apply the usage-warehouse granularity rule:
     *  - databases stop at `database/{databaseId}/table` + tableId
     *  - storage stops at `bucket` + bucketId
     *  - functions/sites are scalar (`function`/`site` + id)
     *  - anything else returns the head segment unchanged
     *
     * @param array<int, array{type: string, id: string}> $segments
     * @return array{resource: string, resourceId: string}
     */
    public static function capForUsage(array $segments): array
    {
        if ($segments === []) {
            return ['resource' => '', 'resourceId' => ''];
        }

        $head = $segments[0];
        if ($head['type'] === 'database' && isset($segments[1]) && $segments[1]['type'] === 'collection') {
            return [
                'resource' => 'database/' . $head['id'] . '/table',
                'resourceId' => $segments[1]['id'],
            ];
        }

        return ['resource' => $head['type'], 'resourceId' => $head['id']];
    }
}

<?php

namespace Appwrite\Network\RateLimit;

use Utopia\Database\Document;
use Utopia\Http\Request;
use Utopia\Http\Route;

final class Params
{
    /**
     * The placeholders an `abuse-key` label is filled from. A request param that is empty() keeps its
     * `{param-…}` placeholder literal, so "0", 0 and false share the bucket of an omitted param.
     *
     * @return array<string, string>
     */
    public static function of(Request $request, Route $route, Document $project, Document $user): array
    {
        $start = $request->getContentRangeStart();
        $end = $request->getContentRangeEnd();
        $params = [
            '{projectId}' => (string) $project->getId(),
            '{userId}' => (string) $user->getId(),
            '{userAgent}' => (string) $request->getUserAgent(''),
            '{ip}' => (string) $request->getIP(),
            '{url}' => $request->getHostname() . $route->getPath(),
            '{method}' => (string) $request->getMethod(),
            '{chunkId}' => (string) (int) ($start / ($end + 1 - $start)),
        ];

        foreach ($request->getParams() as $key => $value) {
            if (! empty($value)) {
                $params['{param-' . $key . '}'] = (\is_array($value) || \is_object($value)) ? (string) \json_encode($value) : (string) $value;
            }
        }

        return $params;
    }
}

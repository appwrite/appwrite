<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\Keys;

use Appwrite\Auth\Key;
use Appwrite\Platform\Action as AppwriteAction;
use Utopia\Database\Document;

/**
 * TODO: Remove once the Console, CLI and SDKs use the Organization API
 * (/v1/organization/projects/:projectId/keys) instead of this project-scoped route.
 */
class Action extends AppwriteAction
{
    /**
     * Blank the stored secret when the caller is an API key.
     */
    protected function withoutSecret(?Key $apiKey, Document $key): Document
    {
        if ($apiKey === null) {
            return $key;
        }

        $copy = new Document($key->getArrayCopy());
        $copy->setAttribute('secret', '');

        return $copy;
    }

    /**
     * @param array<Document> $keys
     * @return array<Document>
     */
    protected function withoutSecrets(?Key $apiKey, array $keys): array
    {
        if ($apiKey === null) {
            return $keys;
        }

        return \array_map(fn (Document $key) => $this->withoutSecret($apiKey, $key), $keys);
    }
}

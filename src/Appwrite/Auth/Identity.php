<?php

namespace Appwrite\Auth;

use Utopia\Database\Database;
use Utopia\Database\Document;

class Identity
{
    /**
     * Include `photo` only when the identities collection already has that attribute.
     *
     * OAuth login stores the provider avatar URL on the identity. Cloud (and
     * self-hosted upgrades) can run that write against project databases that
     * have not yet received the V25 column. Writing `photo` in that window
     * raises Structure and fails the entire session create.
     *
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    public static function withPhoto(Database $dbForProject, array $attributes, string $photo): array
    {
        foreach ($dbForProject->getCollection('identities')->getAttribute('attributes', []) as $attribute) {
            $attributeId = match (true) {
                $attribute instanceof Document => $attribute->getId(),
                \is_array($attribute) => $attribute['$id'] ?? '',
                default => '',
            };

            if ($attributeId === 'photo') {
                $attributes['photo'] = $photo;
                break;
            }
        }

        return $attributes;
    }
}

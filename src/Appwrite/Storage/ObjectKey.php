<?php

namespace Appwrite\Storage;

use Appwrite\Extend\Exception;
use Appwrite\Utopia\Database\Validator\Folder;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;

/**
 * S3-style addressing of a file inside a bucket. A key is the file's folder
 * followed by its name, for example "photos/2026/pink.png".
 *
 * Names are not unique within a folder, so a key can address more than one
 * file. Callers are refused in that case rather than served whichever file
 * the query happened to return first, since the matches may differ in who is
 * allowed to read them.
 */
class ObjectKey
{
    /**
     * Split a key into the folder and name that address a file.
     *
     * @return array{folder: string, name: string}
     * @throws Exception
     */
    public static function parse(string $key): array
    {
        $slash = \strrpos($key, '/');
        $folder = $slash === false ? '' : \substr($key, 0, $slash + 1);
        $name = $slash === false ? $key : \substr($key, $slash + 1);
        $validator = new Folder();

        if ($name === '' || !$validator->isValid($folder)) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, $validator->getDescription());
        }

        return [
            'folder' => Folder::normalize($folder),
            'name' => $name,
        ];
    }

    /**
     * Find the file a key addresses, or null when no file matches.
     *
     * @throws Exception when the key is malformed or matches more than one file
     */
    public static function find(Database $dbForProject, Document $bucket, string $key): ?Document
    {
        $object = self::parse($key);

        $files = $dbForProject->getAuthorization()->skip(fn () => $dbForProject->find('bucket_' . $bucket->getSequence(), [
            Query::equal('folder', [$object['folder']]),
            Query::equal('name', [$object['name']]),
            Query::limit(2),
        ]));

        if (\count($files) > 1) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, "Multiple files match object key '{$key}'.");
        }

        return $files[0] ?? null;
    }
}

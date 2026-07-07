<?php

namespace Appwrite\Platform\Modules\Storage\Http\Buckets\Folders;

use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Database\Validator\Folder;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\NotFound as NotFoundException;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Authorization\Input;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Range;

class XList extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'listFolders';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/storage/buckets/:bucketId/folders')
            ->desc('List folders')
            ->groups(['api', 'storage'])
            ->label('scope', 'files.read')
            ->label('usage.resource', 'bucket/{request.bucketId}')
            ->label('resourceType', RESOURCE_TYPE_BUCKETS)
            ->label('sdk', new Method(
                namespace: 'storage',
                group: 'files',
                name: 'listFolders',
                description: '/docs/references/storage/list-folders.md',
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::KEY, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_FOLDER_LIST,
                    )
                ]
            ))
            ->param('bucketId', '', new UID(), 'Storage bucket unique ID. You can create a new storage bucket using the Storage service [server integration](https://appwrite.io/docs/server/storage#createBucket).')
            ->param('folder', '', new Folder(), 'Virtual folder to list the child folders of. Defaults to the bucket root.', true)
            ->param('limit', 25, new Range(1, 100), 'Maximum number of folders to return. Must be between 1 and 100. A page shorter than this limit means the listing is complete.', true)
            ->param('cursor', '', new Folder(), 'Folder path returned last by a previous page, used to continue the listing.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('authorization')
            ->inject('user')
            ->callback($this->action(...));
    }

    public function action(
        string $bucketId,
        string $folder,
        int $limit,
        string $cursor,
        Response $response,
        Database $dbForProject,
        Authorization $authorization,
        User $user
    ) {
        $bucket = $authorization->skip(fn () => $dbForProject->getDocument('buckets', $bucketId));

        $isAPIKey = $user->isKey($authorization->getRoles());
        $isPrivilegedUser = $user->isPrivileged($authorization->getRoles());

        if ($bucket->isEmpty() || (!$bucket->getAttribute('enabled') && !$isAPIKey && !$isPrivilegedUser)) {
            throw new Exception(Exception::STORAGE_BUCKET_NOT_FOUND);
        }

        $fileSecurity = $bucket->getAttribute('fileSecurity', false);
        $valid = $authorization->isValid(new Input(Database::PERMISSION_READ, $bucket->getRead()));
        if (!$fileSecurity && !$valid) {
            throw new Exception(Exception::USER_UNAUTHORIZED, $authorization->getDescription());
        }

        $parent = Folder::normalize($folder);
        $cursor = Folder::normalize($cursor);

        if ($cursor !== '') {
            $remainder = \substr($cursor, \strlen($parent), -1);
            if (!\str_starts_with($cursor, $parent) || $cursor === $parent || \str_contains($remainder, '/')) {
                throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, "Folder '{$cursor}' for the 'cursor' value is not an immediate child of '{$parent}'.");
            }
        }

        $collection = 'bucket_' . $bucket->getSequence();
        $seek = $cursor === '' ? $parent : self::seekPast($cursor);
        $folders = [];

        try {
            while (\count($folders) < $limit) {
                $queries = [
                    Query::greaterThan('folder', $seek),
                    Query::orderAsc('folder'),
                    Query::limit(1),
                ];
                if ($parent !== '') {
                    $queries[] = Query::startsWith('folder', $parent);
                }

                if ($fileSecurity && !$valid) {
                    $results = $dbForProject->find($collection, $queries);
                } else {
                    $results = $authorization->skip(fn () => $dbForProject->find($collection, $queries));
                }

                if (empty($results)) {
                    break;
                }

                $fileParent = $results[0]->getAttribute('folder', '');
                $segment = \explode('/', \substr($fileParent, \strlen($parent)))[0];
                $key = $parent . $segment . '/';

                $folders[] = new Document([
                    'key' => $key,
                    'name' => $segment,
                    'parent' => $parent,
                ]);

                $seek = self::seekPast($key);
            }
        } catch (NotFoundException) {
            throw new Exception(Exception::STORAGE_BUCKET_NOT_FOUND);
        }

        $response->dynamic(new Document([
            'folders' => $folders,
        ]), Response::MODEL_FOLDER_LIST);
    }

    /**
     * Smallest string that sorts after every `folder` value inside the given
     * folder: the trailing '/' replaced with the next code point ('0'), so a
     * seek jumps past all of the folder's contents in one indexed query.
     * Assumes the database orders `folder` values bytewise (binary collation),
     * so nothing sorts between '/' (0x2F) and '0' (0x30).
     */
    private static function seekPast(string $folder): string
    {
        return \substr($folder, 0, -1) . \chr(\ord('/') + 1);
    }
}

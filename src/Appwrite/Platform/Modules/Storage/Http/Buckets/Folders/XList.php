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
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Authorization\Input;
use Utopia\Database\Validator\Queries;
use Utopia\Database\Validator\Query\Filter;
use Utopia\Database\Validator\Query\Limit;
use Utopia\Database\Validator\Query\Offset;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;

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
                description: <<<EOT
                Get a list of the child folders directly inside a virtual folder in a bucket. Folders are derived from the folder paths of existing files: a folder exists while at least one file lives under it, and empty pages mean the listing is complete.
                EOT,
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::KEY, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_FOLDER_LIST,
                    )
                ]
            ))
            ->param('bucketId', '', new UID(), 'Storage bucket unique ID. You can create a new storage bucket using the Storage service [server integration](https://appwrite.io/docs/server/storage#createBucket).')
            ->param('queries', [], new Queries([
                new Limit(100),
                new Offset(),
                new Filter([
                    new Document([
                        'key' => 'folder',
                        'type' => Database::VAR_STRING,
                        'array' => false,
                    ]),
                ], Database::VAR_STRING, 1),
            ]), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Only supported methods are equal on the folder attribute, limit, and offset.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('authorization')
            ->inject('user')
            ->callback($this->action(...));
    }

    public function action(
        string $bucketId,
        array $queries,
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

        try {
            $queries = Query::parseQueries($queries);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        $grouped = Query::groupByType($queries);
        $filters = $grouped['filters'];
        if (\count($filters) > 1) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, 'Only one folder filter is allowed.');
        }

        $parent = '';
        if (!empty($filters)) {
            $filter = $filters[0];
            if ($filter->getMethod() !== Query::TYPE_EQUAL || $filter->getAttribute() !== 'folder' || \count($filter->getValues()) !== 1) {
                throw new Exception(Exception::GENERAL_QUERY_INVALID, 'Only equal queries on the folder attribute are supported.');
            }

            $folder = $filter->getValue();
            $validator = new Folder();
            if (!$validator->isValid($folder)) {
                throw new Exception(Exception::GENERAL_QUERY_INVALID, $validator->getDescription());
            }

            $parent = Folder::normalize($folder);
        }

        $limit = $grouped['limit'] ?? 25;
        $offset = $grouped['offset'] ?? 0;
        $pageEnd = $offset > PHP_INT_MAX - $limit ? PHP_INT_MAX : $offset + $limit;

        $collection = 'bucket_' . $bucket->getSequence();
        $folders = [];
        $latest = null;
        $batch = 1000;

        try {
            do {
                $batchQueries = [Query::limit($batch)];
                if ($latest !== null) {
                    $batchQueries[] = Query::cursorAfter($latest);
                }

                if ($fileSecurity && !$valid) {
                    $results = $dbForProject->find($collection, $batchQueries);
                } else {
                    $results = $authorization->skip(fn () => $dbForProject->find($collection, $batchQueries));
                }

                if (empty($results)) {
                    break;
                }

                foreach ($results as $file) {
                    $fileParent = $file->getAttribute('folder', '');
                    if ($fileParent === $parent || !\str_starts_with($fileParent, $parent)) {
                        continue;
                    }

                    $segment = \explode('/', \substr($fileParent, \strlen($parent)))[0];
                    $key = $parent . $segment . '/';

                    $folders[$key] = new Document([
                        'key' => $key,
                        'name' => $segment,
                        'parent' => $parent,
                    ]);
                }

                \ksort($folders, \SORT_STRING);
                $folders = \array_slice($folders, 0, $pageEnd, true);
                $latest = $results[\array_key_last($results)];
            } while (\count($results) === $batch);
        } catch (NotFoundException) {
            throw new Exception(Exception::STORAGE_BUCKET_NOT_FOUND);
        }

        $response->dynamic(new Document([
            'folders' => \array_slice(\array_values($folders), $offset, $limit),
        ]), Response::MODEL_FOLDER_LIST);
    }

}

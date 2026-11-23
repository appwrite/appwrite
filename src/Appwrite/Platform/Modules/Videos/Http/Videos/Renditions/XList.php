<?php

namespace Appwrite\Platform\Modules\Videos\Http\Videos\Renditions;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Videos\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Database\Validator\Queries\VideoRenditions;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Order as OrderException;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Query\Cursor;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Query\Method as QueryMethod;
use Utopia\System\System;
use Utopia\Validator\Boolean;

class XList extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'listRenditions';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/videos/:videoId/renditions')
            ->desc('List renditions')
            ->groups(['api', 'videos'])
            ->label('scope', 'videos.read')
            ->label('resourceType', RESOURCE_TYPE_VIDEOS)
            ->label('usage.resource', 'video/{request.videoId}')
            ->label('sdk', new Method(
                namespace: 'videos',
                group: 'renditions',
                name: 'listRenditions',
                // Hidden until videos is generally available; preview SDK builds show it.
                hide: System::getEnv('_APP_SDK_PREVIEW', 'disabled') !== 'enabled',
                description: '/docs/references/videos/list-renditions.md',
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::KEY, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_VIDEO_RENDITION_LIST,
                    )
                ]
            ))
            ->param('videoId', '', new UID(), 'Video unique ID.')
            ->param('queries', [], new VideoRenditions(), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long. You may filter on the following attributes: ' . implode(', ', VideoRenditions::ALLOWED_ATTRIBUTES), true)
            ->param('total', true, new Boolean(true), 'When set to false, the total count returned will be 0 and will not be calculated.', true)
            ->inject('response')
            ->inject('dbForProject')
            ->inject('user')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(
        string $videoId,
        array $queries,
        bool $includeTotal,
        Response $response,
        Database $dbForProject,
        User $user,
        Authorization $authorization
    ): void {
        $video = $this->getAuthorizedVideo($dbForProject, $authorization, $user, $videoId);

        try {
            $queries = Query::parseQueries($queries);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        $queries[] = Query::equal('videoInternalId', [$video->getSequence()]);

        if (\count(Query::getByType($queries, [QueryMethod::Limit])) === 0) {
            $queries[] = Query::limit(APP_LIMIT_SUBQUERY);
        }

        $cursor = Query::getCursorQueries($queries, false);
        $cursor = \reset($cursor);

        if ($cursor !== false) {
            $validator = new Cursor();
            if (!$validator->isValid($cursor)) {
                throw new Exception(Exception::GENERAL_QUERY_INVALID, $validator->getDescription());
            }

            $renditionId = $cursor->getValue();
            $cursorDocument = $authorization->skip(fn () => $dbForProject->getDocument('videosRenditions', $renditionId));

            if ($cursorDocument->isEmpty() || $cursorDocument->getAttribute('videoInternalId') !== $video->getSequence()) {
                throw new Exception(Exception::GENERAL_CURSOR_NOT_FOUND, "Rendition '{$renditionId}' for the 'cursor' value not found.");
            }

            $cursor->setValue($cursorDocument);
        }

        $filterQueries = Query::groupByType($queries)->filters;

        try {
            $renditions = $authorization->skip(fn () => $dbForProject->find('videosRenditions', $queries));
            $total = $includeTotal
                ? $authorization->skip(fn () => $dbForProject->count('videosRenditions', $filterQueries, APP_LIMIT_COUNT))
                : 0;
        } catch (OrderException $e) {
            throw new Exception(Exception::DATABASE_QUERY_ORDER_NULL, "The order attribute '{$e->getAttribute()}' had a null value. Cursor pagination requires all documents order attribute values are non-null.");
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        $response->dynamic(new Document([
            'renditions' => $renditions,
            'total' => $total,
        ]), Response::MODEL_VIDEO_RENDITION_LIST);
    }
}

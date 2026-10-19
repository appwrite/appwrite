<?php

namespace Appwrite\Platform\Modules\Project\Http\Project\Profiles;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Videos\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Validator\Queries\VideoProfiles;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Order as OrderException;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Query\Cursor;
use Utopia\Platform\Action;
use Utopia\Platform\Enum;
use Utopia\Platform\Scope\HTTP;
use Utopia\Query\Method as QueryMethod;
use Utopia\Validator\WhiteList;

class XList extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'listProjectProfiles';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/project/profiles')
            ->httpAlias('/v1/projects/:projectId/profiles')
            ->desc('List video profiles')
            ->groups(['api', 'project'])
            ->label('scope', 'project.profiles.read')
            ->label('sdk', new Method(
                namespace: 'project',
                group: 'profiles',
                name: 'listProfiles',
                description: '/docs/references/project/list-profiles.md',
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_VIDEO_PROFILE_LIST,
                    )
                ]
            ))
            ->param('queries', [], new VideoProfiles(), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long. You may filter on the following attributes: ' . implode(', ', VideoProfiles::ALLOWED_ATTRIBUTES), true)
            ->param('codec', self::CODEC_H264, new WhiteList(self::codecIds(), true), 'Only return profiles for this encode codec. Defaults to `h264`.', true, enum: new Enum(name: 'VideoCodec'))
            ->inject('response')
            ->inject('dbForProject')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(
        array $queries,
        string $codec,
        Response $response,
        Database $dbForProject,
        Authorization $authorization
    ): void {
        $codec = self::normalizeCodec($codec);
        $this->assertCodecEnabled($codec);

        try {
            $queries = Query::parseQueries($queries);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        $queries[] = Query::equal('codec', [$codec]);

        if (\count(Query::getByType($queries, [QueryMethod::OrderAsc, QueryMethod::OrderDesc])) === 0) {
            $queries[] = Query::orderAsc('height');
        }

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

            $profileId = $cursor->getValue();
            $cursorDocument = $authorization->skip(fn () => $dbForProject->getDocument('videos_profiles', $profileId));

            if ($cursorDocument->isEmpty()) {
                throw new Exception(Exception::GENERAL_CURSOR_NOT_FOUND, "Profile '{$profileId}' for the 'cursor' value not found.");
            }

            $cursor->setValue($cursorDocument);
        }

        try {
            $profiles = $authorization->skip(fn () => $dbForProject->find('videos_profiles', $queries));
        } catch (OrderException $e) {
            throw new Exception(Exception::DATABASE_QUERY_ORDER_NULL, "The order attribute '{$e->getAttribute()}' had a null value. Cursor pagination requires all documents order attribute values are non-null.");
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        $response->dynamic(new Document([
            'profiles' => $profiles,
            'total' => \count($profiles),
        ]), Response::MODEL_VIDEO_PROFILE_LIST);
    }
}

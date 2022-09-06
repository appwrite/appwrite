<?php

use Appwrite\Auth\Auth;
use Appwrite\Event\Delete;
use Appwrite\Event\Transcoding;
use Appwrite\Utopia\Database\Validator\CustomId;
use Appwrite\Utopia\Response;
use Appwrite\Utopia\View;
use Utopia\App;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Appwrite\Extend\Exception;
use Utopia\Storage\Device;
use Utopia\Validator\Boolean;
use Utopia\Validator\Numeric;
use Utopia\Validator\Range;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;
use Utopia\Swoole\Request;

/**
 * Validate file Permissions
 *
 * @param Database $dbForProject
 * @param string $bucketId
 * @param string $fileId
 * @param string $mode
 * @return Document $file
 * @throws Exception
 */
function validateFilePermissions(Database $dbForProject, string $bucketId, string $fileId, string $mode, Document $user): Document
{

    $bucket = Authorization::skip(fn () => $dbForProject->getDocument('buckets', $bucketId));

    if ($bucket->isEmpty() || (!$bucket->getAttribute('enabled') && $mode !== APP_MODE_ADMIN)) {
        throw new Exception(Exception::STORAGE_BUCKET_NOT_FOUND);
    }

    $fileSecurity = $bucket->getAttribute('fileSecurity', false);
    $validator = new Authorization(Database::PERMISSION_READ);
    $valid = $validator->isValid($bucket->getRead());
    if (!$fileSecurity && !$valid) {
        throw new Exception(Exception::USER_UNAUTHORIZED);
    }

    if ($fileSecurity && !$valid) {
        $file = $dbForProject->getDocument('bucket_' . $bucket->getInternalId(), $fileId);
    } else {
        $file = Authorization::skip(fn() => $dbForProject->getDocument('bucket_' . $bucket->getInternalId(), $fileId));
    }

    return $file;
}

App::post('/v1/videos')
    ->desc('Create Video')
    ->groups(['api', 'video'])
    ->label('scope', 'videos.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'video')
    ->label('sdk.method', 'create')
    ->label('sdk.description', '/docs/references/videos/create.md') // TODO: Create markdown
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_VIDEO)
    ->param('bucketId', null, new UID(), 'Storage bucket unique ID. You can create a new storage bucket using the Storage service [server integration](/docs/server/storage#createBucket).')
    ->param('fileId', '', new CustomId(), 'File ID. Choose your own unique ID or pass the string "unique()" to auto generate it. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->inject('request')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('user')
    ->inject('mode')
    ->action(action: function (string $bucketId, string $fileId, Request $request, Response $response, Database $dbForProject, Document $user, string $mode) {

        $file = validateFilePermissions($dbForProject, $bucketId, $fileId, $mode, $user);
        $video = Authorization::skip(function () use ($dbForProject, $bucketId, $file) {
            return $dbForProject->createDocument('videos', new Document([
                'bucketId'  => $bucketId,
                'fileId'    => $file->getId(),
                'size'      => $file->getAttribute('sizeOriginal'),
            ]));
        });
        $response->setStatusCode(Response::STATUS_CODE_CREATED);
        $response->dynamic($video, Response::MODEL_VIDEO);
    });

App::delete('/v1/videos/:videoId')
    ->desc('Delete video')
    ->groups(['api', 'video'])
    ->label('scope', 'videos.write')
    ->label('sdk.namespace', 'video')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.method', 'delete')
    ->label('sdk.description', '/docs/references/videos/delete.md') // TODO: Create markdown
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->param('videoId', '', new UID(), 'Video unique ID.')
    ->inject('response')
    ->inject('project')
    ->inject('dbForProject')
    ->inject('mode')
    ->inject('user')
    ->inject('deletes')
    ->action(function (string $videoId, Response $response, Document $project, Database $dbForProject, string $mode, Document $user, Delete $deletes) {

        $video = Authorization::skip(fn() => $dbForProject->findOne('videos', [new Query('_uid', Query::TYPE_EQUAL, [$videoId])]));

        if ($video->isEmpty()) {
            throw new Exception('Video not found', 404, Exception::VIDEO_NOT_FOUND);
        }

        $deleted = $dbForProject->deleteDocument('videos', $videoId);

        if (!$deleted) {
            throw new Exception('Failed to remove video from DB', 500, Exception::GENERAL_SERVER_ERROR);
        }

        $deletes
            ->setType(DELETE_TYPE_DOCUMENT)
            ->setDocument($video);

        $response->noContent();
    });

App::put('/v1/videos/:videoId')
    ->desc('Update video')
    ->groups(['api', 'video'])
    ->label('scope', 'videos.write')
    ->label('sdk.namespace', 'video')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.method', 'update')
    ->label('sdk.description', '/docs/references/videos/update.md') // TODO: Create markdown
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_VIDEO)
    ->param('videoId', '', new UID(), 'Video unique ID.')
    ->param('bucketId', null, new UID(), 'Storage bucket unique ID. You can create a new storage bucket using the Storage service [server integration](/docs/server/storage#createBucket).')
    ->param('fileId', '', new CustomId(), 'File ID. Choose your own unique ID or pass the string "unique()" to auto generate it. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->inject('response')
    ->inject('project')
    ->inject('dbForProject')
    ->inject('mode')
    ->inject('user')
    ->action(function (string $videoId, $bucketId, $fileId, Response $response, Document $project, Database $dbForProject, string $mode, Document $user) {

        $video = Authorization::skip(fn() => $dbForProject->findOne('videos', [new Query('_uid', Query::TYPE_EQUAL, [$videoId])]));

        if ($video->isEmpty()) {
            throw new Exception('Video not found', 404, Exception::VIDEO_NOT_FOUND);
        }

        $file = validateFilePermissions($dbForProject, $bucketId, $fileId, $mode, $user);

        $video = Authorization::skip(function () use ($dbForProject, $videoId, $bucketId, $file) {
            return $dbForProject->updateDocument('videos', $videoId, new Document([
                'bucketId'  => $bucketId,
                'fileId'    => $file->getId(),
                'size'      => $file->getAttribute('sizeOriginal'),
                'duration' =>  null,
                'width' =>  null,
                'height' =>  null,
                'videoCodec' =>  null,
                'videoBitrate' =>  null,
                'videoFramerate' =>  null,
                'audioCodec' =>  null,
                'audioBitrate' =>  null,
                'audioSamplerate' => null,
            ]));
        });

        $response->dynamic($video, Response::MODEL_VIDEO);
    });

App::get('/v1/videos/:videoId')
    ->desc('Get video ')
    ->groups(['api', 'video'])
    ->label('scope', 'videos.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'video')
    ->label('sdk.method', 'get')
    ->label('sdk.description', '/docs/references/videos/get-video.md') // TODO: Create markdown
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_VIDEO)
    ->param('videoId', '', new UID(), 'Video  unique ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (string $videoId, Response $response, Database $dbForProject) {

        $video = Authorization::skip(fn() => $dbForProject->getDocument('videos', $videoId));

        if ($video->isEmpty()) {
            throw new Exception('Video  not found', 404, Exception::VIDEO_NOT_FOUND);
        }

        $response->dynamic($video, Response::MODEL_VIDEO);
    });

App::get('/v1/videos')
    ->desc('Get video project list ')
    ->groups(['api', 'video'])
    ->label('scope', 'videos.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'video')
    ->label('sdk.method', 'list')
    ->label('sdk.description', '/docs/references/videos/get-video-list.md') // TODO: Create markdown
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_VIDEO)
    ->param('limit', 25, new Range(0, 100), 'Maximum number of files to return in response. By default will return maximum 25 results. Maximum of 100 results allowed per request.', true)
    ->param('offset', 0, new Range(0, APP_LIMIT_COUNT), 'Offset value. The default value is 0. Use this param to manage pagination. [learn more about pagination](https://appwrite.io/docs/pagination)', true)
    ->param('cursor', '', new UID(), 'ID of the file used as the starting point for the query, excluding the file itself. Should be used for efficient pagination when working with large sets of data. [learn more about pagination](https://appwrite.io/docs/pagination)', true)
    ->param('cursorDirection', Database::CURSOR_AFTER, new WhiteList([Database::CURSOR_AFTER, Database::CURSOR_BEFORE]), 'Direction of the cursor, can be either \'before\' or \'after\'.', true)
    ->param('orderType', 'ASC', new WhiteList(['ASC', 'DESC'], true), 'Order result by ASC or DESC order.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (int $limit, int $offset, string $cursor, string $cursorDirection, string $orderType, Response $response, Database $dbForProject) {

        if (!empty($cursor)) {
            $cursorFile = $dbForProject->getDocument('videos', $cursor);

            if ($cursorFile->isEmpty()) {
                throw new Exception("File '{$cursor}' for the 'cursor' value not found.", 400, Exception::GENERAL_CURSOR_NOT_FOUND);
            }
        }

        $videos = $dbForProject->find('videos', [], $limit, $offset, [], [$orderType], $cursorFile ?? null, $cursorDirection);

        if (empty($videos)) {
            throw new Exception('Videos  not found', 404, Exception::VIDEO_NOT_FOUND);
        }

        $response->dynamic(new Document([
            'total' => $dbForProject->count('videos', [], APP_LIMIT_COUNT),
            'videos' => $videos,
        ]), Response::MODEL_VIDEO_LIST);
    });


App::post('/v1/videos/:videoId/subtitles')
    ->desc('Add subtitle to video')
    ->groups(['api', 'video'])
    ->label('scope', 'videos.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'video')
    ->label('sdk.method', 'addSubtitle')
    ->label('sdk.description', '/docs/references/videos/add-subtitle.md') // TODO: Create markdown
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_SUBTITLE)
    ->param('videoId', null, new UID(), 'Video unique ID.')
    ->param('bucketId', '', new CustomId(), 'Subtitle bucket unique ID.')
    ->param('fileId', '', new CustomId(), 'Subtitle file unique ID.')
    ->param('name', '', new Text(128), 'Subtitle name.')
    ->param('code', '', new Text(128), 'Subtitle code name.')
    ->param('default', false, new Boolean(true), 'Default subtitle.')
    ->inject('request')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('user')
    ->inject('mode')
    ->action(action: function (string $videoId, string $bucketId, string $fileId, string $name, string $code, bool $default, Request $request, Response $response, Database $dbForProject, Document $user, string $mode) {

        $video = Authorization::skip(fn() => $dbForProject->findOne('videos', [new Query('_uid', Query::TYPE_EQUAL, [$videoId])]));

        if (empty($video)) {
            throw new Exception('Video not found', 404, Exception::VIDEO_NOT_FOUND);
        }

        validateFilePermissions($dbForProject, $video['bucketId'], $video['fileId'], $mode, $user);
        validateFilePermissions($dbForProject, $bucketId, $fileId, $mode, $user);

        $subtitle = Authorization::skip(function () use ($dbForProject, $videoId, $bucketId, $fileId, $name, $code, $default) {
            return $dbForProject->createDocument('videos_subtitles', new Document([
                'videoId'   => $videoId,
                'bucketId'  => $bucketId,
                'fileId'    => $fileId,
                'name'      => $name,
                'code'      => $code,
                'default'   => $default,
            ]));
        });

        $response->setStatusCode(Response::STATUS_CODE_CREATED);
        $response->dynamic($subtitle, Response::MODEL_SUBTITLE);
    });


App::patch('/v1/videos/:videoId/subtitles/:subtitleId')
    ->desc('Update video subtitle')
    ->groups(['api', 'video'])
    ->label('scope', 'videos.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'video')
    ->label('sdk.method', 'updateSubtitle')
    ->label('sdk.description', '/docs/references/videos/update-subtitle.md') // TODO: Create markdown
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_SUBTITLE)
    ->param('subtitleId', null, new UID(), 'Video subtitle unique ID.')
    ->param('videoId', null, new UID(), 'Video unique ID.')
    ->param('bucketId', '', new CustomId(), 'Subtitle bucket unique ID.')
    ->param('fileId', '', new CustomId(), 'Subtitle file unique ID.')
    ->param('name', '', new Text(128), 'Subtitle name.')
    ->param('code', '', new Text(128), 'Subtitle code name.')
    ->param('default', false, new Boolean(true), 'Default subtitle.')
    ->inject('response')
    ->inject('dbForProject')
    ->action(action: function (string $subtitleId, string $videoId, string $bucketId, string $fileId, string $name, string $code, bool $default, Response $response, Database $dbForProject) {

        $subtitle = Authorization::skip(fn() => $dbForProject->getDocument('videos_subtitles', $subtitleId));

        if ($subtitle->isEmpty()) {
            throw new Exception('Project not found', 404, Exception::PROJECT_NOT_FOUND);
        }

        $subtitle->setAttribute('videoId', $videoId)
            ->setAttribute('bucketId', $bucketId)
            ->setAttribute('fileId', $fileId)
            ->setAttribute('name', $name)
            ->setAttribute('code', $code)
            ->setAttribute('default', $default);

        $subtitle = Authorization::skip(fn() => $dbForProject->updateDocument('videos_subtitles', $subtitle->getId(), $subtitle));

        $response->dynamic($subtitle, Response::MODEL_SUBTITLE);
    });


App::delete('/v1/videos/:videoId/subtitles/:subtitleId')
    ->desc('Delete video subtitle')
    ->groups(['api', 'video'])
    ->label('scope', 'videos.write')
    ->label('sdk.namespace', 'video')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.method', 'deleteSubtitle')
    ->label('sdk.description', '/docs/references/videos/delete-subtitle.md') // TODO: Create markdown
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->param('videoId', '', new UID(), 'Video  unique ID.')
    ->param('subtitleId', '', new UID(), 'Subtitle unique ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('user')
    ->inject('mode')
    ->action(function (string $videoId, string $subtitleId, Response $response, Database $dbForProject, Document $user, string $mode) {

        $video = Authorization::skip(fn() => $dbForProject->findOne('videos', [new Query('_uid', Query::TYPE_EQUAL, [$videoId])]));

        if ($video->isEmpty()) {
            throw new Exception('Video not found', 404, Exception::VIDEO_NOT_FOUND);
        }

        validateFilePermissions($dbForProject, $video['bucketId'], $video['fileId'], $mode, $user);

        $subtitle = Authorization::skip(fn() => $dbForProject->getDocument('videos_subtitles', $subtitleId));

        if ($subtitle->isEmpty()) {
            throw new Exception('Video subtitle not found', 404, Exception::VIDEO_PROFILE_NOT_FOUND);
        }

        $deleted = $dbForProject->deleteDocument('videos_subtitles', $subtitleId);

        if (!$deleted) {
            throw new Exception('Failed to remove video subtitle from DB', 500, Exception::GENERAL_SERVER_ERROR);
        }

        $response->noContent();
    });


App::get('/v1/videos/:videoId/subtitles')
    ->desc('Get all video subtitles')
    ->groups(['api', 'video'])
    ->label('scope', 'videos.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'video')
    ->label('sdk.method', 'getSubtitles')
    ->label('sdk.description', '/docs/references/videos/get-subtitles.md') // TODO: Create markdown
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_SUBTITLE_LIST)
    ->param('videoId', null, new UID(), 'Video unique ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->action(function ($videoId, Response $response, Database $dbForProject) {

        $subtitles = Authorization::skip(fn () => $dbForProject->find('videos_subtitles', [new Query('videoId', Query::TYPE_EQUAL, [$videoId])]));

        if (empty($subtitles)) {
            throw new Exception('Video subtitles  not found', 404, Exception::VIDEO_SUBTITLE_NOT_FOUND);
        }

        $response->dynamic(new Document([
            'total' => $dbForProject->count('videos_subtitles', [], APP_LIMIT_COUNT),
            'subtitles' => $subtitles,
        ]), Response::MODEL_SUBTITLE_LIST);
    });


App::post('/v1/videos/:videoId/rendition')
    ->alias('/v1/videos/:videoId/rendition', [])
    ->desc('Create video rendition')
    ->groups(['api', 'video'])
    ->label('scope', 'videos.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'video')
    ->label('sdk.method', 'createRendition')
    ->label('sdk.description', '/docs/references/videos/create-rendition.md') // TODO: Create markdown
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->param('videoId', null, new UID(), 'Video unique ID.')
    ->param('profileId', '', new CustomId(), 'Profile unique ID.')
    ->inject('request')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('project')
    ->inject('user')
    ->inject('mode')
    ->action(action: function (string $videoId, string $profileId, Request $request, Response $response, Database $dbForProject, Document $project, Document $user, string $mode) {

        $video =  Authorization::skip(fn() =>  $dbForProject->findOne('videos', [
            Query::equal('_uid', [$videoId]),
        ]));

        if (empty($video)) {
            throw new Exception('Video not found', 404, Exception::VIDEO_NOT_FOUND);
        }

        validateFilePermissions($dbForProject, $video->getAttribute('bucketId'), $video->getAttribute('fileId'), $mode, $user);

        $profile =  Authorization::skip(fn() =>  $dbForProject->findOne('videos_profiles', [
                    Query::equal('_uid', [$profileId]),
                    ]));


        if (empty($profile)) {
            throw new Exception('Video profile not found', 404, Exception::VIDEO_PROFILE_NOT_FOUND);
        }

        $transcoder = new Transcoding();
        $transcoder
            ->setUser($user)
            ->setProject($project)
            ->setVideoId($video->getId())
            ->setProfileId($profile->getId())
            ->trigger();

        $response->noContent();
    });


App::delete('/v1/videos/:videoId/renditions/:renditionId')
    ->desc('Delete video rendition')
    ->groups(['api', 'video'])
    ->label('scope', 'videos.write')
    ->label('sdk.namespace', 'video')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.method', 'deleteRendition')
    ->label('sdk.description', '/docs/references/videos/delete-rendition.md') // TODO: Create markdown
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->param('videoId', '', new UID(), 'Video unique ID.')
    ->param('renditionId', '', new UID(), 'Video rendition unique ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('mode')
    ->inject('user')
    ->inject('deviceVideos')
    ->action(function (string $videoId, string $renditionId, Response $response, Database $dbForProject, string $mode, Document $user, Device $deviceVideos) {

        $video = Authorization::skip(fn() => $dbForProject->findOne('videos', [new Query('_uid', Query::TYPE_EQUAL, [$videoId])]));

        if ($video->isEmpty()) {
            throw new Exception('Video not found', 404, Exception::VIDEO_NOT_FOUND);
        }

        validateFilePermissions($dbForProject, $video['bucketId'], $video['fileId'], $mode, $user);

        $rendition = Authorization::skip(fn() => $dbForProject->getDocument('videos_renditions', $renditionId));

        if ($rendition->isEmpty()) {
            throw new Exception('Video rendition not found', 404, Exception::VIDEO_RENDITION_NOT_FOUND);
        }

        $deleted = $dbForProject->deleteDocument('videos_renditions', $renditionId);

        if (!$deleted) {
            throw new Exception('Failed to remove video rendition from DB', 500, Exception::GENERAL_SERVER_ERROR);
        }

        Authorization::skip(fn() => $dbForProject->deleteDocument('videos_renditions', $rendition->getId()));
        if (!empty($rendition['path'])) {
            $deviceVideos->deletePath($rendition['path']);
        }

        $response->noContent();
    });


App::get('/v1/videos/:videoId/rendition/:renditionId')
    ->desc('Get all backet\'s videos')
    ->groups(['api', 'video'])
    ->label('scope', 'videos.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'video')
    ->label('sdk.method', 'getRendition')
    ->label('sdk.description', '/docs/references/videos/get-rendition.md') // TODO: Create markdown
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_RENDITION)
    ->param('videoId', null, new UID(), 'Video unique ID.')
    ->param('renditionId', null, new UID(), 'Rendition unique ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('mode')
    ->inject('user')
    ->action(function ($videoId, $renditionId, Response $response, Database $dbForProject, string $mode, Document $user) {

        $video = Authorization::skip(fn() => $dbForProject->findOne('videos', [new Query('_uid', Query::TYPE_EQUAL, [$videoId])]));

        if ($video->isEmpty()) {
            throw new Exception('Video not found', 404, Exception::VIDEO_NOT_FOUND);
        }

        validateFilePermissions($dbForProject, $video['bucketId'], $video['fileId'], $mode, $user);


        $rendition = Authorization::skip(fn () => $dbForProject->findOne('videos_renditions', [new Query('_uid', Query::TYPE_EQUAL, [$renditionId])]));

        if ($rendition->isEmpty()) {
            throw new Exception('Video rendition not found', 404, Exception::VIDEO_RENDITION_NOT_FOUND);
        }

        $response->dynamic($rendition, Response::MODEL_RENDITION);
    });


App::get('/v1/videos/:videoId/renditions')
    ->desc('Get video renditions')
    ->groups(['api', 'video'])
    ->label('scope', 'videos.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'video')
    ->label('sdk.method', 'getRenditions')
    ->label('sdk.description', '/docs/references/videos/get-renditions.md') // TODO: Create markdown
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_RENDITION_LIST)
    ->param('videoId', null, new UID(), 'Video unique ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('mode')
    ->inject('user')
    ->action(function (string $videoId, Response $response, Database $dbForProject, string $mode, Document $user) {

        $video = Authorization::skip(fn() => $dbForProject->findOne('videos', [new Query('_uid', Query::TYPE_EQUAL, [$videoId])]));

        if ($video->isEmpty()) {
            throw new Exception('Video not found', 404, Exception::VIDEO_NOT_FOUND);
        }

        validateFilePermissions($dbForProject, $video['bucketId'], $video['fileId'], $mode, $user);

        $queries = [
            new Query('videoId', Query::TYPE_EQUAL, [$video->getId()]),
            new Query('endedAt', Query::TYPE_GREATER, [0]),
            new Query('status', Query::TYPE_EQUAL, ['ready']),
        ];

        $renditions = Authorization::skip(fn () => $dbForProject->find('videos_renditions', $queries));

        $response->dynamic(new Document([
            'total'      => $dbForProject->count('videos_renditions', $queries, APP_LIMIT_COUNT),
            'renditions' => $renditions,
        ]), Response::MODEL_RENDITION_LIST);
    });


App::get('/v1/videos/:videoId/protocols/:protocolId')
    ->desc('Get video master renditions manifest')
    ->groups(['api', 'video'])
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'video')
    ->label('sdk.method', 'getMasterManifest')
    ->label('sdk.description', '/docs/references/videos/get-master-manifest.md') // TODO: Create markdown
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('scope', 'videos.read')
    ->param('videoId', null, new UID(), 'Video unique ID.')
    ->param('protocolId', '', new WhiteList(['hls', 'dash']), 'protocol name')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('mode')
    ->inject('user')
    ->action(function (string $videoId, string $protocolId, Response $response, Database $dbForProject, string $mode, Document $user) {

        $video =  Authorization::skip(fn() =>  $dbForProject->findOne('videos', [
            Query::equal('_uid', [$videoId]),
        ]));

        if (empty($video)) {
            throw new Exception('Video not found', 404, Exception::VIDEO_NOT_FOUND);
        }

        validateFilePermissions($dbForProject, $video['bucketId'], $video['fileId'], $mode, $user);

        $renditions = Authorization::skip(fn () => $dbForProject->find('videos_renditions', [
            Query::equal('videoId', [$video->getId()]),
            Query::equal('status', ['ready']),
            Query::equal('protocol', [$protocolId]),
        ]));

        if (empty($renditions)) {
            throw new Exception('Renditions  not found', 404, Exception::VIDEO_RENDITION_NOT_FOUND);
        }

        $baseUrl = 'http://127.0.0.1/v1/videos/' . $videoId . '/protocols/' . $protocolId;
        $subtitles = Authorization::skip(fn() => $dbForProject->find('videos_subtitles', [
            Query::equal('videoId', [$video->getId()]),
        ]));

        $_renditions = [];
        $_subtitles = [];

        if ($protocolId === 'hls') {
            foreach ($subtitles ?? [] as $subtitle) {
                $_subtitles[] = [
                    'name' => $subtitle->getAttribute('name'),
                    'code' => $subtitle->getAttribute('code'),
                    'default' => !empty($subtitle->getAttribute('default')) ? 'YES' : 'NO',
                    'uri' => $baseUrl . '/subtitles/' . $subtitle->getId(),
                ];
            }

            foreach ($renditions as $rendition) {
                $uri = null;
                $_audios = [];
                $metadata = $rendition->getAttribute('metadata');
                $streams = $metadata['hls'];
                foreach ($streams as $i => $stream) {
                    if ($stream['type'] === 'audio') {
                        $_audios[] = [
                            'type' => 'group_audio',
                            'name' => $stream['language'],
                            'default' => ($i === 0) ? 'YES' : 'NO',
                            'language' => $stream['language'],
                            'uri' => $baseUrl . '/renditions/' . $rendition->getId() . '/streams/' . $stream['id'],
                        ];
                    } elseif ($stream['type'] === 'video') {
                        $uri = $baseUrl . '/renditions/' . $rendition->getId() . '/streams/' . $stream['id'];
                    }
                }

                $_renditions[] = [
                    'bandwidth' => ($rendition->getAttribute('videoBitrate') + $rendition->getAttribute('audioBitrate')),
                    'resolution' => $rendition->getAttribute('width') . 'X' . $rendition->getAttribute('height'),
                    'name' => $rendition->getAttribute('name'),
                    'uri' => $uri,
                    'subs' => !empty($_subtitles) ? 'subs' : null,
                    'audio' => !empty($_audios) ? 'group_audio' : null,
                ];
            }

            $template = new View(__DIR__ . '/../../views/videos/hls-master.phtml');
            $template->setParam('audios', $_audios);
            $template->setParam('subtitles', $_subtitles);
            $template->setParam('renditions', $_renditions);
            $response
                ->setContentType('application/x-mpegurl')
                ->send($template->render(false))
            ;
        } else {
            $adaptationId = 0;
            foreach ($renditions as $rendition) {
                $metadata = $rendition->getAttribute('metadata');
                $xml = simplexml_load_string($metadata['mpd']);
                if (empty($xml)) {
                    continue;
                }

                $attributes = (array)$xml->attributes();
                $mpd = $attributes['@attributes'] ?? [];
                $streamId = 0;
                foreach ($xml->Period->AdaptationSet ?? [] as $adaptation) {
                    $representation = [];
                    $representation['id'] = $streamId;
                    $attributes = (array)$adaptation->Representation->attributes();
                    $representation['attributes'] = $attributes['@attributes'] ?? [];
                    $attributes = (array)$adaptation->Representation->SegmentList->attributes();
                    $representation['segmentList']['attributes'] = $attributes['@attributes'] ?? [];
                    $segments = Authorization::skip(fn() => $dbForProject->find('videos_renditions_segments', [
                        new Query('renditionId', Query::TYPE_EQUAL, [$rendition->getId()]),
                        new Query('streamId', Query::TYPE_EQUAL, [$streamId]),
                    ], 1000, 0, ['streamId']));

                    if (count($segments) === 0) {
                        continue;
                    }

                    foreach ($segments ?? [] as $segment) {
                        if ($segment->getAttribute('isInit')) {
                            $representation['segmentList']['init'] = $segment->getId();
                            continue;
                        }

                        $representation['segmentList']['media'][] = $segment->getId();
                    }

                    $attributes = (array)$adaptation->attributes();
                    $_renditions[] =  [
                        'attributes' => $attributes['@attributes'] ?? [],
                        'id' => $adaptationId,
                        'baseUrl' => $baseUrl . '/renditions/' . $rendition->getId() . '/' . 'segments/',
                        'representation' => $representation,
                    ];
                    $adaptationId++;
                    $streamId++;
                }
            }

            foreach ($subtitles ?? [] as $subtitle) {
                $_subtitles[] = [
                    'id' => $adaptationId,
                    'baseUrl' => $baseUrl . '/subtitles/' . $subtitle->getId(),
                    'code' => $subtitle->getAttribute('code'),
                ];
                $adaptationId++;
            }

            $template = new View(__DIR__ . '/../../views/videos/dash.phtml');
            $template->setParam('mpd', $mpd);
            $template->setParam('renditions', $_renditions);
            $template->setParam('subtitles', $_subtitles);
            $response
                ->setContentType('application/dash+xml')
                ->send($template->render(false))
            ;
        }
    });


App::get('/v1/videos/:videoId/protocols/:protocolId/renditions/:renditionId/streams/:streamId')
    ->desc('Get video rendition manifest')
    ->groups(['api', 'video'])
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'video')
    ->label('sdk.method', 'getManifest')
    ->label('sdk.description', '/docs/references/videos/get-manifest.md') // TODO: Create markdown
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    // TODO: Response model
    ->label('scope', 'videos.read')
    ->param('videoId', null, new UID(), 'Video unique ID.')
    ->param('protocolId', '', new WhiteList(['hls']), 'protocol name')
    ->param('renditionId', '', new UID(), 'Rendition unique ID.')
    ->param('streamId', '', new Range(0, 10), 'Stream id.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('mode')
    ->inject('user')
    ->action(function (string $videoId, string $protocolId, string $renditionId, string $streamId, Response $response, Database $dbForProject, string $mode, Document $user) {

        $video =  Authorization::skip(fn() =>  $dbForProject->findOne('videos', [
            Query::equal('_uid', [$videoId]),
        ]));

        if (empty($video)) {
            throw new Exception('Video not found', 404, Exception::VIDEO_NOT_FOUND);
        }

        validateFilePermissions($dbForProject, $video['bucketId'], $video['fileId'], $mode, $user);

        $rendition = Authorization::skip(fn () => $dbForProject->findOne('videos_renditions', [
            Query::equal('_uid', [$renditionId]),
            Query::equal('videoId', [$video->getId()]),
            Query::equal('status', ['ready']),
            Query::equal('protocol', [$protocolId]),
        ]));

        if (empty($rendition)) {
            throw new Exception('Rendition  not found', 404, Exception::VIDEO_RENDITION_NOT_FOUND);
        }

        $segments = Authorization::skip(fn () => $dbForProject->find('videos_renditions_segments', [
            Query::equal('renditionId', [$renditionId]),
            Query::equal('streamId', [$streamId]),
        ]));

        if (empty($segments)) {
            throw new Exception('Rendition segments not found', 404, Exception::VIDEO_RENDITION_SEGMENT_NOT_FOUND);
        }

        $_segments = [];
        foreach ($segments as $segment) {
            $_segments[] = [
                'duration' => $segment->getAttribute('duration'),
                'url' => 'http://127.0.0.1/v1/videos/' . $videoId . '/protocols/' . $protocolId . '/renditions/' . $renditionId . '/segments/' . $segment->getId(),
            ];
        }

        $template = new View(__DIR__ . '/../../views/videos/hls.phtml');
        $template->setParam('targetDuration', $rendition->getAttribute('targetDuration'));
        $template->setParam('paramsSegments', $_segments);
        $response->setContentType('application/x-mpegurl')
            ->send($template->render(false));
    });


App::get('/v1/videos/:videoId/protocols/:protocolId/renditions/:renditionId/segments/:segmentId')
    ->desc('Get video rendition segment')
    ->groups(['api', 'video'])
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'video')
    ->label('sdk.method', 'getRenditionSegment')
    ->label('sdk.description', '/docs/references/videos/get-rendition-segment.md') // TODO: Create markdown
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    // TODO: Response model
    ->label('scope', 'videos.read')
    ->param('videoId', null, new UID(), 'Video unique ID.')
    ->param('protocolId', '', new WhiteList(['hls', 'dash']), 'Protocol name')
    ->param('renditionId', '', new UID(), 'Rendition unique ID.')
    ->param('segmentId', '', new UID(), 'Segment unique ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('deviceVideos')
    ->inject('mode')
    ->inject('user')
    ->action(function (string $videoId, string $protocolId, string $renditionId, string $segmentId, Response $response, Database $dbForProject, Device $deviceVideos, string $mode, Document $user) {

        $video =  Authorization::skip(fn() =>  $dbForProject->findOne('videos', [
            Query::equal('_uid', [$videoId]),
        ]));

        if (empty($video)) {
            throw new Exception('Video not found', 404, Exception::VIDEO_NOT_FOUND);
        }

        validateFilePermissions($dbForProject, $video['bucketId'], $video['fileId'], $mode, $user);

        $video =  Authorization::skip(fn() =>  $dbForProject->findOne('videos', [
            Query::equal('_uid', [$videoId]),
        ]));

        $rendition = Authorization::skip(fn () => $dbForProject->findOne('videos_renditions', [
            Query::equal('_uid', [$renditionId]),
            Query::equal('videoId', [$video->getId()]),
            Query::equal('status', ['ready']),
            Query::equal('protocol', [$protocolId]),
        ]));

        if (empty($rendition)) {
            throw new Exception('Rendition  not found', 404, Exception::VIDEO_RENDITION_NOT_FOUND);
        }

        $segment = Authorization::skip(fn () => $dbForProject->findOne('videos_renditions_segments', [
            Query::equal('_uid', [$segmentId]),
        ]));

        if (empty($segment)) {
            throw new Exception('Rendition segments not found', 404, Exception::VIDEO_RENDITION_SEGMENT_NOT_FOUND);
        }

        $output = $deviceVideos->read($segment->getAttribute('path') .  $segment->getAttribute('fileName'));

        if ($protocolId === 'hls') {
            $response->setContentType('video/MP2T')
                ->send($output);
        } else {
            $response->setContentType('video/iso.segment')
                ->send($output);
        }
    });


App::get('/v1/videos/:videoId/protocols/:protocolId/subtitles/:subtitleId')
    ->desc('Get video subtitle')
    ->groups(['api', 'video'])
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'video')
    ->label('sdk.method', 'getSubtitle')
    ->label('sdk.description', '/docs/references/videos/get-subtitle.md') // TODO: Create markdown
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    // TODO: Response model
    ->label('scope', 'videos.read')
    ->param('videoId', null, new UID(), 'Video unique ID.')
    ->param('protocolId', '', new WhiteList(['hls', 'dash']), 'Protocol name')
    ->param('subtitleId', '', new UID(), 'Subtitle unique ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('deviceVideos')
    ->inject('mode')
    ->inject('user')
    ->action(function (string $videoId, string $protocolId, string $subtitleId, Response $response, Database $dbForProject, Device $deviceVideos, string $mode, Document $user) {

        $video = Authorization::skip(fn() => $dbForProject->findOne('videos', [
            new Query('_uid', Query::TYPE_EQUAL, [$videoId])
        ]));

        if (empty($video)) {
            throw new Exception('Video not found', 404, Exception::VIDEO_NOT_FOUND);
        }

        validateFilePermissions($dbForProject, $video['bucketId'], $video['fileId'], $mode, $user);

        $subtitle = Authorization::skip(fn() => $dbForProject->findOne('videos_subtitles', [
            new Query('_uid', Query::TYPE_EQUAL, [$subtitleId]),
            new Query('status', Query::TYPE_EQUAL, ['ready'])
        ]));

        if (empty($subtitle)) {
            throw new Exception('subtitle not found', 404, Exception::VIDEO_SUBTITLE_NOT_FOUND);
        }

        if ($protocolId == 'hls') {
            $segments = Authorization::skip(fn() => $dbForProject->find('videos_subtitles_segments', [
                new Query('subtitleId', Query::TYPE_EQUAL, [$subtitleId]),
            ], 4000));

            if (empty($segments)) {
                throw new Exception('Subtitle segments not found', 404, Exception::VIDEO_SUBTITLE_SEGMENT_NOT_FOUND);
            }

            $paramsSegments = [];
            foreach ($segments as $segment) {
                $paramsSegments[] = [
                    'duration' => $segment->getAttribute('duration'),
                    'url' => 'http://127.0.0.1/v1/videos/' . $videoId . '/protocols/' . $protocolId . '/subtitles/' . $subtitleId . '/segments/' . $segment->getId(),
                ];
            }

            $template = new View(__DIR__ . '/../../views/videos/hls-subtitles.phtml');
            $template->setParam('targetDuration', $subtitle->getAttribute('targetDuration'));
            $template->setParam('paramsSegments', $paramsSegments);
            $response->setContentType('application/x-mpegurl')->send($template->render(false));
        } else {
            $output = $deviceVideos->read($deviceVideos->getPath($subtitle->getAttribute('videoId')) . '/'  . $subtitle->getId() . '.vtt');
            $response->setContentType('text/vtt')->send($output);
        }
    });


App::get('/v1/videos/:videoId/protocols/:protocolId/subtitles/:subtitleId/segments/:segmentId')
    ->desc('Get video subtitle segment')
    ->groups(['api', 'video'])
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'video')
    ->label('sdk.method', 'getSubtitleSegment')
    ->label('sdk.description', '/docs/references/videos/get-subtitle-segment.md') // TODO: Create markdown
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    // TODO: Response model
    ->label('scope', 'videos.read')
    ->param('videoId', null, new UID(), 'Video unique ID.')
    ->param('protocolId', '', new WhiteList(['hls', 'dash']), 'Protocol name')
    ->param('subtitleId', '', new UID(), 'Subtitle unique ID.')
    ->param('segmentId', '', new UID(), 'Segment unique ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('deviceVideos')
    ->inject('mode')
    ->inject('user')
    ->action(function (string $videoId, string $protocolId, string $subtitleId, string $segmentId, Response $response, Database $dbForProject, Device $deviceVideos, string $mode, Document $user) {

        $video = Authorization::skip(fn() => $dbForProject->findOne('videos', [
            new Query('_uid', Query::TYPE_EQUAL, [$videoId])
        ]));

        if (empty($video)) {
            throw new Exception('Video not found', 404, Exception::VIDEO_NOT_FOUND);
        }

        validateFilePermissions($dbForProject, $video['bucketId'], $video['fileId'], $mode, $user);

        $subtitle = Authorization::skip(fn() => $dbForProject->findOne('videos_subtitles', [
            new Query('_uid', Query::TYPE_EQUAL, [$subtitleId]),
            new Query('status', Query::TYPE_EQUAL, ['ready'])
        ]));

        if (empty($subtitle)) {
            throw new Exception('subtitle not found', 404, Exception::VIDEO_SUBTITLE_NOT_FOUND);
        }

        $segment = Authorization::skip(fn () => $dbForProject->findOne('videos_subtitles_segments', [
            new Query('_uid', Query::TYPE_EQUAL, [$segmentId])]));

        if (empty($segment)) {
            throw new Exception('Subtitle segments not found', 404, Exception::VIDEO_SUBTITLE_SEGMENT_NOT_FOUND);
        }

        $output = $deviceVideos->read($segment->getAttribute('path') .  $segment->getAttribute('fileName'));
        $response->setContentType('text/vtt')->send($output);
    });


App::post('/v1/videos/profiles')
    ->desc('Create video profile')
    ->groups(['api', 'video'])
    ->label('scope', 'videos.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'video')
    ->label('sdk.method', 'createProfile')
    ->label('sdk.description', '/docs/references/videos/create-profile.md') // TODO: Create markdown
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PROFILE)
    ->param('name', null, new Text(128), 'Video profile name.')
    ->param('videoBitrate', '', new Range(32, 5000), 'Video profile bitrate in Kbps.')
    ->param('audioBitrate', '', new Range(32, 5000), 'Audio profile bit rate in Kbps.')
    ->param('width', '', new Range(6, 3000), 'Video profile width.')
    ->param('height', '', new Range(6, 3000), 'Video  profile height.')
    ->param('protocol', false, new WhiteList(['hls', 'dash']), 'Video  profile protocol.')
    ->inject('response')
    ->inject('dbForProject')
    ->action(action: function (string $name, string $videoBitrate, string $audioBitrate, string $width, string $height, string $protocol, Response $response, Database $dbForProject) {
        try {
            $profile = Authorization::skip(function () use ($dbForProject, $name, $videoBitrate, $audioBitrate, $width, $height, $protocol) {
                return $dbForProject->createDocument('videos_profiles', new Document([
                    'name'          => $name,
                    'videoBitrate'  => (int)$videoBitrate,
                    'audioBitrate'  => (int)$audioBitrate,
                    'width'         => (int)$width,
                    'height'        => (int)$height,
                    'protocol'        => $protocol,
                ]));
            });
        } catch (DuplicateException $exception) {
            throw new Exception('Profile already exists', 409, Exception::VIDEO_PROFILE_ALREADY_EXISTS);
        }

        $response->setStatusCode(Response::STATUS_CODE_CREATED);
        $response->dynamic($profile, Response::MODEL_PROFILE);
    });


App::patch('/v1/videos/profiles/:profileId')
    ->desc('Update video  profile')
    ->groups(['api', 'video'])
    ->label('scope', 'videos.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'video')
    ->label('sdk.method', 'updateProfile')
    ->label('sdk.description', '/docs/references/videos/update-profile.md') // TODO: Create markdown
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PROFILE)
    ->param('profileId', '', new UID(), 'Video profile unique ID.')
    ->param('name', null, new Text(128), 'Video profile name.')
    ->param('videoBitrate', '', new Range(64, 4000), 'Video profile bitrate in Kbps.')
    ->param('audioBitrate', '', new Range(64, 4000), 'Audio profile bit rate in Kbps.')
    ->param('width', '', new Range(100, 2000), 'Video profile width.')
    ->param('height', '', new Range(100, 2000), 'Video  profile height.')
    ->param('protocol', false, new WhiteList(['hls', 'dash']), 'Video  profile protocol.')
    ->inject('response')
    ->inject('dbForProject')
    ->action(action: function (string $profileId, string $name, string $videoBitrate, string $audioBitrate, string $width, string $height, string $protocol, Response $response, Database $dbForProject) {

        $profile = Authorization::skip(fn() => $dbForProject->getDocument('videos_profiles', $profileId));
        ;
        if ($profile->isEmpty()) {
            throw new Exception('Project not found', 404, Exception::PROJECT_NOT_FOUND);
        }

        $profile->setAttribute('name', $name)
                 ->setAttribute('videoBitrate', (int)$videoBitrate)
                ->setAttribute('audioBitrate', (int)$audioBitrate)
                ->setAttribute('width', (int)$width)
                ->setAttribute('height', (int)$height)
                ->setAttribute('protocol', $protocol);

        $profile = Authorization::skip(fn() => $dbForProject->updateDocument('videos_profiles', $profile->getId(), $profile));

        $response->dynamic($profile, Response::MODEL_PROFILE);
    });


App::get('/v1/videos/profiles/:profileId')
    ->desc('Get video profile')
    ->groups(['api', 'video'])
    ->label('scope', 'videos.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'video')
    ->label('sdk.method', 'getProfile')
    ->label('sdk.description', '/docs/references/videos/get-profile.md') // TODO: Create markdown
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PROFILE)
    ->param('profileId', '', new UID(), 'Video profile unique ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (string $profileId, Response $response, Database $dbForProject) {

        $profile = Authorization::skip(fn() => $dbForProject->getDocument('videos_profiles', $profileId));

        if ($profile->isEmpty()) {
            throw new Exception('Video profile not found', 404, Exception::VIDEO_PROFILE_NOT_FOUND);
        }

        $response->dynamic($profile, Response::MODEL_PROFILE);
    });


App::get('/v1/videos/profiles')
    ->desc('Get all video profiles')
    ->groups(['api', 'video'])
    ->label('scope', 'videos.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'video')
    ->label('sdk.method', 'getProfiles')
    ->label('sdk.description', '/docs/references/videos/get-profiles.md') // TODO: Create markdown
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PROFILE_LIST)
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (Response $response, Database $dbForProject) {

        $profiles = Authorization::skip(fn () => $dbForProject->find('videos_profiles', [], 12, 0, [], ['ASC']));

        if (empty($profiles)) {
            throw new Exception('Video profiles where not found', 404, Exception::VIDEO_PROFILE_NOT_FOUND);
        }

        $response->dynamic(new Document([
            'total' => $dbForProject->count('videos_profiles', [], APP_LIMIT_COUNT),
            'profiles' => $profiles,
        ]), Response::MODEL_PROFILE_LIST);
    });


App::delete('/v1/videos/profiles/:profileId')
    ->desc('Delete video profile')
    ->groups(['api', 'video'])
    ->label('scope', 'videos.write')
    ->label('sdk.namespace', 'video')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.method', 'deleteProfile')
    ->label('sdk.description', '/docs/references/videos/delete-profile.md') // TODO: Create markdown
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->param('profileId', '', new UID(), 'Video profile unique ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (string $profileId, Response $response, Database $dbForProject) {

        $profile = Authorization::skip(fn() => $dbForProject->getDocument('videos_profiles', $profileId));

        if ($profile->isEmpty()) {
            throw new Exception('Video profile not found', 404, Exception::VIDEO_PROFILE_NOT_FOUND);
        }

        $deleted = $dbForProject->deleteDocument('videos_profiles', $profileId);

        if (!$deleted) {
            throw new Exception('Failed to remove video profile from DB', 500, Exception::GENERAL_SERVER_ERROR);
        }

        $response->noContent();
    });

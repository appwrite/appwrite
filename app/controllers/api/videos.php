<?php

use Appwrite\Auth\Auth;

use Appwrite\Event\Audit;
use Appwrite\Event\Audit as EventAudit;
use Appwrite\Event\Database as EventDatabase;
use Appwrite\Event\Delete;
use Appwrite\Event\Event;
use Appwrite\Event\Transcoding;
use Appwrite\Utopia\Database\Validator\CustomId;
use Appwrite\OpenSSL\OpenSSL;
use Appwrite\Stats\Stats;
use Appwrite\Utopia\Response;
use Appwrite\Utopia\View;
use Utopia\App;
use Utopia\Cache\Adapter\Filesystem;
use Utopia\Cache\Cache;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Exception\Structure as StructureException;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Permissions;
use Utopia\Database\Validator\UID;
use Appwrite\Extend\Exception;
use Utopia\Image\Image;
use Utopia\Storage\Compression\Algorithms\GZIP;
use Utopia\Storage\Device;
use Utopia\Storage\Device\Local;
use Utopia\Storage\Storage;
use Utopia\Storage\Validator\File;
use Utopia\Storage\Validator\FileExt;
use Utopia\Storage\Validator\FileSize;
use Utopia\Storage\Validator\Upload;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Boolean;
use Utopia\Validator\HexColor;
use Utopia\Validator\Integer;
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
        throw new Exception('Bucket not found', 404, Exception::STORAGE_BUCKET_NOT_FOUND);
    }

    // Check bucket permissions when enforced
    $permissionBucket = $bucket->getAttribute('permission') === 'bucket';
    if ($permissionBucket) {
        $validator = new Authorization('read');
        if (!$validator->isValid($bucket->getRead())) {
            throw new Exception('Unauthorized file permissions', 401, Exception::USER_UNAUTHORIZED);
        }
    }

    $read = !$user->isEmpty() ? ['user:' . $user->getId()] : []; // By default set read permissions for user

    // Users can only add their roles to files, API keys and Admin users can add any
    $roles = Authorization::getRoles();

    if (!Auth::isAppUser($roles) && !Auth::isPrivilegedUser($roles)) {
        foreach ($read as $role) {
            if (!Authorization::isRole($role)) {
                throw new Exception('Read permissions must be one of: (' . \implode(', ', $roles) . ')', 400, Exception::USER_UNAUTHORIZED);
            }
        }
    }

    if ($bucket->getAttribute('permission') === 'bucket') {
        // skip authorization
        $file = Authorization::skip(fn () => $dbForProject->getDocument('bucket_' . $bucket->getInternalId(), $fileId));
    } else {
        $file = $dbForProject->getDocument('bucket_' . $bucket->getInternalId(), $fileId);
    }

    return $file;
}

App::get('/v1/videos/profiles')
    ->alias('/v1/videos/videos/profiles', [])
    ->desc('Get all video profiles')
    ->groups(['api', 'video'])
    ->label('scope', 'files.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'video')
    ->label('sdk.method', 'getProfiles')
    ->label('sdk.description', '/docs/references/videos/get-profiles.md') // TODO: Create markdown
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_VIDEO_PROFILE_LIST)
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (Response $response, Database $dbForProject) {

        $profiles = Authorization::skip(fn () => $dbForProject->find('videos_profiles', [], 12, 0, [], ['ASC']));

        if (empty($profiles)) {
            throw new Exception('Video profiles where not found', 404, Exception::PROFILES_NOT_FOUND);
        }

        $response->dynamic(new Document([
            'total' => $dbForProject->count('videos_profiles', [], APP_LIMIT_COUNT),
            'profiles' => $profiles,
        ]), Response::MODEL_VIDEO_PROFILE_LIST);
    });


App::post('/v1/videos/:videoId/subtitles')
    ->alias('/v1/videos/:videoId/subtitles', [])
    ->desc('Attach a subtitle file to a video')
    ->groups(['api', 'video'])
    ->label('scope', 'files.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'video')
    ->label('sdk.method', 'addSubtitles')
    ->label('sdk.description', '/docs/references/videos/add-subtitles.md') // TODO: Create markdown
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_NONE)
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
    ->inject('audits')
    ->inject('usage')
    ->inject('events')
    ->inject('mode')
    ->inject('deviceFiles')
    ->inject('deviceLocal')
    ->action(action: function (string $videoId, string $bucketId, string $fileId, string $name, string $code, bool $default, Request $request, Response $response, Database $dbForProject, Document $user, Audit $audits, Stats $usage, Event $events, string $mode, Device $deviceFiles, Device $deviceLocal) {

        $video = Authorization::skip(fn() => $dbForProject->findOne('videos', [new Query('_uid', Query::TYPE_EQUAL, [$videoId])]));

        if (empty($video)) {
            throw new Exception('Video not found', 400, Exception::VIDEO_NOT_FOUND);
        }

        validateFilePermissions($dbForProject, $video['bucketId'], $video['fileId'], $mode, $user);
        validateFilePermissions($dbForProject, $bucketId, $fileId, $mode, $user);

        try {
            Authorization::skip(function () use ($dbForProject, $videoId, $bucketId, $fileId, $name, $code, $default) {
                return $dbForProject->createDocument('videos_subtitles', new Document([
                    'videoId'   => $videoId,
                    'bucketId'  => $bucketId,
                    'fileId'    => $fileId,
                    'name'      => $name,
                    'code'      => $code,
                    'default'   => $default,
                ]));
            });
        } catch (StructureException $exception) {
            throw new Exception($exception->getMessage(), 400, Exception::DOCUMENT_INVALID_STRUCTURE);
        }

        $response->json(['result' => 'ok']);
    });


App::post('/v1/video')
    ->desc('Create Video')
    ->groups(['api', 'video'])
    ->label('scope', 'files.write')
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
    ->inject('audits')
    ->inject('usage')
    ->inject('events')
    ->inject('mode')
    ->inject('deviceFiles')
    ->inject('deviceLocal')
    ->action(action: function (string $bucketId, string $fileId, Request $request, Response $response, Database $dbForProject, Document $user, Audit $audits, Stats $usage, Event $events, string $mode, Device $deviceFiles, Device $deviceLocal) {
        /** @var Utopia\Database\Document $project */

        $file = validateFilePermissions($dbForProject, $bucketId, $fileId, $mode, $user);

        try {
            $video = Authorization::skip(function () use ($dbForProject, $bucketId, $file) {
                return $dbForProject->createDocument('videos', new Document([
                    'bucketId'  => $bucketId,
                    'fileId'    => $file->getId(),
                    'size'      => $file->getAttribute('sizeOriginal'),
                ]));
            });
        } catch (StructureException $exception) {
            throw new Exception($exception->getMessage(), 400, Exception::DOCUMENT_INVALID_STRUCTURE);
        }

        $response->dynamic(new Document([
            '$id'     =>  $video->getId(),
            'fileId'   => $video['fileId'],
            'bucketId' => $video['bucketId'],
            'size'     => $video['size'],
        ]), Response::MODEL_VIDEO);
    });


App::post('/v1/videos/:videoId/rendition')
    ->alias('/v1/videos/:videoId/rendition', [])
    ->desc('Start transcoding video rendition')
    ->groups(['api', 'video'])
    ->label('scope', 'files.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'video')
    ->label('sdk.method', 'createTranscoding')
    ->label('sdk.description', '/docs/references/videos/create-transcoding.md') // TODO: Create markdown
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
//    ->label('event', 'buckets.[bucketId].files.[fileId].create')
    ->param('videoId', null, new UID(), 'Video unique ID.')
    ->param('profileId', '', new CustomId(), 'Profile unique ID.')
    ->inject('request')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('project')
    ->inject('user')
    ->inject('audits')
    ->inject('usage')
    ->inject('events')
    ->inject('mode')
    ->inject('deviceFiles')
    ->inject('deviceLocal')
    ->action(action: function (string $videoId, string $profileId, Request $request, Response $response, Database $dbForProject, Document $project, Document $user, Audit $audits, Stats $usage, Event $events, string $mode, Device $deviceFiles, Device $deviceLocal) {

        $video = Authorization::skip(fn() => $dbForProject->findOne('videos', [new Query('_uid', Query::TYPE_EQUAL, [$videoId])]));

        if ($video->isEmpty()) {
            throw new Exception('Video not found', 400, Exception::VIDEO_NOT_FOUND);
        }

        validateFilePermissions($dbForProject, $video['bucketId'], $video['fileId'], $mode, $user);

        $profile = Authorization::skip(fn() => $dbForProject->findOne('videos_profiles', [new Query('_uid', Query::TYPE_EQUAL, [$profileId])]));

        if (!$profile) {
            throw new Exception('Video profile not found', 400, Exception::PROFILES_NOT_FOUND);
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


App::get('/v1/videos/:videoId/:stream/renditions')
    ->alias('/v1/videos/:videoId/renditions', [])
    ->desc('Get File renditions')
    ->groups(['api', 'video'])
    ->label('scope', 'files.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'video')
    ->label('sdk.method', 'getRenditions')
    ->label('sdk.description', '/docs/references/videos/get-renditions.md') // TODO: Create markdown
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_VIDEO_RENDITIONS_LIST)
    ->param('videoId', null, new UID(), 'Video unique ID.')
    ->param('stream', '', new WhiteList(['hls', 'mpeg-dash']), 'stream protocol name')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('usage')
    ->inject('mode')
    ->inject('user')
    ->action(function (string $videoId, string $stream, Response $response, Database $dbForProject, Stats $usage, string $mode, Document $user) {

        $video = Authorization::skip(fn() => $dbForProject->findOne('videos', [new Query('_uid', Query::TYPE_EQUAL, [$videoId])]));

        if ($video->isEmpty()) {
            throw new Exception('Video not found', 400, Exception::VIDEO_NOT_FOUND);
        }

        validateFilePermissions($dbForProject, $video['bucketId'], $video['fileId'], $mode, $user);

        $queries = [
            new Query('videoId', Query::TYPE_EQUAL, [$video->getId()]),
            new Query('endedAt', Query::TYPE_GREATER, [0]),
            new Query('status', Query::TYPE_EQUAL, ['ready']),
            new Query('stream', Query::TYPE_EQUAL, [$stream]),
        ];

        $renditions = Authorization::skip(fn () => $dbForProject->find('videos_renditions', $queries, 12, 0, [], ['ASC']));

        $response->dynamic(new Document([
            'total'      => $dbForProject->count('videos_renditions', $queries, APP_LIMIT_COUNT),
            'renditions' => $renditions,
        ]), Response::MODEL_VIDEO_RENDITIONS_LIST);
    });


App::get('/v1/videos/:videoId/:stream/:profile/:fileName')
    ->alias('/v1/videos/:videoId/:stream/:profile/:fileName', [])
    ->desc('Get video playlist manifests')
    ->groups(['api', 'video'])
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'video')
    ->label('sdk.method', 'getPlaylist')
    ->label('sdk.description', '/docs/references/videos/get-playlist.md') // TODO: Create markdown
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    // TODO: Response model
    ->label('scope', 'files.read')
    ->param('videoId', null, new UID(), 'Video unique ID.')
    ->param('stream', '', new WhiteList(['hls', 'mpeg-dash']), 'stream protocol name')
    ->param('profile', '', new Text(18), 'folder name')
    ->param('fileName', '', new Text(128), 'playlist file name')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('videosDevice')
    ->inject('usage')
    ->inject('mode')
    ->inject('user')
    ->action(function (string $videoId, string $stream, string $profile, string $fileName, Response $response, Database $dbForProject, Device $videosDevice, Stats $usage, string $mode, Document $user) {

        $video = Authorization::skip(fn() => $dbForProject->findOne('videos', [
            new Query('_uid', Query::TYPE_EQUAL, [$videoId])
        ]));

        if (empty($video)) {
            throw new Exception('Video not found', 400, Exception::VIDEO_NOT_FOUND);
        }

        validateFilePermissions($dbForProject, $video['bucketId'], $video['fileId'], $mode, $user);

        $renditions = Authorization::skip(fn () => $dbForProject->find('videos_renditions', [
            new Query('videoId', Query::TYPE_EQUAL, [$video->getId()]),
            new Query('endedAt', Query::TYPE_GREATER, [0]),
            new Query('status', Query::TYPE_EQUAL, ['ready']),
            new Query('stream', Query::TYPE_EQUAL, [$stream]),
        ], 12, 0, [], ['ASC']));

        if (empty($renditions)) {
            throw new Exception('Renditions not found'); // TODO: Proper error code
        }

        $contentType['m3u8'] = 'application/x-mpegurl';
        $contentType['mpd']  = 'application/dash+xml';
        $contentType['ts']   = 'video/MP2T';
        $contentType['m4s']   = 'video/iso.segment';
        $contentType['vtt']   = 'text/vtt';
        $ext = pathinfo($fileName, PATHINFO_EXTENSION);
        $baseUrl = 'http://127.0.0.1/v1/videos/' . $videoId . '/' . $stream . '/' ;

        if ($profile === 'master') {
            if ($stream === 'hls') {
                $subtitles = Authorization::skip(fn () => $dbForProject->find('videos_subtitles', [new Query('videoId', Query::TYPE_EQUAL, [$video->getId()])], 12, 0, [], ['ASC']));
                $paramsSubtitles = [];
                foreach ($subtitles as $subtitle) {
                        $paramsSubtitles[] = [
                            'name' => $subtitle->getAttribute('name'),
                            'code' => $subtitle->getAttribute('code'),
                            'default' => !empty($subtitle->getAttribute('default')) ? 'YES' : 'NO',
                            'uri'  => $baseUrl  . $subtitle->getAttribute('path') . '/' . $videoId . '_subtitles_' . $subtitle->getAttribute('code') . '.m3u8',
                        ];
                }
                $paramsRenditions = [];
                foreach ($renditions as $rendition) {
                    $paramsRenditions[] = [
                        'bandwidth'  => ($rendition->getAttribute('videoBitrate') + $rendition->getAttribute('audioBitrate')),
                        'resolution' => $rendition->getAttribute('width') . 'X' . $rendition->getAttribute('height'),
                        'name' => $rendition->getAttribute('name'),
                        'uri'  => $baseUrl . $rendition->getAttribute('name') . '/' . $rendition->getAttribute('videoId') . '_' . $rendition->getAttribute('height') . 'p.m3u8',
                        'subs' => !empty($paramsSubtitles) ? ' SUBTITLES="subs"' : '',
                        ];
                }

                $template = new View(__DIR__ . '/../../views/videos/hls.phtml');
                $template->setParam('paramsSubtitles', $paramsSubtitles);
                $template->setParam('paramsRenditions', $paramsRenditions);
                $output = $template->render(false);
            } else {
                $adaptations = [];
                foreach ($renditions as $rendition) {
                    $metadata = $rendition->getAttribute('metadata');
                    foreach ($metadata['mpeg-dash']['Period']['AdaptationSet'] as $set) {
                        $adaption = $set['@attributes'];
                        $adaption['baseUrl'] = $baseUrl . $rendition->getAttribute('name') . '/';
                        $adaption['representation'] = $set['Representation']['@attributes'];
                        $adaption['representation']['SegmentTemplate'] = $set['Representation']['SegmentTemplate']['@attributes'];
                        $adaption['representation']['segmentTemplate']['segmentTimeline'] = $set['Representation']['SegmentTemplate']['SegmentTimeline'];
                        $adaptations[] = $adaption;
                    }
                }

                $template = new View(__DIR__ . '/../../views/videos/dash.phtml');
                $template->setParam('params', $adaptations);
                $response->setContentType($contentType[$ext]);
                $output = $template->render(false);
            }
        } else {
            $output = $videosDevice->read($videosDevice->getRoot() . '/' . $videoId . '/' . $profile . '/' . $fileName);
        }

        $response->setContentType($contentType[$ext])->send($output);
    });

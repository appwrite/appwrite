<?php

use Appwrite\Auth\Auth;
use Appwrite\ClamAV\Network;
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
use Streaming\Representation;

/**
 * Validate file Permissions
 *
 * @param Database $dbForProject
 * @param string $bucketId
 * @param string $fileId
 * @param array|null $read
 * @param array|null $write
 * @param string $mode
 * @return Document $file
 * @throws Exception
 */
function validateFilePermissions(Database $dbForProject, string $bucketId, string $fileId, string $mode, ?array $read, ?array $write): Document
{
    /** @var Utopia\Database\Document $project */
    /** @var Utopia\Database\Document $user */

    $bucket = Authorization::skip(fn () => $dbForProject->getDocument('buckets', $bucketId));

    if (
        $bucket->isEmpty()
        || (!$bucket->getAttribute('enabled') && $mode !== APP_MODE_ADMIN)
    ) {
        throw new Exception('Bucket not found', 404, Exception::STORAGE_BUCKET_NOT_FOUND);
    }

    // Check bucket permissions when enforced
    $permissionBucket = $bucket->getAttribute('permission') === 'bucket';
    if ($permissionBucket) {
        $validator = new Authorization('write');
        if (!$validator->isValid($bucket->getWrite())) {
            throw new Exception('Unauthorized file permissions', 401, Exception::USER_UNAUTHORIZED);
        }
    }

    $read = (is_null($read) && !$user->isEmpty()) ? ['user:' . $user->getId()] : $read ?? []; // By default set read permissions for user
    $write = (is_null($write) && !$user->isEmpty()) ? ['user:' . $user->getId()] : $write ?? [];

    // Users can only add their roles to files, API keys and Admin users can add any
    $roles = Authorization::getRoles();

    if (!Auth::isAppUser($roles) && !Auth::isPrivilegedUser($roles)) {
        foreach ($read as $role) {
            if (!Authorization::isRole($role)) {
                throw new Exception('Read permissions must be one of: (' . \implode(', ', $roles) . ')', 400, Exception::USER_UNAUTHORIZED);
            }
        }
        foreach ($write as $role) {
            if (!Authorization::isRole($role)) {
                throw new Exception('Write permissions must be one of: (' . \implode(', ', $roles) . ')', 400, Exception::USER_UNAUTHORIZED);
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

App::get('/v1/video/profiles')
    ->alias('/v1/video/video/profiles', [])
    ->desc('Get all video profiles')
    ->groups(['api', 'storage'])
    ->label('scope', 'files.read')
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (Response $response, Database $dbForProject) {

        $profiles = Authorization::skip(fn () => $dbForProject->find('video_profiles', [], 12, 0, [], ['ASC']));

        if (empty($profiles)) {
            throw new Exception('Video profiles where not found', 404, Exception::PROFILES_NOT_FOUND);
        }

        $response->dynamic(new Document([
            'total' => $dbForProject->count('video_profiles', [], APP_LIMIT_COUNT),
            'profiles' => $profiles,
        ]), Response::MODEL_VIDEO_PROFILE_LIST);
    });


App::post('/v1/video/buckets/:bucketId/files/:fileId')
    ->alias('/v1/video/files', ['bucketId' => 'default'])
    ->desc('Create video')
    ->groups(['api', 'storage'])
    ->label('scope', 'files.write')
//    ->label('event', 'buckets.[bucketId].files.[fileId].create')
    ->param('bucketId', null, new UID(), 'Storage bucket unique ID. You can create a new storage bucket using the Storage service [server integration](/docs/server/storage#createBucket).')
    ->param('fileId', '', new CustomId(), 'File ID. Choose your own unique ID or pass the string "unique()" to auto generate it. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('read', null, new Permissions(), 'An array of strings with read permissions. By default only the current user is granted with read permissions. [learn more about permissions](https://appwrite.io/docs/permissions) and get a full list of available permissions.', true)
    ->param('write', null, new Permissions(), 'An array of strings with write permissions. By default only the current user is granted with write permissions. [learn more about permissions](https://appwrite.io/docs/permissions) and get a full list of available permissions.', true)
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
    ->action(action: function (string $bucketId, string $fileId, ?array $read, ?array $write, Request $request, Response $response, Database $dbForProject, $project, Document $user, Audit $audits, Stats $usage, Event $events, string $mode, Device $deviceFiles, Device $deviceLocal) {
        /** @var Utopia\Database\Document $project */
        /** @var Utopia\Database\Document $user */

        $file = validateFilePermissions($dbForProject, $bucketId, $fileId, $mode, $read, $write);

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


App::post('/v1/video/:videoId/rendition/:profileId')
    ->alias('/v1/video/:videoId/rendition/:profileId', [])
    ->desc('Start transcoding video rendition')
    ->groups(['api', 'storage'])
    ->label('scope', 'files.write')
//    ->label('event', 'buckets.[bucketId].files.[fileId].create')
    ->param('videoId', null, new UID(), 'Video unique ID.')
    ->param('profileId', '', new CustomId(), 'Profile unique ID.')
    ->param('read', null, new Permissions(), 'An array of strings with read permissions. By default only the current user is granted with read permissions. [learn more about permissions](https://appwrite.io/docs/permissions) and get a full list of available permissions.', true)
    ->param('write', null, new Permissions(), 'An array of strings with write permissions. By default only the current user is granted with write permissions. [learn more about permissions](https://appwrite.io/docs/permissions) and get a full list of available permissions.', true)
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
    ->action(action: function (string $videoId, string $profileId, ?array $read, ?array $write, Request $request, Response $response, Database $dbForProject, $project, Document $user, Audit $audits, Stats $usage, Event $events, string $mode, Device $deviceFiles, Device $deviceLocal) {
        /** @var Utopia\Database\Document $project */
        /** @var Utopia\Database\Document $user */

        $video = Authorization::skip(fn() => $dbForProject->findOne('videos', [new Query('_uid', Query::TYPE_EQUAL, [$videoId])]));

        if ($video->isEmpty()) {
            throw new Exception('Video not found', 400, Exception::VIDEO_NOT_FOUND);
        }

        validateFilePermissions($dbForProject, $video['bucketId'], $video['fileId'], $mode, $read, $write);

        $profile = Authorization::skip(fn() => $dbForProject->findOne('video_profiles', [new Query('_uid', Query::TYPE_EQUAL, [$profileId])]));

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

        $response->json(['result' => 'ok']);
    });


App::get('/v1/video/:videoId/:stream/renditions')
    ->alias('/v1/video/:videoId/renditions', [])
    ->desc('Get File renditions')
    ->groups(['api', 'storage'])
    ->label('scope', 'files.read')
    ->param('videoId', null, new UID(), 'Video unique ID.')
    ->param('stream', '', new WhiteList(['hls', 'mpeg-dash']), 'stream protocol name')
    ->param('read', null, new Permissions(), 'An array of strings with read permissions. By default only the current user is granted with read permissions. [learn more about permissions](https://appwrite.io/docs/permissions) and get a full list of available permissions.', true)
    ->param('write', null, new Permissions(), 'An array of strings with write permissions. By default only the current user is granted with write permissions. [learn more about permissions](https://appwrite.io/docs/permissions) and get a full list of available permissions.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('usage')
    ->inject('mode')
    ->action(function (string $videoId, string $stream, ?array $read, ?array $write, Response $response, Database $dbForProject, Stats $usage, string $mode) {

        /** @var Utopia\Database\Document $project */
        /** @var Utopia\Database\Document $user */

        $video = Authorization::skip(fn() => $dbForProject->findOne('videos', [new Query('_uid', Query::TYPE_EQUAL, [$videoId])]));

        if ($video->isEmpty()) {
            throw new Exception('Video not found', 400, Exception::VIDEO_NOT_FOUND);
        }

        $file = validateFilePermissions($dbForProject, $video['bucketId'], $video['fileId'], $mode, $read, $write);

        $queries = [
            new Query('videoId', Query::TYPE_EQUAL, [$video->getId()]),
            new Query('endedAt', Query::TYPE_GREATER, [0]),
            new Query('status', Query::TYPE_EQUAL, ['ready']),
            new Query('stream', Query::TYPE_EQUAL, [$stream]),
        ];

        $renditions = Authorization::skip(fn () => $dbForProject->find('video_renditions', $queries, 12, 0, [], ['ASC']));

        $response->dynamic(new Document([
            'total'      => $dbForProject->count('video_renditions', $queries, APP_LIMIT_COUNT),
            'renditions' => $renditions,
        ]), Response::MODEL_VIDEO_RENDITIONS_LIST);
    });



App::get('/v1/video/:videoId/:stream/:profile/:fileName')
    ->alias('/v1/video/:videoId/:stream/:profile/:fileName', [])
    ->desc('Get video  playlist manifests')
    ->groups(['api', 'storage'])
    ->label('scope', 'files.read')
    ->param('videoId', null, new UID(), 'Video unique ID.')
    ->param('stream', '', new WhiteList(['hls', 'mpeg-dash']), 'stream protocol name')
    ->param('profile', '', new Text(18), 'folder name')
    ->param('fileName', '', new Text(128), 'playlist file name')
    ->param('read', null, new Permissions(), 'An array of strings with read permissions. By default only the current user is granted with read permissions. [learn more about permissions](https://appwrite.io/docs/permissions) and get a full list of available permissions.', true)
    ->param('write', null, new Permissions(), 'An array of strings with write permissions. By default only the current user is granted with write permissions. [learn more about permissions](https://appwrite.io/docs/permissions) and get a full list of available permissions.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('videosDevice')
    ->inject('usage')
    ->inject('mode')
    ->action(function (string $videoId, string $stream, string $profile, string $fileName, ?array $read, ?array $write, Response $response, Database $dbForProject, Device $videosDevice, Stats $usage, string $mode) {

        /** @var Utopia\Database\Document $project */
        /** @var Utopia\Database\Document $user */

        $video = Authorization::skip(fn() => $dbForProject->findOne('videos', [
            new Query('_uid', Query::TYPE_EQUAL, [$videoId])
        ]));

        if ($video->isEmpty()) {
            throw new Exception('Video not found', 400, Exception::VIDEO_NOT_FOUND);
        }

        validateFilePermissions($dbForProject, $video['bucketId'], $video['fileId'], $mode, $read, $write);

        $renditions = Authorization::skip(fn () => $dbForProject->find('video_renditions', [
            new Query('videoId', Query::TYPE_EQUAL, [$video->getId()]),
            new Query('endedAt', Query::TYPE_GREATER, [0]),
            new Query('status', Query::TYPE_EQUAL, ['ready']),
            new Query('stream', Query::TYPE_EQUAL, [$stream]),
        ], 12, 0, [], ['ASC']));

        if (empty($renditions)) {
            throw new Exception('Renditions not found');
        }

        $ct['m3u8'] = 'application/x-mpegurl';
        $ct['mpd']  = 'application/dash+xml';
        $ct['ts']   = 'video/MP2T';
        $ct['m4s']   = 'video/iso.segment';
        $ext = pathinfo($fileName, PATHINFO_EXTENSION);
        $baseUrl = 'http://127.0.0.1/v1/video/' . $videoId . '/' . $stream . '/' ;


        if ($profile === 'master') {
            if ($stream === 'hls') {
                foreach ($renditions as $rendition) {
                    $t['bandwidth'] = $rendition->getAttribute('videoBitrate') + $rendition->getAttribute('audioBitrate');
                    $t['resolution'] = $rendition->getAttribute('width') . 'X' . $rendition->getAttribute('height');
                    $t['name'] = $rendition->getAttribute('name');
                    $t['path'] = $baseUrl . $rendition->getAttribute('name') . '/' . $rendition->getAttribute('videoId') . '_' . $rendition->getAttribute('name') . '.m3u8';
                    $params[] = $t;
                }

                $template = new View(__DIR__ . '/../../views/video/hls.phtml');
                $template->setParam('params', $params);
                $output = $template->render();
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

                $template = new View(__DIR__ . '/../../views/video/dash.phtml');
                $template->setParam('params', $adaptations);
                $response->setContentType($ct[$ext]);
                $output = $template->render();
            }
        } else {
            $output = $videosDevice->read($videosDevice->getRoot() . '/' . $videoId . '/' . $profile . '/' . $fileName);
        }

        $response->setContentType($ct[$ext])
            ->send($output);
    });

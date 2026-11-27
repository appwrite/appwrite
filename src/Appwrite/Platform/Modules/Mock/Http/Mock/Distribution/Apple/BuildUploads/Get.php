<?php

namespace Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\BuildUploads;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\Base;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Cache\Cache;
use Utopia\System\System;
use Utopia\Validator\Assoc;
use Utopia\Validator\Text;

class Get extends Base
{
    private const array INCLUDES = ['build', 'assetFile', 'assetDescriptionFile', 'assetSpiFile'];
    private const array ALLOWED_QUERY = [
        'fields' => ['buildUploads', 'builds', 'buildUploadFiles'],
    ];

    public static function getName(): string
    {
        return 'getDistributionAppleBuildUpload';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath(self::PREFIX . '/v1/buildUploads/:buildUploadId')
            ->desc('Get App Store Connect build upload')
            ->groups(['mock'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('mock', true)
            ->param('buildUploadId', '', new Text(64), 'Build upload ID.')
            ->param('include', '', new Text(256, 0), 'Comma-separated relationships to include: `build`, `assetFile`, `assetDescriptionFile` or `assetSpiFile`.', true)
            ->param('fields', [], new Assoc(), 'Sparse fieldsets, as `fields[buildUploads]`, `fields[builds]` and `fields[buildUploadFiles]`.', true)
            ->inject('request')
            ->inject('response')
            ->inject('cache')
            ->callback($this->action(...));
    }

    /**
     * @param array<string, mixed> $fields
     */
    public function action(string $buildUploadId, string $include, array $fields, Request $request, Response $response, Cache $cache): void
    {
        $isDevelopment = System::getEnv('_APP_ENV', 'production') === 'development';

        if (!$isDevelopment) {
            throw new Exception(Exception::GENERAL_NOT_IMPLEMENTED);
        }

        $key = $this->authorize($request, $response, $cache);

        if ($key === null) {
            return;
        }

        $fields = \array_intersect_key($fields, \array_flip(self::ALLOWED_QUERY['fields']));
        $includes = $include === '' ? [] : \explode(',', $include);

        foreach ($includes as $name) {
            if (!\in_array($name, self::INCLUDES, true)) {
                $this->error(
                    $response,
                    Response::STATUS_CODE_BAD_REQUEST,
                    self::CODE_PARAMETER_INVALID,
                    'A parameter has an invalid value',
                    "'{$name}' is not a valid relationship name",
                    ['parameter' => 'include'],
                );
                return;
            }
        }

        $upload = $cache->load(self::CACHE_UPLOAD_BUILDS . $buildUploadId, self::TTL);

        if (!\is_array($upload) || !$this->owns($cache, $key['issuerId'], $upload['appId'])) {
            $this->notFound($response, 'buildUploads', $buildUploadId);
            return;
        }

        $resource = $this->buildUploadResource($upload);
        $resource['attributes'] = $this->select($resource['attributes'], $fields, 'buildUploads');

        $body = [
            'data' => $resource,
            'links' => ['self' => $this->link($request)],
        ];

        $included = [];
        $build = \in_array('build', $includes, true) && $upload['buildId'] !== null ? $this->build($cache, $upload['buildId']) : null;

        if ($build !== null) {
            $included[] = $this->exposeBuild($build, $fields);
        }

        $file = \in_array('assetFile', $includes, true) && $upload['fileId'] !== null ? $cache->load(self::CACHE_UPLOAD_FILES . $upload['fileId'], self::TTL) : null;

        if (\is_array($file)) {
            $fileResource = $this->buildUploadFileResource($file);
            $fileResource['attributes'] = $this->select($fileResource['attributes'], $fields, 'buildUploadFiles');
            $included[] = $fileResource;
        }

        if ($included !== []) {
            $body['included'] = $included;
        }

        $this->respond($response, $body);
    }
}

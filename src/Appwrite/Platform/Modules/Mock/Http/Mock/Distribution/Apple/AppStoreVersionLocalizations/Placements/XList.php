<?php

namespace Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\AppStoreVersionLocalizations\Placements;

use Appwrite\Extend\Exception;
use Appwrite\Platform\Action;
use Appwrite\Platform\Modules\Mock\Http\Mock\Distribution\Apple\Base;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Cache\Cache;
use Utopia\System\System;
use Utopia\Validator\Range;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

class XList extends Base
{
    private const array SORTS = ['', 'placementGroupPosition'];

    public static function getName(): string
    {
        return 'listDistributionAppleAppStoreVersionLocalizationPlacements';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath(self::PREFIX . '/v1/appStoreVersionLocalizations/:localizationId/placements')
            ->desc('List App Store Connect asset library placements of a version localization')
            ->groups(['mock'])
            ->label('scope', 'public')
            ->label('docs', false)
            ->label('mock', true)
            ->param('localizationId', '', new Text(64), 'Version localization ID.')
            ->param('include', '', new Text(256, 0), 'Related resources to include, `image`.', true)
            ->param('sort', '', new WhiteList(self::SORTS, true), 'Sort order, `placementGroupPosition`.', true)
            ->param('limit', 50, new Range(1, 200), 'Maximum number of placements to return.', true)
            ->inject('request')
            ->inject('response')
            ->inject('cache')
            ->callback($this->action(...));
    }

    public function action(string $localizationId, string $include, string $sort, int $limit, Request $request, Response $response, Cache $cache): void
    {
        $isDevelopment = System::getEnv('_APP_ENV', 'production') === 'development';

        if (!$isDevelopment) {
            throw new Exception(Exception::GENERAL_NOT_IMPLEMENTED);
        }

        $key = $this->authorize($request, $response, $cache);

        if ($key === null) {
            return;
        }

        $version = $this->versionOfLocalization($cache, $localizationId);

        if ($version === null || !$this->ownsVersion($cache, $key['issuerId'], $version)) {
            $this->notFound($response, self::VERSION_TYPE_LOCALIZATION, $localizationId);
            return;
        }

        $placements = $this->imagePlacements($cache, $localizationId);
        $page = \array_slice($placements, 0, $limit);
        $linkage = \in_array('image', $this->versionInclude($include), true);
        $body = [
            'data' => \array_map(fn (array $placement): array => $this->imagePlacementResource($placement, $linkage), $page),
            'links' => ['self' => $this->link($request)],
            'meta' => ['paging' => ['total' => \count($placements), 'limit' => $limit]],
        ];

        if ($linkage) {
            $included = [];

            foreach ($page as $placement) {
                $image = $this->image($cache, $placement[self::INTERNAL]['imageId']);

                if ($image !== null) {
                    $included[$image['id']] = $this->imageResource($image);
                }
            }

            $body['included'] = \array_values($included);
        }

        $this->respond($response, $body);
    }
}

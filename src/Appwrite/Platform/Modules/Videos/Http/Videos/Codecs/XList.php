<?php

namespace Appwrite\Platform\Modules\Videos\Http\Videos\Codecs;

use Appwrite\Platform\Modules\Videos\Base;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Config\Config;
use Utopia\Database\Document;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;

class XList extends Base
{
    use HTTP;

    public static function getName()
    {
        return 'listCodecs';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/videos/codecs')
            ->desc('List video codecs')
            ->groups(['api', 'videos'])
            ->label('scope', 'videos.read')
            ->label('resourceType', RESOURCE_TYPE_VIDEOS)
            ->label('sdk', new Method(
                namespace: 'videos',
                group: 'codecs',
                name: 'listCodecs',
                description: '/docs/references/videos/list-codecs.md',
                auth: [AuthType::ADMIN, AuthType::SESSION, AuthType::KEY, AuthType::JWT],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_VIDEO_CODEC_LIST,
                    )
                ]
            ))
            ->inject('response')
            ->callback($this->action(...));
    }

    public function action(Response $response): void
    {
        $codecs = [];

        foreach (Config::getParam('videos-codecs', []) as $id => $codec) {
            if (!\is_array($codec) || ($codec['enabled'] ?? false) !== true) {
                continue;
            }

            $outputs = $codec['outputs'] ?? [];
            if (!\is_array($outputs)) {
                $outputs = [];
            }

            $codecs[] = new Document([
                '$id' => (string) $id,
                'name' => (string) ($codec['name'] ?? $id),
                'outputs' => \array_values(\array_map('strval', $outputs)),
            ]);
        }

        $response->dynamic(new Document([
            'codecs' => $codecs,
            'total' => \count($codecs),
        ]), Response::MODEL_VIDEO_CODEC_LIST);
    }
}

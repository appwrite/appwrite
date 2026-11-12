<?php

namespace Appwrite\Platform\Modules\Console\Http\Versions;

use Appwrite\Utopia\Response;
use Utopia\Config\Config;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;

class Get extends Action
{
    use HTTP;

    public static function getName(): string
    {
        return 'getVersions';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/versions')
            ->desc('Get Version')
            ->groups(['home', 'web'])
            ->label('scope', 'public')
            ->inject('response')
            ->callback($this->action(...));
    }

    public function action(Response $response): void
    {
        $platforms = Config::getParam('sdks');

        $versions = [
            'server' => APP_VERSION_STABLE,
        ];

        foreach ($platforms as $platform) {
            $languages = $platform['sdks'] ?? [];

            foreach ($languages as $key => $language) {
                if (isset($language['dev']) && $language['dev']) {
                    continue;
                }

                if (isset($language['enabled']) && !$language['enabled']) {
                    continue;
                }

                $platformKey = $platform['key'] ?? '';
                $languageKey = $language['key'] ?? '';
                $version = $language['version'] ?? '';
                $versions[$platformKey . '-' . $languageKey] = $version;
            }
        }

        $response->json($versions);
    }
}

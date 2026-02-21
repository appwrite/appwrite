<?php

namespace Appwrite\Platform\Modules\Badge\Http\Sites;

use Appwrite\Template\Template;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Validator\Authorization;
use Utopia\Platform\Action;
use Utopia\Validator\Text;

class Get extends Action
{
    private const LABEL_WIDTH = 77;
    private const COLOR_BRIGHTGREEN = '#4c1';
    private const COLOR_GREEN = '#97ca00';
    private const COLOR_YELLOW = '#dfb317';
    private const COLOR_YELLOWGREEN = '#a4a61d';
    private const COLOR_ORANGE = '#fe7d37';
    private const COLOR_RED = '#e05d44';
    private const COLOR_BLUE = '#007ec6';
    private const COLOR_LIGHTGREY = '#9f9f9f';
    private const COLOR_GREY = '#555';
    private const COLOR_BY_NAME = [
        'brightgreen' => self::COLOR_BRIGHTGREEN,
        'green' => self::COLOR_GREEN,
        'yellow' => self::COLOR_YELLOW,
        'yellowgreen' => self::COLOR_YELLOWGREEN,
        'orange' => self::COLOR_ORANGE,
        'red' => self::COLOR_RED,
        'blue' => self::COLOR_BLUE,
        'lightgrey' => self::COLOR_LIGHTGREY,
        'grey' => self::COLOR_GREY,
    ];

    public static function getName(): string
    {
        return 'getSiteBadge';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/badge/sites/:siteId')
            ->desc('Get site deployment status badge')
            ->groups(['api', 'badge'])
            ->label('scope', 'public')
            ->label('sdk.auth', [])
            ->label('sdk.namespace', 'badge')
            ->label('sdk.method', 'getSite')
            ->param('siteId', '', new Text(36), 'Site ID')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(string $siteId, Response $response, Database $dbForProject, Authorization $authorization): void
    {
        $site = $authorization->skip(fn () => $dbForProject->getDocument('sites', $siteId));

        switch (true) {
            case $site->isEmpty():
                $message = 'not found';
                $color = 'lightgrey';
                break;
            case !$site->getAttribute('deploymentBadge', true):
                $message = 'disabled';
                $color = 'lightgrey';
                break;
            default:
                $status = $site->getAttribute('latestDeploymentStatus', 'unknown');

                switch ($status) {
                    case 'ready':
                        $message = 'ready';
                        $color = 'brightgreen';
                        break;
                    case 'building':
                    case 'processing':
                        $message = 'building';
                        $color = 'yellow';
                        break;
                    case 'waiting':
                        $message = 'waiting';
                        $color = 'blue';
                        break;
                    case 'failed':
                        $message = 'failed';
                        $color = 'red';
                        break;
                    case '':
                        $message = 'no deployment';
                        $color = 'lightgrey';
                        break;
                    default:
                        $message = $status;
                        $color = 'lightgrey';
                        break;
                }
                break;
        }

        $colorCode = self::COLOR_BY_NAME[$color] ?? self::COLOR_LIGHTGREY;
        $totalWidth = self::LABEL_WIDTH + (\strlen($message) * 6) + 10;

        $templatePath = \dirname(__DIR__, 7) . '/app/config/locale/templates/badge.svg.tpl';
        $template = Template::fromFile($templatePath);
        $template
            ->setParam('{{message}}', $message)
            ->setParam('{{colorCode}}', $colorCode)
            ->setParam('{{totalWidth}}', (string) $totalWidth);

        $svg = $template->render();

        $response
            ->setContentType('image/svg+xml')
            ->addHeader('Cache-Control', 'no-cache, no-store, must-revalidate')
            ->addHeader('Pragma', 'no-cache')
            ->addHeader('Expires', '0')
            ->send($svg);
    }
}

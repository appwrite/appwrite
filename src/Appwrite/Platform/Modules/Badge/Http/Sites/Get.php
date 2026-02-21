<?php

namespace Appwrite\Platform\Modules\Badge\Http\Sites;

use Appwrite\Platform\Modules\Badge\Http\Action;
use Appwrite\Template\Template;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\NotFound as NotFoundException;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;

class Get extends Action
{
    private const LABEL = 'appwrite sites';
    private const LABEL_WIDTH = 110;

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
            ->param('siteId', '', new UID(), 'Site ID')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(string $siteId, Response $response, Database $dbForProject, Authorization $authorization): void
    {
        try {
            $site = $authorization->skip(fn () => $dbForProject->getDocument('sites', $siteId));
        } catch (NotFoundException) {
            $site = new Document();
        }
        $label = self::LABEL;

        switch (true) {
            case $site->isEmpty():
                $message = 'not found';
                $color = 'lightgrey';
                break;
            case !$site->getAttribute('deploymentBadge', false):
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
        $messageWidth = self::MESSAGE_WIDTHS[$message] ?? ((\strlen($message) * self::FALLBACK_CHAR_WIDTH) + (self::TEXT_PADDING * 2));
        $totalWidth = self::LABEL_WIDTH + $messageWidth;
        $messageTextX = (self::LABEL_WIDTH + self::TEXT_PADDING) * 10;

        $templatePath = \dirname(__DIR__, 7) . '/app/config/locale/templates/badge.svg.tpl';
        $template = Template::fromFile($templatePath);
        $template
            ->setParam('{{label}}', $label)
            ->setParam('{{message}}', $message)
            ->setParam('{{colorCode}}', $colorCode)
            ->setParam('{{labelWidth}}', (string)self::LABEL_WIDTH)
            ->setParam('{{labelFontSize}}', (string)self::LABEL_FONT_SIZE)
            ->setParam('{{labelTextX}}', (string)self::LABEL_TEXT_X)
            ->setParam('{{messageTextX}}', (string)$messageTextX)
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

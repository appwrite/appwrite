<?php

namespace Appwrite\Platform\Modules\Badge\Http\Sites;

use Appwrite\Template\Template;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Validator\Authorization;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Text;

class Get extends Action
{
    use HTTP;

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

        if ($site->isEmpty()) {
            $status = 'not found';
            $color = 'lightgrey';
        } elseif (!$site->getAttribute('deploymentBadge', true)) {
            $status = 'disabled';
            $color = 'lightgrey';
        } else {
            $status = $site->getAttribute('latestDeploymentStatus', 'unknown');

            $color = match ($status) {
                'ready' => 'brightgreen',
                'building', 'processing' => 'yellow',
                'waiting' => 'blue',
                'failed' => 'red',
                default => 'lightgrey',
            };

            $status = match ($status) {
                'processing' => 'building',
                '' => 'no deployment',
                default => $status,
            };
        }

        $colors = [
            'brightgreen' => '#4c1',
            'green' => '#97ca00',
            'yellow' => '#dfb317',
            'yellowgreen' => '#a4a61d',
            'orange' => '#fe7d37',
            'red' => '#e05d44',
            'blue' => '#007ec6',
            'lightgrey' => '#9f9f9f',
            'grey' => '#555',
        ];

        $colorCode = $colors[$color] ?? $colors['lightgrey'];
        $labelColor = '#FD366E';

        $label = 'appwrite';
        $logoWidth = 14;
        $logoPadding = 5;
        $textWidth = \strlen($label) * 6 + 10;
        $labelWidth = $logoWidth + $logoPadding + $textWidth;
        $messageWidth = \strlen($status) * 6 + 10;
        $totalWidth = $labelWidth + $messageWidth;

        $textAreaStart = $logoPadding + $logoWidth + $logoPadding;
        $textAreaWidth = $labelWidth - $textAreaStart;
        $labelTextX = ($textAreaStart + $textAreaWidth / 2) * 10 - 20;
        $messageX = ($labelWidth + $messageWidth / 2) * 10;
        $labelTextLength = \strlen($label) * 60;
        $messageTextLength = ($messageWidth - 10) * 10;

        $templatePath = \dirname(__DIR__, 7) . '/app/config/locale/templates/badge.svg.tpl';
        $template = Template::fromFile($templatePath);
        $template
            ->setParam('{{label}}', $label)
            ->setParam('{{message}}', $status)
            ->setParam('{{labelColor}}', $labelColor)
            ->setParam('{{colorCode}}', $colorCode)
            ->setParam('{{totalWidth}}', (string) $totalWidth)
            ->setParam('{{labelWidth}}', (string) $labelWidth)
            ->setParam('{{messageWidth}}', (string) $messageWidth)
            ->setParam('{{labelTextX}}', (string) $labelTextX)
            ->setParam('{{messageX}}', (string) $messageX)
            ->setParam('{{labelTextLength}}', (string) $labelTextLength)
            ->setParam('{{messageTextLength}}', (string) $messageTextLength);

        $svg = $template->render();

        $response
            ->setContentType('image/svg+xml')
            ->addHeader('Cache-Control', 'no-cache, no-store, must-revalidate')
            ->addHeader('Pragma', 'no-cache')
            ->addHeader('Expires', '0')
            ->send($svg);
    }
}

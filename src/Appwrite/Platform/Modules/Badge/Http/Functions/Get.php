<?php

namespace Appwrite\Platform\Modules\Badge\Http\Functions;

use Appwrite\Platform\Modules\Badge\Http\Action;
use Appwrite\Template\Template;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Validator\Authorization;
use Utopia\Validator\Text;

class Get extends Action
{
    public static function getName(): string
    {
        return 'getFunctionBadge';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/badge/functions/:functionId')
            ->desc('Get function deployment status badge')
            ->groups(['api', 'badge'])
            ->label('scope', 'public')
            ->label('sdk.auth', [])
            ->label('sdk.namespace', 'badge')
            ->label('sdk.method', 'getFunction')
            ->param('functionId', '', new Text(36), 'Function ID')
            ->inject('response')
            ->inject('dbForProject')
            ->inject('authorization')
            ->callback($this->action(...));
    }

    public function action(string $functionId, Response $response, Database $dbForProject, Authorization $authorization): void
    {
        $function = $authorization->skip(fn () => $dbForProject->getDocument('functions', $functionId));

        switch (true) {
            case $function->isEmpty():
                $message = 'not found';
                $color = 'lightgrey';
                break;
            case !$function->getAttribute('deploymentBadge', true):
                $message = 'disabled';
                $color = 'lightgrey';
                break;
            default:
                $status = $function->getAttribute('latestDeploymentStatus', 'unknown');

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

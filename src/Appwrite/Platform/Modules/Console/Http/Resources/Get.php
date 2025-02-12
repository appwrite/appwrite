<?php

namespace Appwrite\Platform\Modules\Console\Http\Resources;

use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Domain;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

class Get extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'getResources';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('v1/console/resources')
            ->desc('Check resource ID availability')
            ->groups(['api', 'projects'])
            ->label('scope', 'rules.read')
            ->label('sdk', new Method(
                namespace: 'console',
                name: 'getResource',
                description: <<<EOT
                Check if a resource ID is available.
                EOT,
                auth: [AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_NOCONTENT,
                        model: Response::MODEL_NONE,
                    )
                ],
                contentType: ContentType::NONE,
            ))
            ->label('abuse-limit', 10)
            ->label('abuse-key', 'userId:{userId}, url:{url}')
            ->label('abuse-time', 60)
            ->param('value', '', new Text(256), 'Resource value.')
            ->param('type', '', new WhiteList(['rules']), 'Resource type.')
            ->inject('response')
            ->inject('dbForPlatform')
            ->callback([$this, 'action']);
    }

    public function action(string $value, string $type, Response $response, Database $dbForPlatform)
    {
        if ($type == 'rules') {
            $validator = new Domain($value);

            if (!$validator->isValid($value)) {
                throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, $validator->getDescription());
            }

            $document = Authorization::skip(fn () => $dbForPlatform->findOne('rules', [
                Query::equal('domain', [$value]),
            ]));

            if (!$document->isEmpty()) {
                throw new Exception(Exception::RESOURCE_ALREADY_EXISTS);
            }

            $response->noContent();
        }

        throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Invalid type');
    }
}

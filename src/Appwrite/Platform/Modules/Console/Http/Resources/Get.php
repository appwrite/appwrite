<?php

namespace Appwrite\Platform\Modules\Console\Http\Resources;

use Appwrite\Domain\Validator\AppwriteDomain;
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
        return 'getResource';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/v1/console/resources')
            ->desc('Check resource ID availability')
            ->groups(['api', 'projects'])
            ->label('scope', 'rules.read')
            ->label('sdk', new Method(
                namespace: 'console',
                group: null,
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
            ->label('abuse-limit', 120)
            ->label('abuse-key', 'userId:{userId}, url:{url}')
            ->label('abuse-time', 60)
            ->param('value', '', new Text(256), 'Resource value.')
            ->param('type', '', new WhiteList(['rules']), 'Resource type.')
            ->inject('response')
            ->inject('dbForPlatform')
            ->callback($this->action(...));
    }

    public function action(
        string $value,
        string $type,
        Response $response,
        Database $dbForPlatform
    ) {
        if ($type === 'rules') {
            $domainValidator = new Domain($value);
            $appwriteDomainValidator = new AppwriteDomain();

            if (!$domainValidator->isValid($value) && !$appwriteDomainValidator->isValid($value)) {
                throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Value must be a valid domain name or a valid Appwrite subdomain.');
            }

            $document = Authorization::skip(fn () => $dbForPlatform->findOne('rules', [
                Query::equal('domain', [$value]),
            ]));

            if (!$document->isEmpty()) {
                throw new Exception(Exception::RESOURCE_ALREADY_EXISTS);
            }

            $response->noContent();
        }

        // Only occurs if type is added into whitelist, but not supported in action
        throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Invalid type');
    }
}

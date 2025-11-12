<?php

namespace Appwrite\Platform\Modules\Tokens\Http\Tokens;

use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Validator\Datetime as DatetimeValidator;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Nullable;

class Update extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'updateToken';
    }

    public function __construct()
    {
        $this->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
        ->setHttpPath('/v1/tokens/:tokenId')
        ->desc('Update token')
        ->groups(['api', 'tokens'])
        ->label('scope', 'tokens.write')
        ->label('event', 'tokens.[tokenId].update')
        ->label('audits.event', 'tokens.update')
        ->label('audits.resource', 'tokens/{response.$id}')
        ->label('usage.metric', 'tokens.{scope}.requests.update')
        ->label('usage.params', ['tokenId:{request.tokenId}'])
        ->label('abuse-key', 'ip:{ip},method:{method},url:{url},userId:{userId}')
        ->label('abuse-limit', APP_LIMIT_WRITE_RATE_DEFAULT)
        ->label('abuse-time', APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT)
        ->label('sdk', new Method(
            namespace: 'tokens',
            group: 'tokens',
            name: 'update',
            description: <<<EOT
            Update a token by its unique ID. Use this endpoint to update a token's expiry date.
            EOT,
            auth: [AuthType::ADMIN, AuthType::KEY],
            responses: [
                new SDKResponse(
                    code: Response::STATUS_CODE_OK,
                    model: Response::MODEL_RESOURCE_TOKEN,
                )
            ],
            contentType: ContentType::JSON
        ))
        ->param('tokenId', '', new UID(), 'Token unique ID.')
        ->param('expire', null, new Nullable(new DatetimeValidator(requireDateInFuture: true)), 'File token expiry date', true)
        ->inject('response')
        ->inject('dbForProject')
        ->inject('queueForEvents')
        ->callback($this->action(...));
    }

    public function action(string $tokenId, ?string $expire, Response $response, Database $dbForProject, Event $queueForEvents)
    {
        $token = $dbForProject->getDocument('resourceTokens', $tokenId);

        if ($token->isEmpty()) {
            throw new Exception(Exception::TOKEN_NOT_FOUND);
        }

        $token->setAttribute('expire', $expire);

        $token = $dbForProject->updateDocument('resourceTokens', $tokenId, $token);

        $queueForEvents->setParam('tokenId', $token->getId());

        $response->dynamic($token, Response::MODEL_RESOURCE_TOKEN);
    }
}

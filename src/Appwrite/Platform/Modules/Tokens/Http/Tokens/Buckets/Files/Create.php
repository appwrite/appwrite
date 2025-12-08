<?php

namespace Appwrite\Platform\Modules\Tokens\Http\Tokens\Buckets\Files;

use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Auth\Proofs\Token;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Authorization\Input;
use Utopia\Database\Validator\Datetime as DatetimeValidator;
use Utopia\Database\Validator\UID;
use Utopia\Platform\Scope\HTTP;
use Utopia\Validator\Nullable;

class Create extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'createFileToken';
    }

    public function __construct()
    {
        $this->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
        ->setHttpPath('/v1/tokens/buckets/:bucketId/files/:fileId')
        ->desc('Create file token')
        ->groups(['api', 'token'])
        ->label('scope', 'tokens.write')
        ->label('event', 'tokens.[tokenId].create')
        ->label('audits.event', 'token.create')
        ->label('audits.resource', 'token/{response.$id}')
        ->label('usage.metric', 'tokens.{scope}.requests.create')
        ->label('usage.params', ['resourceId:{request.resourceId}', 'resourceType:{request.resourceType}'])
        ->label('abuse-key', 'ip:{ip},method:{method},url:{url},userId:{userId}')
        ->label('abuse-limit', APP_LIMIT_WRITE_RATE_DEFAULT)
        ->label('abuse-time', APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT)
        ->label('sdk', new Method(
            namespace: 'tokens',
            group: 'files',
            name: 'createFileToken',
            description: <<<EOT
            Create a new token. A token is linked to a file. Token can be passed as a request URL search parameter.
            EOT,
            auth: [AuthType::ADMIN, AuthType::KEY],
            responses: [
                new SDKResponse(
                    code: Response::STATUS_CODE_CREATED,
                    model: Response::MODEL_RESOURCE_TOKEN,
                )
            ],
            contentType: ContentType::JSON
        ))
        ->param('bucketId', '', new UID(), 'Storage bucket unique ID. You can create a new storage bucket using the Storage service [server integration](https://appwrite.io/docs/server/storage#createBucket).')
        ->param('fileId', '', new UID(), 'File unique ID.')
        ->param('expire', null, new Nullable(new DatetimeValidator(requireDateInFuture: true)), 'Token expiry date', true)
        ->inject('response')
        ->inject('dbForProject')
        ->inject('queueForEvents')
        ->inject('authorization')
        ->callback($this->action(...));
    }

    public function action(string $bucketId, string $fileId, ?string $expire, Response $response, Database $dbForProject, Event $queueForEvents, Authorization $authorization): void
    {
        /**
         * @var Document $bucket
         * @var Document $file
         */
        ['bucket' => $bucket, 'file' => $file] = $this->getFileAndBucket($dbForProject, $authorization, $bucketId, $fileId);

        $fileSecurity = $bucket->getAttribute('fileSecurity', false);
        $bucketPermission =  $authorization->isValid(new Input(Database::PERMISSION_UPDATE, $bucket->getUpdate()));

        if ($fileSecurity) {
            $filePermission = $authorization->isValid(new Input(Database::PERMISSION_UPDATE, $file->getUpdate()));
            if (!$bucketPermission && !$filePermission) {
                throw new Exception(Exception::USER_UNAUTHORIZED);
            }
        } elseif (!$bucketPermission) {
            throw new Exception(Exception::USER_UNAUTHORIZED);
        }

        $token = $dbForProject->createDocument('resourceTokens', new Document([
            '$id' => ID::unique(),
            'secret' => (new Token(128))->generate(),
            'resourceId' => $bucketId . ':' . $fileId,
            'resourceInternalId' => $bucket->getSequence() . ':' . $file->getSequence(),
            'resourceType' => TOKENS_RESOURCE_TYPE_FILES,
            'expire' => $expire,
        ]));

        $queueForEvents
            ->setParam('bucketId', $bucket->getId())
            ->setParam('fileId', $file->getId())
            ->setParam('tokenId', $token->getId())
            ->setContext('bucket', $bucket)
        ;

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($token, Response::MODEL_RESOURCE_TOKEN);
    }
}

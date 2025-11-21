<?php

namespace Appwrite\Platform\Modules\Databases\Http\VectorDB\Embeddings\Text;

use Appwrite\Auth\Auth;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Documents\Action as CreateDocumentAction;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Parameter;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Swoole\Response as SwooleResponse;
use Utopia\Validator\ArrayList;
use Utopia\Validator\JSON;

// Agent is dynamically provided via container; avoid strict type to pass lints

class Create extends CreateDocumentAction
{
    public static function getName(): string
    {
        return 'createTextEmbedding';
    }

    protected function getResponseModel(): string
    {
        return UtopiaResponse::MODEL_EMBEDDING;
    }

    protected function getBulkResponseModel(): string
    {
        return UtopiaResponse::MODEL_EMBEDDING_LIST;
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(self::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/vectordb/embeddings/text')
            ->desc('Create Text Embeddings')
            ->groups(['api', 'database'])
            ->label('scope', 'documents.read')
            ->label('resourceType', RESOURCE_TYPE_DATABASES)
            ->label('audits.event', 'embedding.create')
            ->label('audits.resource', 'vectordb/embeddings/text')
            ->label('abuse-key', 'ip:{ip},method:{method},url:{url},userId:{userId}')
            ->label('abuse-limit', APP_LIMIT_WRITE_RATE_DEFAULT * 2)
            ->label('abuse-time', APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT)
            ->label('sdk', [
                new Method(
                    namespace: 'vectorDB',
                    group: $this->getSdkGroup(),
                    name: 'createTextEmbeddings',
                    desc: 'Create Text Embedding',
                    description: '/docs/references/vectordb/create-document.md',
                    auth: [AuthType::SESSION, AuthType::KEY, AuthType::JWT],
                    responses: [
                        new SDKResponse(
                            code: SwooleResponse::STATUS_CODE_OK,
                            model: $this->getBulkResponseModel(),
                        )
                    ],
                    contentType: ContentType::JSON,
                    parameters: [
                        new Parameter('databaseId', optional: false),
                        new Parameter('collectionId', optional: false),
                        new Parameter('documents', optional: false),
                    ]
                )
            ])
            ->param('documents', [], fn (array $plan) => new ArrayList(new JSON(), $plan['databasesBatchSize'] ?? APP_LIMIT_DATABASE_BATCH), 'Array of documents data as JSON objects.', true, ['plan'])
            ->inject('response')
            ->inject('embeddingAgent')
            ->inject('dbForProject')
            ->inject('getDatabasesDB')
            ->callback($this->action(...));
    }

    public function action(array $documents, UtopiaResponse $response, $embeddingAgent, Database $dbForProject, callable $getDatabasesDB): void
    {
        $isAPIKey = Auth::isAppUser(Authorization::getRoles());
        $isPrivilegedUser = Auth::isPrivilegedUser(Authorization::getRoles());

        if (!$isAPIKey && !$isPrivilegedUser) {
            throw new Exception(Exception::GENERAL_BAD_REQUEST);
        }

        if (empty($documents)) {
            throw new Exception(Exception::DOCUMENT_MISSING_DATA);
        }

        // Validate and process each document
        $availableModels = [];
        $availableModels = $embeddingAgent->getAdapter()->getModels();

        $results = [];

        // validating all documents first
        foreach ($documents as $index => $item) {
            if (!\is_array($item)) {
                throw new Exception(Exception::DOCUMENT_INVALID_STRUCTURE, 'Invalid item at index ' . $index);
            }

            $text = $item['text'] ?? '';
            $model = $item['embeddingModel'] ?? ($item['embeddingModel'] ?? null);

            if (!\is_string($text) || $text === '') {
                throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Missing or invalid "text" at index ' . $index);
            }
            if (!\is_string($model) || $model === '') {
                throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Missing or invalid "embeddingModel" at index ' . $index);
            }
            if (!empty($availableModels) && !\in_array($model, $availableModels, true)) {
                throw new Exception(Exception::GENERAL_BAD_REQUEST, 'Unknown embedding model: ' . $model);
            }
        }
        foreach ($documents as $index => $item) {
            $embeddingAgent->getAdapter()->setModel($model);

            $embedResult = $embeddingAgent->embed($text);
            $vector = $embedResult['embedding'] ?? [];
            $dimensions = \is_array($vector) ? \count($vector) : 0;

            $results[] = new Document([
                'model' => $model,
                'dimensions' => $dimensions,
                'embeddings' => $vector,
            ]);

        }
        $list = new Document([
            'embeddings' => array_map(fn ($d) => $d, $results),
            'total' => \count($results),
        ]);

        $response
            ->setStatusCode(SwooleResponse::STATUS_CODE_OK)
            ->dynamic($list, $this->getBulkResponseModel());
    }
}

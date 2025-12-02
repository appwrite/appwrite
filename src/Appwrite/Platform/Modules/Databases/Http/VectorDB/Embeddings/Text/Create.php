<?php

namespace Appwrite\Platform\Modules\Databases\Http\VectorDB\Embeddings\Text;

use Appwrite\Event\StatsUsage;
use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Documents\Action as CreateDocumentAction;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Parameter;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Agents\Adapters\Ollama;
use Utopia\Agents\Agent;
use Utopia\Database\Document;
use Utopia\Swoole\Response as SwooleResponse;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

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
            ->label('scope', 'documents.write')
            ->label('resourceType', RESOURCE_TYPE_EMBEDDINGS_TEXT)
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
                    auth: [AuthType::KEY, AuthType::JWT],
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
            ->param('embeddingModel', Ollama::MODEL_EMBEDDING_GEMMA, new WhiteList(Ollama::MODELS), 'The embedding model to use for generating vector embeddings.', false)
            ->param('texts', [], fn (array $plan) => new ArrayList(new Text(0), $plan['databasesBatchSize'] ?? APP_LIMIT_DATABASE_BATCH), 'Array of text to generate embeddings.', true, ['plan'])
            ->inject('response')
            ->inject('embeddingAgent')
            ->inject('queueForStatsUsage')
            ->callback($this->action(...));
    }

    public function action(string $embeddingModel, array $texts, UtopiaResponse $response, Agent $embeddingAgent, StatsUsage $queueForStatsUsage): void
    {
        $results = [];
        $embeddingAgent->getAdapter()->setModel($embeddingModel);
        $dimension = $embeddingAgent->getAdapter()->getEmbeddingDimension();

        $totalDuration = 0;
        $totalTokens = 0;
        foreach ($texts as $text) {
            $embedding = [];
            $error = '';
            try {
                $embedResult = $embeddingAgent->embed($text);
                $embedding = $embedResult['embedding'] ?? [];
                $totalDuration += $embedResult['totalDuration'] ?? 0;
                $totalTokens += $embedResult['tokensProcessed'] ?? 0;
            } catch (\Exception) {
                $error = 'Error while generating embedding';
            }

            $results[] = new Document([
                'model' => $embeddingModel,
                'dimension' => $dimension,
                'embedding' => $embedding,
                'error' => $error
            ]);
        }
        $embeddings = new Document([
            'embeddings' => $results,
            'total' => \count($results),
        ]);

        $response
            ->setStatusCode(SwooleResponse::STATUS_CODE_OK)
            ->dynamic($embeddings, $this->getBulkResponseModel());

        $queueForStatsUsage
            ->addMetric(
                \str_replace(
                    '{embeddingModel}',
                    $embeddingModel,
                    METRIC_EMBEDDINGS_TEXT
                ),
                \count($texts)
            )
            ->addMetric(
                \str_replace(
                    '{embeddingModel}',
                    $embeddingModel,
                    METRIC_EMBEDDINGS_TEXT_TOTAL_TOKENS
                ),
                $totalTokens
            )
            ->addMetric(
                \str_replace(
                    '{embeddingModel}',
                    $embeddingModel,
                    METRIC_EMBEDDINGS_TEXT_TOTAL_DURATION
                ),
                $totalDuration
            )
            ->trigger();
        $queueForStatsUsage->reset();
    }
}

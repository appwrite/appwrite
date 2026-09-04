<?php

namespace Appwrite\Platform\Modules\Databases\Http\Embeddings\Text;

use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Documents\Action as CreateDocumentAction;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Parameter;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Usage\Context;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Agents\Adapters\Appwrite as AppwriteAdapter;
use Utopia\Agents\Agent;
use Utopia\Database\Document;
use Utopia\Http\Adapter\Swoole\Response as SwooleResponse;
use Utopia\Platform\Enum;
use Utopia\Span\Span;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

class Create extends CreateDocumentAction
{
    public static function getName(): string
    {
        return 'createTextEmbeddings';
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
            ->setHttpPath('/v1/embeddings/text')
            ->desc('Create text embeddings')
            ->groups(['api', 'database'])
            ->label('scope', 'embeddings.write')
            ->label('resourceType', RESOURCE_TYPE_EMBEDDINGS_TEXT)
            ->label('audits.event', 'embedding.create')
            ->label('audits.resource', 'embeddings/text')
            ->label('usage.resource', 'database/embeddings/text')
            ->label('abuse-key', 'ip:{ip},method:{method},url:{url},userId:{userId}')
            ->label('abuse-limit', APP_LIMIT_WRITE_RATE_DEFAULT * 2)
            ->label('abuse-time', APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT)
            ->label('sdk', [
                new Method(
                    namespace: 'embeddings',
                    group: 'embeddings',
                    name: 'createTextEmbeddings',
                    desc: 'Create Text Embedding',
                    description: '/docs/references/embeddings/create-text-embeddings.md',
                    auth: [AuthType::ADMIN, AuthType::KEY, AuthType::JWT],
                    responses: [
                        new SDKResponse(
                            code: SwooleResponse::STATUS_CODE_OK,
                            model: $this->getBulkResponseModel(),
                        )
                    ],
                    contentType: ContentType::JSON,
                    parameters: [
                        new Parameter('texts', optional: false),
                        new Parameter('model', optional: true),
                    ]
                )
            ])
            ->param('texts', [], fn (array $plan) => new ArrayList(new Text(0), $plan['databasesMaxEmbeddingTexts'] ?? APP_LIMIT_DATABASE_BATCH), 'Array of text to generate embeddings.', false, ['plan'])
            ->param('model', AppwriteAdapter::MODEL_NOMIC_EMBED_TEXT, new WhiteList([AppwriteAdapter::MODEL_NOMIC_EMBED_TEXT, AppwriteAdapter::MODEL_ALL_MINILM]), 'The embedding model to use for generating vector embeddings.', true, enum: new Enum(name: 'EmbeddingModel'))
            ->inject('response')
            ->inject('project')
            ->inject('embeddingAgent')
            ->inject('usage')
            ->callback($this->action(...));
    }

    public function action(array $texts, string $model, UtopiaResponse $response, Document $project, Agent $embeddingAgent, Context $usage): void
    {
        $adapter = $embeddingAgent->getAdapter();
        $adapter->setModel($model);
        $dimension = $adapter->getEmbeddingDimension();

        $results = [];
        $totalDuration = 0;
        $totalTokens = 0;
        $totalErrors = 0;

        foreach (array_chunk($texts, APP_EMBEDDING_BATCH_LIMIT) as $batch) {
            try {
                $embedResult = $embeddingAgent->bulkEmbed($batch);
                $totalDuration += $embedResult['totalDuration'] ?? 0;
                $totalTokens += $embedResult['tokensProcessed'] ?? 0;

                foreach ($embedResult['embeddings'] as $embedding) {
                    $results[] = $this->embeddingResult($model, $dimension, $embedding);
                }

                // Partial success: backend returned fewer embeddings than inputs.
                // Fill the gap with error results so the response stays 1:1 with texts.
                $missing = \count($batch) - \count($embedResult['embeddings']);
                for ($i = 0; $i < $missing; $i++) {
                    $totalErrors++;
                    $results[] = $this->embeddingResult($model, $dimension, [], 'Error while generating embedding');
                }
            } catch (\Exception $e) {
                $totalErrors += \count($batch);
                $this->logError($e, $model);

                // One error result per text in the failed batch.
                foreach ($batch as $ignored) {
                    $results[] = $this->embeddingResult($model, $dimension, [], 'Error while generating embedding');
                }
            }
        }

        $embeddings = new Document([
            'embeddings' => $results,
            'total' => \count($results),
        ]);

        $response
            ->setStatusCode(SwooleResponse::STATUS_CODE_OK)
            ->dynamic($embeddings, $this->getBulkResponseModel());

        $usage
            ->addMetric(METRIC_EMBEDDINGS_TEXT, \count($texts))
            ->addMetric(\str_replace('{embeddingModel}', $model, METRIC_EMBEDDINGS_MODEL_TEXT), \count($texts))
            ->addMetric(METRIC_EMBEDDINGS_TEXT_TOTAL_TOKENS, $totalTokens)
            ->addMetric(\str_replace('{embeddingModel}', $model, METRIC_EMBEDDINGS_MODEL_TEXT_TOTAL_TOKENS), $totalTokens)
            ->addMetric(METRIC_EMBEDDINGS_TEXT_TOTAL_DURATION, $totalDuration)
            ->addMetric(\str_replace('{embeddingModel}', $model, METRIC_EMBEDDINGS_MODEL_TEXT_TOTAL_DURATION), $totalDuration)
            ->addMetric(METRIC_EMBEDDINGS_TEXT_TOTAL_ERROR, $totalErrors)
            ->addMetric(\str_replace('{embeddingModel}', $model, METRIC_EMBEDDINGS_MODEL_TEXT_TOTAL_ERROR), $totalErrors);
    }

    /**
     * Build a single embedding response document.
     *
     * @param float[] $embedding
     */
    private function embeddingResult(string $model, int $dimension, array $embedding, string $error = ''): Document
    {
        return new Document([
            'model' => $model,
            'dimension' => $dimension,
            'embedding' => $embedding,
            'error' => $error,
        ]);
    }

    private function logError(\Throwable $e, string $model): void
    {
        Span::add('embedding.model', $model);
        Span::add('embedding.error', $e->getMessage());
        Span::add('embedding.error_code', $e->getCode());
    }
}

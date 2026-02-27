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
use Utopia\Http\Adapter\Swoole\Response as SwooleResponse;
use Utopia\Logger\Log;
use Utopia\Logger\Logger;
use Utopia\System\System;
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
                    auth: [AuthType::ADMIN, AuthType::KEY, AuthType::JWT],
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
            ->param('texts', [], fn (array $plan) => new ArrayList(new Text(0), $plan['databasesBatchSize'] ?? APP_LIMIT_DATABASE_BATCH), 'Array of text to generate embeddings.', false, ['plan'])
            ->param('model', Ollama::MODEL_EMBEDDING_GEMMA, new WhiteList(Ollama::MODELS), 'The embedding model to use for generating vector embeddings.', true)
            ->inject('response')
            ->inject('project')
            ->inject('embeddingAgent')
            ->inject('queueForStatsUsage')
            ->inject('log')
            ->inject('logger')
            ->callback($this->action(...));
    }

    public function action(array $texts, string $model, UtopiaResponse $response, Document $project, Agent $embeddingAgent, StatsUsage $queueForStatsUsage, Log $log, ?Logger $logger): void
    {
        $results = [];
        $embeddingAgent->getAdapter()->setModel($model)->setTimeout(60000);
        $dimension = $embeddingAgent->getAdapter()->getEmbeddingDimension();

        $totalDuration = 0;
        $totalTokens = 0;
        $totalErrors = 0;
        foreach ($texts as $text) {
            $embedding = [];
            $error = '';
            try {
                $embedResult = $embeddingAgent->embed($text);
                $embedding = $embedResult['embedding'] ?? [];
                $totalDuration += $embedResult['totalDuration'] ?? 0;
                $totalTokens += $embedResult['tokensProcessed'] ?? 0;
            } catch (\Exception $e) {
                $error = 'Error while generating embedding';
                $totalErrors += 1;
                if ($logger) {
                    $log->setNamespace("http");
                    $log->setServer(System::getEnv('_APP_LOGGING_SERVICE_IDENTIFIER', \gethostname()));
                    $log->setVersion(System::getEnv('_APP_VERSION', 'UNKNOWN'));
                    $log->setType(Log::TYPE_ERROR);
                    $log->setMessage($e->getMessage());

                    $log->addTag('embeddingModel', $model);
                    $log->addTag('code', $e->getCode());
                    $log->addTag('projectId', $project->getId());

                    $log->addExtra('file', $e->getFile());
                    $log->addExtra('line', $e->getLine());
                    $log->addExtra('trace', $e->getTraceAsString());

                    $logger->addLog($log);
                }
            }

            $results[] = new Document([
                'model' => $model,
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
            ->setProject($project)
            ->addMetric(METRIC_EMBEDDINGS_TEXT, \count($texts))
            ->addMetric(\str_replace('{embeddingModel}', $model, METRIC_EMBEDDINGS_MODEL_TEXT), \count($texts))
            ->addMetric(METRIC_EMBEDDINGS_TEXT_TOTAL_TOKENS, $totalTokens)
            ->addMetric(\str_replace('{embeddingModel}', $model, METRIC_EMBEDDINGS_MODEL_TEXT_TOTAL_TOKENS), $totalTokens)
            ->addMetric(METRIC_EMBEDDINGS_TEXT_TOTAL_DURATION, $totalDuration)
            ->addMetric(\str_replace('{embeddingModel}', $model, METRIC_EMBEDDINGS_MODEL_TEXT_TOTAL_DURATION), $totalDuration)
            ->addMetric(METRIC_EMBEDDINGS_TEXT_TOTAL_ERROR, $totalErrors)
            ->addMetric(\str_replace('{embeddingModel}', $model, METRIC_EMBEDDINGS_MODEL_TEXT_TOTAL_ERROR), $totalErrors)
            ->trigger();

        $queueForStatsUsage->reset();
    }
}

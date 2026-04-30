<?php

namespace Appwrite\Platform\Modules\Databases\Http\VectorsDB\Embeddings\Text;

use Appwrite\Platform\Modules\Databases\Http\Databases\Collections\Documents\Action as CreateDocumentAction;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Parameter;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Usage\Context;
use Appwrite\Utopia\Response as UtopiaResponse;
use Utopia\Agents\Adapters\Ollama;
use Utopia\Agents\Agent;
use Utopia\Database\Document;
use Utopia\Http\Adapter\Swoole\Response as SwooleResponse;
use Utopia\Span\Span;
use Utopia\System\System;
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
            ->setHttpPath('/v1/vectorsdb/embeddings/text')
            ->desc('Create Text Embeddings')
            ->groups(['api', 'database'])
            ->label('scope', 'documents.write')
            ->label('resourceType', RESOURCE_TYPE_EMBEDDINGS_TEXT)
            ->label('audits.event', 'embedding.create')
            ->label('audits.resource', 'vectorsdb/embeddings/text')
            ->label('abuse-key', 'ip:{ip},method:{method},url:{url},userId:{userId}')
            ->label('abuse-limit', APP_LIMIT_WRITE_RATE_DEFAULT * 2)
            ->label('abuse-time', APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT)
            ->label('sdk', [
                new Method(
                    namespace: 'vectorsDB',
                    group: $this->getSdkGroup(),
                    name: 'createTextEmbeddings',
                    desc: 'Create Text Embedding',
                    description: '/docs/references/vectorsdb/create-document.md',
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
            ->param('model', Ollama::MODEL_EMBEDDING_GEMMA, new WhiteList(Ollama::MODELS), 'The embedding model to use for generating vector embeddings.', true)
            ->inject('response')
            ->inject('project')
            ->inject('embeddingAgent')
            ->inject('usage')
            ->callback($this->action(...));
    }

    public function action(array $texts, string $model, UtopiaResponse $response, Document $project, Agent $embeddingAgent, Context $usage): void
    {
        $results = [];
        $embeddingAgent->getAdapter()->setModel($model);
        $dimension = $embeddingAgent->getAdapter()->getEmbeddingDimension();

        $totalDuration = 0;
        $totalTokens = 0;
        $totalErrors = 0;
        foreach ($texts as $text) {
            $embedding = [];
            $error = '';
            try {
                $embedResult = $embeddingAgent->embed($text);
                $embedding = $embedResult['embedding'];
                $totalDuration += $embedResult['totalDuration'] ?? 0;
                $totalTokens += $embedResult['tokensProcessed'] ?? 0;
            } catch (\Exception $e) {
                $error = 'Error while generating embedding';
                $totalErrors += 1;

                // Export each failed text independently without overwriting the request span.
                $span = new Span('vectorsDB.createTextEmbeddings');
                $span->set('level', 'error');
                $span->set('logger', 'http');
                $span->set('server.name', System::getEnv('_APP_LOGGING_SERVICE_IDENTIFIER', \gethostname()));
                $span->set('release', System::getEnv('_APP_VERSION', 'UNKNOWN'));
                $span->set('environment', System::getEnv('_APP_ENV', 'development') === 'production' ? 'production' : 'staging');
                $span->set('appwrite.error.publish', true);
                $span->set('appwrite.error.action', 'vectorsDB.createTextEmbeddings');
                $span->set('embeddingModel', $model);
                $span->set('code', $e->getCode());
                $span->set('projectId', $project->getId());
                $span->set('error.message', $e->getMessage());
                $span->set('error.file', $e->getFile());
                $span->set('error.line', $e->getLine());
                $span->set('error.trace', $e->getTraceAsString());
                $span->setError($e);
                $span->finish();
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
}

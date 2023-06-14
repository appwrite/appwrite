<?php

namespace Appwrite\Event;

use Appwrite\Auth\Auth;
use Appwrite\Messaging\Adapter\Realtime;
use Appwrite\Utopia\Response;
use Swoole\Http\Response as SwooleResponse;
use Utopia\App;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\Locale\Locale;
use Utopia\Logger\Log;
use Utopia\Logger\Log\User;
use Utopia\Logger\Logger;

class DatabaseListener {
    protected ?Logger $logger;
    protected array $loggerBreadcrumbs;
    protected Document $project;
    protected Document $user;
    protected Event $events;
    protected Locale $locale;

    public function __construct(?Logger $logger, array $loggerBreadcrumbs, Document $project, Document $user, Event $events, Locale $locale)
    {
        $this->logger = $logger;
        $this->loggerBreadcrumbs = $loggerBreadcrumbs;
        $this->project = $project;
        $this->user = $user;
        $this->events = $events;
        $this->locale = $locale;
    }

    public function handle(string $databaseEvent, Document $document)
    {
        $response = new Response(new SwooleResponse());
        $dump = function ($value) {
            $p = var_export($value, true);
            $b = debug_backtrace();
            print($b[0]['file'] . ':' . $b[0]['line'] . ' - ' . $p . "\n");
        };
        try {
            $event = new Event('', '');

            $event
                ->setProject($this->project)
                ->setUser($this->user);

            $eventPattern = $this->events->getEvent();
            $collection = $document->getCollection();
            $dump($databaseEvent);
            $dump($collection);

            $action = match ($databaseEvent) {
                Database::EVENT_DOCUMENT_CREATE => '.create',
                Database::EVENT_DOCUMENT_UPDATE => '.update',
                Database::EVENT_DOCUMENT_DELETE => '.delete',
            };

            // Create a copy of the document to avoid any changes to the original
            $document = new Document($document->getArrayCopy());

            switch ($collection) {
                case 'users':
                    $modelType = Response::MODEL_USER;
                    if (!\str_starts_with($eventPattern, 'users.[userId].update')) {
                        $eventPattern = 'users.[userId]' . $action;
                    }

                    $event->setParam('userId', $document->getId());
                    break;
                case 'sessions':
                    $modelType = Response::MODEL_SESSION;
                    $eventPattern = 'users.[userId].sessions.[sessionId]' . $action;

                    $current = $document->getAttribute('secret') == Auth::hash(Auth::$secret);
                    $countryName = $this->locale->getText('countries.' . strtolower($document->getAttribute('countryCode')), $this->locale->getText('locale.country.unknown'));
                    $expire = DateTime::addSeconds(new \DateTime($document->getCreatedAt()), Auth::TOKEN_EXPIRATION_LOGIN_LONG);
                    $document
                        ->setAttribute('current', $current)
                        ->setAttribute('countryName', $countryName)
                        ->setAttribute('expire', $expire);

                    $event
                        ->setParam('userId', $document->getAttribute('userId'))
                        ->setParam('sessionId', $document->getId());
                    break;
                case 'tokens':
                    $modelType = Response::MODEL_TOKEN;

                    $tokenType = $document->getAttribute('type');
                    $eventPattern = match($tokenType) {
                        Auth::TOKEN_TYPE_VERIFICATION, Auth::TOKEN_TYPE_PHONE => 'users.[userId].verification.[tokenId]',
                        Auth::TOKEN_TYPE_RECOVERY => 'users.[userId].recovery.[tokenId]',
                        default => '',
                    };

                    if (empty($eventPattern)) {
                        return;
                    }

                    $eventPattern .= match ($databaseEvent) {
                        Database::EVENT_DOCUMENT_CREATE => '.create',
                        // We act like we're updating and validating the 
                        // token but actually we don't need it anymore.
                        Database::EVENT_DOCUMENT_DELETE => '.update', 
                    };

                    $token = $this->events->getContext('token');
                    $secret = $token->getAttribute('secret', null);

                    $document->setAttribute('secret', $secret);

                    $event
                        ->setParam('userId', $document->getAttribute('userId'))
                        ->setParam('tokenId', $document->getId());
                    break;
                case 'teams':
                    $modelType = Response::MODEL_TEAM;
                    if ($eventPattern !== 'teams.[teamId].update.prefs') {
                        $eventPattern = 'teams.[teamId]' . $action;
                    }

                    $event->setParam('teamId', $document->getId());
                    break;
                case 'memberships':
                    $modelType = Response::MODEL_MEMBERSHIP;
                    $eventPattern = 'teams.[teamId].memberships.[membershipId]' . $action;

                    $team = $this->events->getContext('team');
                    $user = $this->events->getContext('user');

                    $event
                        ->setParam('teamId', $document->getAttribute('teamId'))
                        ->setParam('membershipId', $document->getId());

                    $document
                        ->setAttribute('teamName', $team->getAttribute('name'))
                        ->setAttribute('userName', $user->getAttribute('name'))
                        ->setAttribute('userEmail', $user->getAttribute('email'));
                    break;
                case 'databases':
                    $modelType = Response::MODEL_DATABASE;
                    $eventPattern = 'databases.[databaseId]' . $action;
                    $event->setParam('databaseId', $document->getId());
                    break;
                case 'attributes':
                    $eventPattern = 'databases.[databaseId].collections.[collectionId].attributes.[attributeId]' . $action;
                    $database = $this->events->getContext('database');
                    $collection = $this->events->getContext('collection');
                    $event
                        ->setContext('database', $database)
                        ->setContext('collection', $collection);

                    $event
                        ->setParam('databaseId', $database->getId())
                        ->setParam('collectionId', $collection->getId())
                        ->setParam('attributeId', $document->getId());

                    // Select response model based on type and format
                    $type = $document->getAttribute('type');
                    $format = $document->getAttribute('format');
                    $modelType = match ($type) {
                        Database::VAR_BOOLEAN => Response::MODEL_ATTRIBUTE_BOOLEAN,
                        Database::VAR_INTEGER => Response::MODEL_ATTRIBUTE_INTEGER,
                        Database::VAR_FLOAT => Response::MODEL_ATTRIBUTE_FLOAT,
                        Database::VAR_DATETIME => Response::MODEL_ATTRIBUTE_DATETIME,
                        Database::VAR_RELATIONSHIP => Response::MODEL_ATTRIBUTE_RELATIONSHIP,
                        Database::VAR_STRING => match ($format) {
                            APP_DATABASE_ATTRIBUTE_EMAIL => Response::MODEL_ATTRIBUTE_EMAIL,
                            APP_DATABASE_ATTRIBUTE_ENUM => Response::MODEL_ATTRIBUTE_ENUM,
                            APP_DATABASE_ATTRIBUTE_IP => Response::MODEL_ATTRIBUTE_IP,
                            APP_DATABASE_ATTRIBUTE_URL => Response::MODEL_ATTRIBUTE_URL,
                            default => Response::MODEL_ATTRIBUTE_STRING,
                        },
                        default => Response::MODEL_ATTRIBUTE,
                    };

                    break;
                case 'indexes':
                    $modelType = Response::MODEL_INDEX;
                    $eventPattern = 'databases.[databaseId].collections.[collectionId].indexes.[indexId]' . $action;
                    $database = $this->events->getContext('database');
                    $collection = $this->events->getContext('collection');
                    $event
                        ->setContext('database', $database)
                        ->setContext('collection', $collection);

                    $event
                        ->setParam('databaseId', $database->getId())
                        ->setParam('collectionId', $collection->getId())
                        ->setParam('indexId', $document->getId());
                    break;
                case 'buckets':
                    $modelType = Response::MODEL_BUCKET;
                    $eventPattern = 'buckets.[bucketId]' . $action;

                    $event->setParam('bucketId', $document->getId());
                    break;
                case \str_starts_with($collection, 'bucket_'):
                    $modelType = Response::MODEL_FILE;
                    $eventPattern = 'buckets.[bucketId].files.[fileId]' . $action;

                    $bucket = $this->events->getContext('bucket');
                    $event->setContext('bucket', $bucket);

                    $event
                        ->setParam('bucketId', $bucket->getId())
                        ->setParam('fileId', $document->getId());
                    break;
                case 'functions':
                    $modelType = Response::MODEL_FUNCTION;
                    $eventPattern = 'functions.[functionId]' . $action;

                    $event->setParam('functionId', $document->getId());
                    break;
                case 'deployments':
                    $modelType = Response::MODEL_DEPLOYMENT;
                    $eventPattern = 'functions.[functionId].deployments.[deploymentId]' . $action;

                    $event
                        ->setParam('functionId', $document->getAttribute('resourceId'))
                        ->setParam('deploymentId', $document->getId());
                    break;
                case 'executions':
                    $modelType = Response::MODEL_EXECUTION;
                    $eventPattern = 'functions.[functionId].executions.[executionId]' . $action;
        
                    $function = $this->events->getContext('function');
                    $event->setContext('function', $function);

                    $event
                        ->setParam('functionId', $function->getId())
                        ->setParam('executionId', $document->getId());
                    break;
                case \str_starts_with($collection, 'database_'):
                    if (\str_contains($collection, '_collection_')) {
                        $modelType = Response::MODEL_DOCUMENT;
                        $eventPattern = 'databases.[databaseId].collections.[collectionId].documents.[documentId]' . $action;

                        $database = $this->events->getContext('database');
                        $collection = $this->events->getContext('collection');

                        $document->setAttribute('$databaseId', $database->getId());
                        $document->setAttribute('$collectionId', $collection->getId());

                        $event
                            ->setContext('database', $database)
                            ->setContext('collection', $collection);

                        $event
                            ->setParam('databaseId', $database->getId())
                            ->setParam('collectionId', $collection->getId())
                            ->setParam('documentId', $document->getId());
                    } else {
                        $modelType = Response::MODEL_COLLECTION;
                        $eventPattern = 'databases.[databaseId].collections.[collectionId]' . $action;

                        $database = $this->events->getContext('database');
                        $event->setContext('database', $database);

                        $event
                            ->setParam('databaseId', $database->getId())
                            ->setParam('collectionId', $document->getId());
                    }
                    break;
                default:
                    return;
            }

            $event->setEvent($eventPattern);

            $payload = $response->output($document, $modelType);
            $event->setPayload($payload);

            $dump($event->getEvent());
            $dump($event->getParams());
            $dump($event->getPayload());

            /**
             * Trigger functions.
             */
            $event
                ->setClass(Event::FUNCTIONS_CLASS_NAME)
                ->setQueue(Event::FUNCTIONS_QUEUE_NAME)
                ->trigger();

            /**
             * Trigger webhooks.
             */
            $event
                ->setClass(Event::WEBHOOK_CLASS_NAME)
                ->setQueue(Event::WEBHOOK_QUEUE_NAME)
                ->trigger();

            /**
             * Trigger realtime.
             */
            if ($this->project->getId() !== 'console') {
                $allEvents = Event::generateEvents($event->getEvent(), $event->getParams());
                $payload = new Document($event->getPayload());

                if ($payload->getAttribute('secret') !== null) {
                    $payload->setAttribute('secret', '');
                }

                $db = $event->getContext('database');
                $collection = $event->getContext('collection');
                $bucket = $event->getContext('bucket');

                $target = Realtime::fromPayload(
                    // Pass first, most verbose event pattern
                    event: $allEvents[0],
                    payload: $payload,
                    project: $this->project,
                    database: $db,
                    collection: $collection,
                    bucket: $bucket,
                );

                Realtime::send(
                    projectId: $target['projectId'] ?? $this->project->getId(),
                    payload: $event->getPayload(),
                    events: $allEvents,
                    channels: $target['channels'],
                    roles: $target['roles'],
                    options: [
                        'permissionsChanged' => $target['permissionsChanged'],
                        'userId' => $event->getParam('userId')
                    ]
                );
            }
        } catch (\Throwable $th) {
            $dump($th->getMessage());
            if (empty($logger)) {
                return;
            }

            $log = new Log();

            if (isset($user) && !$user->isEmpty()) {
                $log->setUser(new User($user->getId()));
            }

            $log->setNamespace("http");
            $log->setServer(\gethostname());
            $log->setVersion(App::getEnv('_APP_VERSION', 'UNKNOWN'));
            $log->setType(Log::TYPE_ERROR);
            $log->setMessage($th->getMessage());

            $log->addTag('verboseType', get_class($th));
            $log->addTag('code', $th->getCode());
            $log->addTag('projectId', $this->project->getId());
            $log->addTag('locale', $this->locale->default);

            $log->addExtra('file', $th->getFile());
            $log->addExtra('line', $th->getLine());
            $log->addExtra('trace', $th->getTraceAsString());
            $log->addExtra('detailedTrace', $th->getTrace());
            $log->addExtra('roles', Authorization::getRoles());

            $log->setAction($action);

            $isProduction = App::getEnv('_APP_ENV', 'development') === 'production';
            $log->setEnvironment($isProduction ? Log::ENVIRONMENT_PRODUCTION : Log::ENVIRONMENT_STAGING);

            foreach ($this->loggerBreadcrumbs as $loggerBreadcrumb) {
                $log->addBreadcrumb($loggerBreadcrumb);
            }

            $logger->addLog($log);
        }
    }
}
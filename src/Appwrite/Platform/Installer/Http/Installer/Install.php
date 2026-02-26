<?php

namespace Appwrite\Platform\Installer\Http\Installer;

use Appwrite\Platform\Installer\Runtime\Config;
use Appwrite\Platform\Installer\Runtime\State;
use Appwrite\Platform\Installer\Server;
use Swoole\Http\Response as SwooleResponse;
use Utopia\Http\Adapter\Swoole\Request;
use Utopia\Http\Adapter\Swoole\Response;
use Utopia\Platform\Action;

class Install extends Action
{
    private const int SSE_KEEPALIVE_DELAY_MICROSECONDS = 500000;

    public static function getName(): string
    {
        return 'installerInstall';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/install')
            ->desc('Run installation')
            ->inject('request')
            ->inject('response')
            ->inject('swooleResponse')
            ->inject('installerState')
            ->inject('installerConfig')
            ->inject('installerPaths')
            ->callback($this->action(...));
    }

    public function action(Request $request, Response $response, SwooleResponse $swooleResponse, State $state, Config $config, array $paths): void
    {
        $acceptHeader = $request->getHeader('accept');
        $wantsStream = stripos($acceptHeader, 'text/event-stream') !== false;

        if ($wantsStream) {
            $swooleResponse->header('Content-Type', 'text/event-stream');
            $swooleResponse->header('Cache-Control', 'no-cache');
            $swooleResponse->header('Connection', 'keep-alive');
            $swooleResponse->header('X-Accel-Buffering', 'no');

            $swooleResponse->write("event: ping\ndata: {\"time\":" . time() . "}\n\n");
        }

        if (!Validate::validateCsrf($request)) {
            $this->sendBadRequest($response, $swooleResponse, $wantsStream, 'Invalid CSRF token');
            return;
        }

        $rawBody = $request->getRawPayload();
        $input = json_decode($rawBody, true);

        if (!is_array($input)) {
            $this->sendBadRequest($response, $swooleResponse, $wantsStream, 'Invalid request');
            return;
        }

        $appDomain = trim((string) ($input['appDomain'] ?? ''));
        if ($appDomain === '' || !$state->isValidAppDomainInput($appDomain)) {
            $this->sendBadRequest($response, $swooleResponse, $wantsStream, 'Please enter a valid hostname');
            return;
        }
        $input['appDomain'] = $appDomain;

        $httpPort = $input['httpPort'] ?? '';
        if (!$state->isValidPort($httpPort)) {
            $this->sendBadRequest($response, $swooleResponse, $wantsStream, 'Please enter a valid HTTP port (1-65535)');
            return;
        }

        $httpsPort = $input['httpsPort'] ?? '';
        if (!$state->isValidPort($httpsPort)) {
            $this->sendBadRequest($response, $swooleResponse, $wantsStream, 'Please enter a valid HTTPS port (1-65535)');
            return;
        }

        $emailCertificates = trim((string) ($input['emailCertificates'] ?? ''));
        if ($emailCertificates === '' || !$state->isValidEmailAddress($emailCertificates)) {
            $this->sendBadRequest($response, $swooleResponse, $wantsStream, 'Please enter a valid email address');
            return;
        }
        $input['emailCertificates'] = $emailCertificates;

        $opensslKey = trim((string) ($input['opensslKey'] ?? ''));
        if (!$state->isValidSecretKey($opensslKey)) {
            $this->sendBadRequest($response, $swooleResponse, $wantsStream, 'Secret API key must be 1-64 characters');
            return;
        }
        $input['opensslKey'] = $opensslKey;

        $assistantOpenAIKey = trim((string) ($input['assistantOpenAIKey'] ?? ''));
        $input['assistantOpenAIKey'] = $assistantOpenAIKey;

        $account = [];
        if (!$config->isUpgrade()) {
            $accountEmail = trim((string) ($input['accountEmail'] ?? ''));
            if ($accountEmail === '' || !$state->isValidEmailAddress($accountEmail)) {
                $this->sendBadRequest($response, $swooleResponse, $wantsStream, 'Please enter a valid email address', Server::STEP_ACCOUNT_SETUP);
                return;
            }

            $accountPassword = (string) ($input['accountPassword'] ?? '');
            if (!$state->isValidPassword($accountPassword)) {
                $this->sendBadRequest($response, $swooleResponse, $wantsStream, 'Password must be at least 8 characters', Server::STEP_ACCOUNT_SETUP);
                return;
            }

            $accountName = $this->deriveNameFromEmail($accountEmail);

            $input['accountEmail'] = $accountEmail;
            $input['accountPassword'] = $accountPassword;

            $account = [
                'name' => $accountName,
                'email' => $accountEmail,
                'password' => $accountPassword,
            ];
        }

        $lockedDatabase = $config->getLockedDatabase();
        if (!$lockedDatabase) {
            $database = strtolower(trim((string) ($input['database'] ?? '')));
            if (!$state->isValidDatabaseAdapter($database)) {
                $this->sendBadRequest($response, $swooleResponse, $wantsStream, 'Please select a supported database');
                return;
            }
            $input['database'] = $database;
        }

        $installId = $state->sanitizeInstallId($input['installId'] ?? '');
        if ($installId === '') {
            $installId = bin2hex(random_bytes(8));
        }

        @unlink(Server::INSTALLER_COMPLETE_FILE);

        try {
            $lockResult = $state->reserveGlobalLock($installId);
        } catch (\Throwable $e) {
            if ($wantsStream) {
                $this->writeSseEvent($swooleResponse, Server::STATUS_ERROR, ['message' => 'Lock failed: ' . $e->getMessage()]);
                $swooleResponse->end();
            } else {
                $response->setStatusCode(Response::STATUS_CODE_INTERNAL_SERVER_ERROR);
                $response->json(['success' => false, 'message' => 'Lock failed: ' . $e->getMessage()]);
            }
            return;
        }

        if ($lockResult !== 'ok') {
            $lockMessage = $lockResult === 'locked'
                ? 'Installation already in progress'
                : 'Installer lock unavailable';
            if ($wantsStream) {
                $this->writeSseEvent($swooleResponse, Server::STATUS_ERROR, ['message' => $lockMessage]);
                $swooleResponse->end();
            } else {
                $statusCode = $lockResult === 'locked'
                    ? Response::STATUS_CODE_CONFLICT
                    : Response::STATUS_CODE_SERVICE_UNAVAILABLE;
                $response->setStatusCode($statusCode);
                $response->json(['success' => false, 'message' => $lockMessage]);
            }
            return;
        }

        $retryStep = $input['retryStep'] ?? null;
        $allowedRetrySteps = [Server::STEP_DOCKER_COMPOSE, Server::STEP_ENV_VARS, Server::STEP_DOCKER_CONTAINERS];
        if (!is_string($retryStep) || !in_array($retryStep, $allowedRetrySteps, true)) {
            $retryStep = null;
        }

        $existingPath = $state->progressFilePath($installId);
        $existing = null;
        if (file_exists($existingPath)) {
            $existing = $state->readProgressFile($installId);
            if (!empty($existing['steps']) && $retryStep === null) {
                if ($wantsStream) {
                    $this->writeSseEvent($swooleResponse, Server::STATUS_ERROR, ['message' => 'Installation already started']);
                    $swooleResponse->end();
                } else {
                    $response->setStatusCode(Response::STATUS_CODE_CONFLICT);
                    $response->json(['success' => false, 'message' => 'Installation already started']);
                }
                return;
            }
        }

        try {
            $state->ensureBootstrapped();
            require_once $paths['installPhp'];
            $installer = new \Appwrite\Platform\Tasks\Install();

            if ($wantsStream) {
                $this->writeSseEvent($swooleResponse, 'install-id', ['installId' => $installId]);
            }

            $state->updateGlobalLock($installId, Server::STATUS_IN_PROGRESS);

            $payloadInput = [
                '_APP_ENV' => 'production',
                '_APP_OPENSSL_KEY_V1' => $input['opensslKey'] ?? '',
                '_APP_DOMAIN' => $input['appDomain'] ?? 'localhost',
                '_APP_DOMAIN_TARGET' => $input['appDomain'] ?? 'localhost',
                '_APP_EMAIL_CERTIFICATES' => $input['emailCertificates'] ?? '',
                '_APP_DB_ADAPTER' => $lockedDatabase ?? ($input['database'] ?? 'mongodb'),
                '_APP_ASSISTANT_OPENAI_API_KEY' => $input['assistantOpenAIKey'] ?? '',
            ];

            if ($this->hasPayload($existing)) {
                $stored = $existing['payload'];
                $fieldsToCompare = [
                    'httpPort',
                    'httpsPort',
                    'database',
                    'appDomain',
                    'emailCertificates',
                ];
                foreach ($fieldsToCompare as $field) {
                    if (isset($stored[$field]) && isset($input[$field])) {
                        $storedValue = (string) $stored[$field];
                        $inputValue = (string) $input[$field];
                        if (in_array($field, ['httpPort', 'httpsPort'], true)) {
                            $storedValue = trim($storedValue);
                            $inputValue = trim($inputValue);
                        }
                        if ($storedValue !== $inputValue) {
                            if ($installId !== '') {
                                $state->updateGlobalLock($installId, Server::STATUS_ERROR);
                            }
                            $this->sendBadRequest($response, $swooleResponse, $wantsStream, 'Installation payload mismatch');
                            return;
                        }
                    }
                }

                $sensitiveFields = [
                    'opensslKey' => 'opensslKeyHash',
                    'assistantOpenAIKey' => 'assistantOpenAIKeyHash',
                ];
                foreach ($sensitiveFields as $field => $hashField) {
                    if (!isset($stored[$hashField]) && !isset($stored[$field])) {
                        continue;
                    }
                    $incomingHash = $state->hashSensitiveValue((string) ($input[$field] ?? ''));
                    if (isset($stored[$hashField])) {
                        if (!hash_equals((string) $stored[$hashField], $incomingHash)) {
                            if ($installId !== '') {
                                $state->updateGlobalLock($installId, Server::STATUS_ERROR);
                            }
                            $this->sendBadRequest($response, $swooleResponse, $wantsStream, 'Installation payload mismatch');
                            return;
                        }
                    } elseif (isset($stored[$field]) && isset($input[$field]) && (string) $stored[$field] !== (string) $input[$field]) {
                        if ($installId !== '') {
                            $state->updateGlobalLock($installId, Server::STATUS_ERROR);
                        }
                        $this->sendBadRequest($response, $swooleResponse, $wantsStream, 'Installation payload mismatch');
                        return;
                    }
                }

                $payloadInput['_APP_DOMAIN'] = $stored['appDomain'] ?? $payloadInput['_APP_DOMAIN'];
                $payloadInput['_APP_DOMAIN_TARGET'] = $stored['appDomain'] ?? $payloadInput['_APP_DOMAIN_TARGET'];
                $payloadInput['_APP_EMAIL_CERTIFICATES'] = $stored['emailCertificates'] ?? $payloadInput['_APP_EMAIL_CERTIFICATES'];
                $payloadInput['_APP_DB_ADAPTER'] = $lockedDatabase ?? ($stored['database'] ?? $payloadInput['_APP_DB_ADAPTER']);
                $input['httpPort'] = $stored['httpPort'] ?? $input['httpPort'] ?? $config->getDefaultHttpPort();
                $input['httpsPort'] = $stored['httpsPort'] ?? $input['httpsPort'] ?? $config->getDefaultHttpsPort();
            }

            $vars = $config->getVars();
            $shouldGenerateSecrets = !$installer->hasExistingConfig() && !$config->isUpgrade();
            $envVars = $installer->prepareEnvironmentVariables($payloadInput, $vars, $shouldGenerateSecrets);

            $state->writeProgressFile($installId, [
                'payload' => [
                    'httpPort' => $input['httpPort'] ?? $config->getDefaultHttpPort(),
                    'httpsPort' => $input['httpsPort'] ?? $config->getDefaultHttpsPort(),
                    'database' => $lockedDatabase ?? ($input['database'] ?? 'mongodb'),
                    'appDomain' => $input['appDomain'] ?? 'localhost',
                    'emailCertificates' => $input['emailCertificates'] ?? '',
                    'opensslKeyHash' => $state->hashSensitiveValue($input['opensslKey'] ?? ''),
                    'assistantOpenAIKeyHash' => $state->hashSensitiveValue($input['assistantOpenAIKey'] ?? ''),
                ],
                'step' => 'start',
                'status' => Server::STATUS_IN_PROGRESS,
                'message' => 'Installation started',
                'updatedAt' => time(),
            ]);

            $progress = function (string $step, string $status, string $message, array $details = []) use ($installId, $wantsStream, $swooleResponse, $state) {
                $payload = [
                    'installId' => $installId,
                    'step' => $step,
                    'status' => $status,
                    'message' => $message,
                    'updatedAt' => time(),
                ];
                if (!empty($details)) {
                    $payload['details'] = $details;
                }
                $state->writeProgressFile($installId, $payload);
                $state->updateGlobalLock($installId, Server::STATUS_IN_PROGRESS);
                if ($wantsStream) {
                    $this->writeSseEvent($swooleResponse, 'progress', $payload);
                }
            };

            $installer->performInstallation(
                $input['httpPort'] ?? $config->getDefaultHttpPort(),
                $input['httpsPort'] ?? $config->getDefaultHttpsPort(),
                $config->getOrganization(),
                $config->getImage(),
                $envVars,
                $config->getNoStart(),
                $progress,
                $retryStep,
                $config->isUpgrade(),
                $account
            );

            if ($wantsStream) {
                $this->writeSseEvent($swooleResponse, 'done', ['installId' => $installId, 'success' => true]);
                usleep(self::SSE_KEEPALIVE_DELAY_MICROSECONDS);
                $swooleResponse->write(": keepalive\n\n");
                usleep(self::SSE_KEEPALIVE_DELAY_MICROSECONDS);
                $swooleResponse->end();
            } else {
                $response->json([
                    'success' => true,
                    'installId' => $installId,
                    'message' => 'Installation completed successfully',
                ]);
            }
            $state->updateGlobalLock($installId, Server::STATUS_COMPLETED);
        } catch (\Throwable $e) {
            $this->handleInstallationError($e, $installId, $wantsStream, $response, $swooleResponse, $state);
        }
    }

    private function writeSseEvent(SwooleResponse $swooleResponse, string $event, array $payload): void
    {
        $swooleResponse->write("event: $event\ndata: " . json_encode($payload) . "\n\n");
    }

    private function sendBadRequest(Response $response, SwooleResponse $swooleResponse, bool $wantsStream, string $message, string $step = Server::STEP_CONFIG_FILES): void
    {
        if ($wantsStream) {
            $this->writeSseEvent($swooleResponse, Server::STATUS_ERROR, ['message' => $message, 'step' => $step]);
            $swooleResponse->end();
        } else {
            $response->setStatusCode(Response::STATUS_CODE_BAD_REQUEST);
            $response->json(['success' => false, 'message' => $message]);
        }
    }

    private function handleInstallationError(\Throwable $e, string $installId, bool $wantsStream, Response $response, SwooleResponse $swooleResponse, State $state): void
    {
        if ($installId !== '') {
            $state->writeProgressFile($installId, [
                'step' => Server::STATUS_ERROR,
                'status' => Server::STATUS_ERROR,
                'message' => $e->getMessage(),
                'details' => $this->buildErrorDetails($e),
                'updatedAt' => time(),
            ]);
            $state->updateGlobalLock($installId, Server::STATUS_ERROR);
        }

        @unlink(Server::INSTALLER_CONFIG_FILE);

        if ($wantsStream) {
            $this->writeSseEvent($swooleResponse, Server::STATUS_ERROR, [
                'message' => $e->getMessage(),
                'details' => $this->buildErrorDetails($e)
            ]);
            $swooleResponse->end();
        } else {
            $response->setStatusCode(Response::STATUS_CODE_INTERNAL_SERVER_ERROR);
            $response->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    private function buildErrorDetails(\Throwable $e): array
    {
        $details = ['trace' => $e->getTraceAsString()];
        $previous = $e->getPrevious();
        if ($previous instanceof \Throwable && $previous->getMessage() !== '') {
            $details['output'] = $previous->getMessage();
        }
        return $details;
    }

    private function hasPayload(mixed $data): bool
    {
        return is_array($data) && isset($data['payload']) && is_array($data['payload']);
    }

    private function deriveNameFromEmail(string $email): string
    {
        $parts = explode('@', $email);
        $username = $parts[0] ?? '';
        $cleaned = preg_replace('/[^a-zA-Z0-9]/', '', $username);
        return ucfirst($cleaned);
    }
}

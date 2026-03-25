<?php

namespace Appwrite\Platform\Installer\Http\Installer;

use Appwrite\Platform\Installer\Runtime\State;
use Utopia\Http\Adapter\Swoole\Response;
use Utopia\Platform\Action;
use Utopia\Validator\Text;

class Status extends Action
{
    public static function getName(): string
    {
        return 'installerStatus';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_GET)
            ->setHttpPath('/install/status')
            ->desc('Poll installation progress')
            ->param('installId', '', new Text(64, 0), 'Installation ID', true)
            ->inject('response')
            ->inject('installerState')
            ->callback($this->action(...));
    }

    public function action(string $installId, Response $response, State $state): void
    {
        $state->clearStaleLockIfNeeded();

        $installId = $state->sanitizeInstallId($installId);
        if ($installId === '') {
            $response->setStatusCode(Response::STATUS_CODE_BAD_REQUEST);
            $response->json(['success' => false, 'message' => 'Missing installId']);
            return;
        }

        $path = $state->progressFilePath($installId);
        if (!file_exists($path)) {
            $response->setStatusCode(Response::STATUS_CODE_NOT_FOUND);
            $response->json(['success' => false, 'message' => 'Install not found']);
            return;
        }

        $data = $state->readProgressFile($installId);
        if (is_array($data) && isset($data['payload']) && is_array($data['payload'])) {
            unset(
                $data['payload']['opensslKey'],
                $data['payload']['assistantOpenAIKey'],
                $data['payload']['opensslKeyHash'],
                $data['payload']['assistantOpenAIKeyHash'],
            );
        }
        // Strip sensitive data from step details
        if (is_array($data) && isset($data['details']) && is_array($data['details'])) {
            foreach ($data['details'] as $stepKey => &$stepDetails) {
                if (is_array($stepDetails)) {
                    unset($stepDetails['sessionSecret'], $stepDetails['trace']);
                }
            }
            unset($stepDetails);
        }
        $response->json(['success' => true, 'progress' => $data]);
    }
}

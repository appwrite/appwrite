<?php

namespace Appwrite\Platform\Installer\Http\Installer;

use Appwrite\Platform\Installer\Runtime\Config;
use Appwrite\Platform\Installer\Runtime\State;
use Appwrite\Platform\Installer\Server;
use Utopia\Http\Adapter\Swoole\Request;
use Utopia\Http\Adapter\Swoole\Response;
use Utopia\Platform\Action;
use Utopia\Validator\Boolean;
use Utopia\Validator\Text;

class Reset extends Action
{
    public static function getName(): string
    {
        return 'installerReset';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/install/reset')
            ->desc('Reset installation state')
            ->param('installId', '', new Text(64, 0), 'Installation ID', true)
            ->param('hard', false, new Boolean(true), 'Remove all data including volumes and config files', true)
            ->inject('request')
            ->inject('response')
            ->inject('installerState')
            ->inject('installerConfig')
            ->callback($this->action(...));
    }

    public function action(string $installId, bool $hard, Request $request, Response $response, State $state, Config $config): void
    {
        if (!Validate::validateCsrf($request)) {
            $response->setStatusCode(Response::STATUS_CODE_BAD_REQUEST);
            $response->json(['success' => false, 'message' => 'Invalid CSRF token']);
            return;
        }

        $installId = $state->sanitizeInstallId($installId);

        if ($installId !== '') {
            @unlink($state->progressFilePath($installId));
            $state->updateGlobalLock($installId, Server::STATUS_COMPLETED);
        }

        // Use direct clearStaleLock (not throttled) since reset is an
        // explicit user action that should guarantee all stale state is gone.
        $state->clearStaleLock();

        if ($hard) {
            $error = $this->performHardReset($config);
            if ($error !== null) {
                $response->setStatusCode(Response::STATUS_CODE_INTERNAL_SERVER_ERROR);
                $response->json(['success' => false, 'message' => $error]);
                return;
            }
        }

        $response->json(['success' => true]);
    }

    private function performHardReset(Config $config): ?string
    {
        $isLocal = $config->isLocal();
        $composeFileName = $isLocal ? 'docker-compose.web-installer.yml' : 'docker-compose.yml';
        $envFileName = $isLocal ? '.env.web-installer' : '.env';
        $path = $isLocal ? '/usr/src/code' : '/usr/src/code/appwrite';

        $composeFile = $path . '/' . $composeFileName;

        if (file_exists($composeFile)) {
            $command = array_map(escapeshellarg(...), [
                'docker', 'compose',
                '-f', $composeFile,
                ...($isLocal ? ['--project-name', 'appwrite'] : []),
                '--project-directory', $path,
                'down', '-v', '--remove-orphans',
            ]);

            $output = [];
            @exec(implode(' ', $command) . ' 2>&1', $output, $exitCode);

            if ($exitCode !== 0) {
                return 'Failed to stop containers: ' . trim(implode("\n", $output));
            }

            @unlink($composeFile);
        }

        $envFile = $path . '/' . $envFileName;
        if (file_exists($envFile)) {
            @unlink($envFile);
        }

        @unlink(Server::INSTALLER_CONFIG_FILE);
        @unlink(Server::INSTALLER_LOCK_FILE);

        $tempDir = sys_get_temp_dir();
        foreach ((array) glob($tempDir . '/appwrite-install-*.json') as $file) {
            @unlink($file);
        }

        return null;
    }
}

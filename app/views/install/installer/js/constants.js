(() => {
    window.InstallerConstants = Object.freeze({
        stepTransitionMs: 260,
        errorClearMs: 180,
        installPollIntervalMs: 4000,
        installFallbackDelayMs: 12000,
        mockStepDelayMs: 1800,
        progressTransitionDelayMs: 200,
        mockErrorDetails: {
            output: [
                'Failed to start containers: appwrite-worker-webhooks',
                'Pulling appwrite-worker-databases',
                'Pulling appwrite-worker-audits',
                'Error response from daemon: manifest for appwrite/appwrite:local not found: manifest unknown',
                'appwrite-worker-webhooks Error context canceled',
                'appwrite-worker-databases Error context canceled'
            ].join('\n'),
            trace: [
                '#0 /usr/src/code/src/Appwrite/Platform/Tasks/Install.php(540): Appwrite\\\\Platform\\\\Tasks\\\\Install->performInstallation(...)',
                '#1 /usr/src/code/src/Appwrite/Platform/Tasks/Install.php(910): Appwrite\\\\Platform\\\\Tasks\\\\Install->startWebServer(...)',
                '#2 {main}'
            ].join('\n')
        }
    });
})();

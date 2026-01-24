(() => {
    const getBodyDataset = () => document.body?.dataset ?? {};
    const isUpgradeMode = () => getBodyDataset().upgrade === 'true';
    const getLockedDatabase = () => getBodyDataset().lockedDatabase || '';
    const isMockMode = () => document.body?.dataset.installMode === 'mock'
        || (typeof navigator !== 'undefined' && navigator.webdriver);
    const isMockErrorMode = () => {
        if (typeof window === 'undefined') return false;
        const params = new URLSearchParams(window.location.search);
        return params.get('mock-error') === '1';
    };
    const getMockToastParam = () => {
        if (typeof window === 'undefined') return false;
        const params = new URLSearchParams(window.location.search);
        return params.get('mock-toast') === '1';
    };
    const MOCK_TOAST_ENABLED = getMockToastParam();
    const isMockToastMode = () => MOCK_TOAST_ENABLED;
    const isMockProgressMode = () => isMockMode() || isMockErrorMode();

    const STEP_IDS = Object.freeze({
        CONFIG_FILES: 'config-files',
        DOCKER_COMPOSE: 'docker-compose',
        ENV_VARS: 'env-vars',
        DOCKER_CONTAINERS: 'docker-containers'
    });

    const STATUS = Object.freeze({
        IN_PROGRESS: 'in-progress',
        COMPLETED: 'completed',
        ERROR: 'error'
    });

    const SSE_EVENTS = Object.freeze({
        PING: 'ping',
        INSTALL_ID: 'install-id',
        PROGRESS: 'progress',
        DONE: 'done',
        ERROR: 'error'
    });

    const buildInstallationSteps = (upgrade) => (upgrade ? [
        {
            id: STEP_IDS.CONFIG_FILES,
            inProgress: 'Updating configuration files...',
            done: 'Configuration files updated'
        },
        {
            id: STEP_IDS.DOCKER_COMPOSE,
            inProgress: 'Updating Docker Compose file...',
            done: 'Docker Compose file updated'
        },
        {
            id: STEP_IDS.ENV_VARS,
            inProgress: 'Updating environment variables...',
            done: 'Environment variables updated'
        },
        {
            id: STEP_IDS.DOCKER_CONTAINERS,
            inProgress: 'Restarting Docker containers...',
            done: 'Docker containers restarted'
        }
    ] : [
        {
            id: STEP_IDS.CONFIG_FILES,
            inProgress: 'Creating configuration files...',
            done: 'Configuration files created'
        },
        {
            id: STEP_IDS.DOCKER_COMPOSE,
            inProgress: 'Generating Docker Compose file...',
            done: 'Docker Compose file generated'
        },
        {
            id: STEP_IDS.ENV_VARS,
            inProgress: 'Configuring environment variables...',
            done: 'Environment variables configured'
        },
        {
            id: STEP_IDS.DOCKER_CONTAINERS,
            inProgress: 'Starting Docker containers...',
            done: 'Docker containers started'
        }
    ]);

    const INSTALLATION_STEPS = buildInstallationSteps(isUpgradeMode());
    const CONSTANTS = window.InstallerConstants || {};
    const TIMINGS = {
        errorClear: CONSTANTS.errorClearMs ?? 180,
        installPollInterval: CONSTANTS.installPollIntervalMs ?? 4000,
        installFallbackDelay: CONSTANTS.installFallbackDelayMs ?? 12000,
        redirectDelay: CONSTANTS.redirectDelayMs ?? 500,
        mockStepDelay: CONSTANTS.mockStepDelayMs ?? 1800,
        progressTransitionDelay: CONSTANTS.progressTransitionDelayMs ?? 140,
        progressCompleteDelay: CONSTANTS.progressCompleteDelayMs ?? 120
    };

    const clampStep = (step) => {
        const numeric = Number(step);
        if (Number.isNaN(numeric)) return 1;
        return Math.max(1, Math.min(4, numeric));
    };

    window.InstallerStepsContext = Object.freeze({
        getBodyDataset,
        isUpgradeMode,
        getLockedDatabase,
        isMockMode,
        isMockErrorMode,
        isMockToastMode,
        isMockProgressMode,
        STEP_IDS,
        STATUS,
        SSE_EVENTS,
        INSTALLATION_STEPS,
        TIMINGS,
        clampStep
    });
})();

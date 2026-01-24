(() => {
    const Context = window.InstallerStepsContext || {};
    const State = window.InstallerStepsState || {};
    const Validation = window.InstallerStepsValidation || {};
    const UI = window.InstallerStepsUI || {};
    const Progress = window.InstallerStepsProgress || {};
    const Tooltips = window.InstallerTooltips || null;

    const {
        INSTALLATION_STEPS,
        clampStep,
        isMockMode
    } = Context;

    const {
        formState,
        dispatchStateChange,
        applyBodyDefaults,
        applyLockPayload,
        clearInstallLock,
        clearInstallId,
        isInstallLocked,
        syncInstallLockFlag,
        getInstallLock,
        getLockedDatabase
    } = State;

    const {
        isValidEmail,
        isValidPort,
        isValidHostnameInput
    } = Validation;

    const {
        clearFieldErrors,
        setFieldError,
        bindErrorClear,
        updateDatabaseSelection,
        setupResetButtons,
        setupAccordion,
        openAccordion,
        disableControls,
        generateSecretKey,
        copyToClipboard,
        setTooltipText,
        resetTooltipText,
        updateReviewSummary
    } = UI;

    let reviewListener = null;

    const bindInputToState = (input, key) => {
        if (!input) return;
        const update = () => {
            formState[key] = input.value;
            dispatchStateChange?.(key);
        };
        input.addEventListener('input', update);
        input.addEventListener('change', update);
        update();
    };

    const lockDatabaseSelection = (root, lockedDatabase) => {
        if (lockedDatabase) {
            const radios = root.querySelectorAll('input[name="database"]');
            radios.forEach((radio) => {
                const isLockedChoice = radio.value === lockedDatabase;
                const card = radio.closest('.selector-card');
                radio.disabled = !isLockedChoice;
                if (card) {
                    card.classList.toggle('is-disabled', !isLockedChoice);
                }
                if (isLockedChoice) {
                    radio.checked = true;
                    updateDatabaseSelection?.(radio, root);
                }
            });
        }
    };

    const bindDatabaseSelection = (root) => {
        const radios = root.querySelectorAll('input[name="database"]');
        radios.forEach((radio) => {
            radio.addEventListener('change', () => {
                formState.database = radio.value;
                updateDatabaseSelection?.(radio, root);
            });
        });
    };

    const hydrateStep1State = (root) => {
        State.setStateIfEmpty?.('appDomain', root.querySelector('#hostname')?.value);
        State.setStateIfEmpty?.('database', root.querySelector('input[name="database"]:checked')?.value);
        State.setStateIfEmpty?.('httpPort', root.querySelector('#http-port')?.value);
        State.setStateIfEmpty?.('httpsPort', root.querySelector('#https-port')?.value);
        State.setStateIfEmpty?.('emailCertificates', root.querySelector('#ssl-email')?.value);
        State.setStateIfEmpty?.('assistantOpenAIKey', root.querySelector('#assistant-openai-key')?.value);
    };

    const applyStep1State = (root) => {
        const hostname = root.querySelector('#hostname');
        if (hostname && formState.appDomain) hostname.value = formState.appDomain;

        const httpPort = root.querySelector('#http-port');
        if (httpPort && formState.httpPort) httpPort.value = formState.httpPort;

        const httpsPort = root.querySelector('#https-port');
        if (httpsPort && formState.httpsPort) httpsPort.value = formState.httpsPort;

        const sslEmail = root.querySelector('#ssl-email');
        if (sslEmail && formState.emailCertificates) sslEmail.value = formState.emailCertificates;

        const assistantKey = root.querySelector('#assistant-openai-key');
        if (assistantKey && formState.assistantOpenAIKey) {
            assistantKey.value = formState.assistantOpenAIKey;
        }

        if (formState.database) {
            const radio = root.querySelector(`input[name="database"][value="${formState.database}"]`);
            if (radio) {
                radio.checked = true;
                updateDatabaseSelection?.(radio, root);
            }
        }
    };

    const initStep1 = (root) => {
        if (!root) return;
        if (isMockMode?.()) {
            clearInstallLock?.();
            clearInstallId?.();
        }
        syncInstallLockFlag?.();
        applyLockPayload?.();
        applyBodyDefaults?.();
        hydrateStep1State(root);
        applyStep1State(root);

        if (isInstallLocked?.()) {
            openAccordion?.(root);
            disableControls?.(root);
            return;
        }

        const lockedDatabase = getLockedDatabase?.() || '';
        if (lockedDatabase) {
            lockDatabaseSelection(root, lockedDatabase);
        } else {
            bindDatabaseSelection(root);
        }

        const hostname = root.querySelector('#hostname');
        const httpPort = root.querySelector('#http-port');
        const httpsPort = root.querySelector('#https-port');
        const sslEmail = root.querySelector('#ssl-email');
        const assistantKey = root.querySelector('#assistant-openai-key');

        bindInputToState(hostname, 'appDomain');
        bindInputToState(httpPort, 'httpPort');
        bindInputToState(httpsPort, 'httpsPort');
        bindInputToState(sslEmail, 'emailCertificates');
        bindInputToState(assistantKey, 'assistantOpenAIKey');

        bindErrorClear?.(hostname);
        bindErrorClear?.(httpPort);
        bindErrorClear?.(httpsPort);
        bindErrorClear?.(sslEmail);
        bindErrorClear?.(assistantKey);

        const checked = root.querySelector('input[name="database"]:checked');
        if (checked) {
            updateDatabaseSelection?.(checked, root);
        }

        setupResetButtons?.(root);
        setupAccordion?.(root);
        Tooltips?.setupTooltipPortals?.(root);
    };

    const hydrateStep2State = (root) => {
        const value = root.querySelector('#secret-key')?.value;
        if (formState.opensslKey) return;
        if (value) {
            formState.opensslKey = value;
        }
    };

    const applyStep2State = (root) => {
        const input = root.querySelector('#secret-key');
        if (input && formState.opensslKey) {
            input.value = formState.opensslKey;
        }
    };

    const initStep2 = (root) => {
        if (!root) return;
        if (isMockMode?.()) {
            clearInstallLock?.();
            clearInstallId?.();
        }
        syncInstallLockFlag?.();
        applyLockPayload?.();
        applyBodyDefaults?.();
        hydrateStep2State(root);
        if (!formState.opensslKey || !formState.opensslKey.trim()) {
            formState.opensslKey = generateSecretKey?.();
            dispatchStateChange?.('opensslKey');
        }
        applyStep2State(root);

        const input = root.querySelector('#secret-key');
        if (input) {
            bindInputToState(input, 'opensslKey');
            bindErrorClear?.(input);
        }

        const copyButton = root.querySelector('[data-copy-target]');
        const tooltipWrapper = copyButton?.closest('.tooltip-wrapper');

        if (tooltipWrapper) {
            tooltipWrapper.addEventListener('mouseenter', () => resetTooltipText?.(tooltipWrapper));
            tooltipWrapper.addEventListener('focusin', () => resetTooltipText?.(tooltipWrapper));
        }

        if (copyButton) {
            copyButton.addEventListener('click', () => {
                const targetId = copyButton.getAttribute('data-copy-target');
                const targetInput = targetId ? root.querySelector(`#${targetId}`) : null;
                const value = targetInput?.value || '';
                copyToClipboard?.(value, targetInput);
                copyButton.blur();

                if (tooltipWrapper) {
                    const successText = tooltipWrapper.dataset.tooltipSuccess || 'Copied';
                    setTooltipText?.(tooltipWrapper, successText);
                }
            });
        }

        const regenerateButton = root.querySelector('[data-regenerate-target]');
        if (regenerateButton && !isInstallLocked?.()) {
            regenerateButton.addEventListener('click', () => {
                const targetId = regenerateButton.getAttribute('data-regenerate-target');
                const targetInput = targetId ? root.querySelector(`#${targetId}`) : null;
                if (!targetInput) return;
                regenerateButton.classList.remove('is-rotating');
                void regenerateButton.offsetWidth;
                regenerateButton.classList.add('is-rotating');
                const handleAnimationEnd = () => {
                    regenerateButton.classList.remove('is-rotating');
                };
                regenerateButton.addEventListener('animationend', handleAnimationEnd, { once: true });
                targetInput.value = generateSecretKey?.();
                targetInput.dispatchEvent(new Event('input', { bubbles: true }));
            });
        }

        if (isInstallLocked?.()) {
            disableControls?.(root);
        }
    };

    const initStep3 = (root) => {
        if (!root) return;
        if (isMockMode?.()) {
            clearInstallLock?.();
            clearInstallId?.();
        }
        syncInstallLockFlag?.();
        applyLockPayload?.();
        applyBodyDefaults?.();
        updateReviewSummary?.(root);
        if (reviewListener) {
            document.removeEventListener('installer:state-change', reviewListener);
        }
        reviewListener = () => updateReviewSummary?.(root);
        document.addEventListener('installer:state-change', reviewListener);
        if (isInstallLocked?.()) {
            disableControls?.(root);
        }
    };

    const initStep = (step, container) => {
        if (!container) return;
        const root = container.querySelector('.step-layout') || container;
        const normalized = clampStep?.(step) ?? 1;
        Tooltips?.cleanupTooltipPortals?.();
        if (normalized !== 3 && reviewListener) {
            document.removeEventListener('installer:state-change', reviewListener);
            reviewListener = null;
        }
        if (normalized !== 4) {
            Progress.cleanupInstallFlow?.();
        }
        if (normalized === 1) initStep1(root);
        if (normalized === 2) initStep2(root);
        if (normalized === 3) initStep3(root);
        if (normalized === 4) Progress.initStep4?.(root);
    };

    window.InstallerSteps = {
        initStep1,
        initStep2,
        initStep3,
        initStep4: Progress.initStep4,
        installationSteps: INSTALLATION_STEPS || [],
        isInstallLocked,
        getInstallLock,
        clearInstallLock,
        initStep,
        validateStep: (step, container) => {
            const root = container?.querySelector('.step-layout') || container;
            const normalized = clampStep?.(step) ?? 1;
            if (normalized === 1) {
                clearFieldErrors?.(root);
                let valid = true;
                const hostname = root?.querySelector('#hostname');
                const httpPort = root?.querySelector('#http-port');
                const httpsPort = root?.querySelector('#https-port');
                const sslEmail = root?.querySelector('#ssl-email');

                if (!hostname || !hostname.value.trim()) {
                    setFieldError?.(hostname, 'Please enter your Appwrite hostname');
                    valid = false;
                } else if (!isValidHostnameInput?.(hostname.value.trim())) {
                    setFieldError?.(hostname, 'Please enter a valid hostname');
                    valid = false;
                }

                const parsePort = (input, label) => {
                    const value = input?.value;
                    if (!value || !isValidPort?.(value)) {
                        setFieldError?.(input, `Please enter a valid ${label} port (1-65535)`);
                        return false;
                    }
                    return true;
                };

                if (!parsePort(httpPort, 'HTTP')) valid = false;
                if (!parsePort(httpsPort, 'HTTPS')) valid = false;

                if (!sslEmail || !sslEmail.value.trim()) {
                    setFieldError?.(sslEmail, 'Please enter an email address for SSL certificates');
                    valid = false;
                } else if (!isValidEmail?.(sslEmail.value.trim())) {
                    setFieldError?.(sslEmail, 'Please enter a valid email address');
                    valid = false;
                }

                if (!valid) {
                    openAccordion?.(root);
                }

                return valid;
            }

            if (normalized === 2) {
                clearFieldErrors?.(root);
                const secretKey = root?.querySelector('#secret-key');
                const secretValue = secretKey?.value.trim() || '';
                if (!secretKey || !secretValue) {
                    setFieldError?.(secretKey, 'Please enter or generate a secret API key');
                    return false;
                }
                if (secretValue.length > 64) {
                    setFieldError?.(secretKey, 'Secret API key must be 1-64 characters');
                    return false;
                }
            }

            return true;
        }
    };
})();

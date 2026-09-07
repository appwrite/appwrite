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
        isUpgradeMode,
        getEnabledDatabases,
        getTopology
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
        isValidHostnameInput,
        isValidPassword
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

    const bindCheckboxToState = (input, key) => {
        if (!input) return;
        const update = () => {
            formState[key] = input.checked;
            dispatchStateChange?.(key);
        };
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

    const applyEnabledDatabases = (root) => {
        const enabled = getEnabledDatabases?.() || [];
        const radios = root.querySelectorAll('input[name="database"]');
        radios.forEach((radio) => {
            if (!enabled.includes(radio.value)) {
                const card = radio.closest('.selector-card');
                if (card) {
                    card.remove();
                }
            }
        });
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

    // A hostname that no public certificate authority will issue for: loopback names,
    // .local/.internal names, and bare IP literals. Those are served over plain HTTP, so
    // the toggle follows the hostname instead of making the operator know this.
    const servedOverPlainHttp = (value) => {
        const host = (value || '').trim().toLowerCase().replace(/^\[|\]$/g, '');

        if (host === '') return true;
        if (host === 'localhost' || host.endsWith('.localhost')) return true;
        if (host.endsWith('.local') || host.endsWith('.internal')) return true;
        if (host === '::1' || host === '0.0.0.0') return true;
        if (/^\d{1,3}(\.\d{1,3}){3}$/.test(host)) return true;
        if (host.includes(':') && /^[0-9a-f:]+$/.test(host)) return true;

        return false;
    };

    const hydrateStep1State = (root) => {
        State.setStateIfEmpty?.('appDomain', root.querySelector('#hostname')?.value);
        State.setStateIfEmpty?.('database', root.querySelector('input[name="database"]:checked')?.value);
        State.setStateIfEmpty?.('topology', getTopology?.() || root.querySelector('input[name="topology"]:checked')?.value || 'combined');
        State.setStateIfEmpty?.('httpPort', root.querySelector('#http-port')?.value);
        State.setStateIfEmpty?.('httpsPort', root.querySelector('#https-port')?.value);
        State.setStateIfEmpty?.('emailCertificates', root.querySelector('#ssl-email')?.value);
        State.setStateIfEmpty?.('forceHttps', root.querySelector('#force-https')?.checked);
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

        const forceHttps = root.querySelector('#force-https');
        if (forceHttps && typeof formState.forceHttps === 'boolean') {
            forceHttps.checked = formState.forceHttps;
        }

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

        if (formState.topology) {
            const radio = root.querySelector(`input[name="topology"][value="${formState.topology}"]`);
            if (radio) {
                radio.checked = true;
                const group = radio.closest('.selector-group');
                group?.querySelectorAll('.selector-card').forEach((card) => card.classList.remove('selected'));
                radio.closest('.selector-card')?.classList.add('selected');
            }
        }
    };

    const initStep1 = (root) => {
        if (!root) return;
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

        applyEnabledDatabases(root);

        const lockedDatabase = getLockedDatabase?.() || '';
        if (lockedDatabase) {
            lockDatabaseSelection(root, lockedDatabase);
        } else {
            bindDatabaseSelection(root);
        }

        const topologyRadios = root.querySelectorAll('input[name="topology"]');
        topologyRadios.forEach((radio) => {
            radio.addEventListener('change', () => {
                formState.topology = radio.value;
                const group = radio.closest('.selector-group');
                group?.querySelectorAll('.selector-card').forEach((card) => card.classList.remove('selected'));
                radio.closest('.selector-card')?.classList.add('selected');
            });
        });

        const hostname = root.querySelector('#hostname');
        const httpPort = root.querySelector('#http-port');
        const httpsPort = root.querySelector('#https-port');
        const sslEmail = root.querySelector('#ssl-email');
        const forceHttps = root.querySelector('#force-https');
        const assistantKey = root.querySelector('#assistant-openai-key');

        bindInputToState(hostname, 'appDomain');

        // Follow the hostname until the operator sets the toggle themselves, after which
        // their choice stands however the hostname changes.
        if (hostname && forceHttps) {
            const followHostname = () => {
                if (forceHttps.dataset.touched === 'true') return;
                const https = !servedOverPlainHttp(hostname.value);
                forceHttps.checked = https;
                formState.forceHttps = https;
            };

            forceHttps.addEventListener('change', () => {
                forceHttps.dataset.touched = 'true';
            });
            hostname.addEventListener('input', followHostname);
            followHostname();
        }

        bindInputToState(httpPort, 'httpPort');
        bindInputToState(httpsPort, 'httpsPort');
        bindInputToState(sslEmail, 'emailCertificates');
        bindCheckboxToState(forceHttps, 'forceHttps');
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
        syncInstallLockFlag?.();
        applyLockPayload?.();
        applyBodyDefaults?.();
        hydrateStep2State(root);
        if (!isUpgradeMode?.() && (!formState.opensslKey || !formState.opensslKey.trim())) {
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

    const hydrateStep3State = (root) => {
        State.setStateIfEmpty?.('accountName', root.querySelector('#account-name')?.value);
        State.setStateIfEmpty?.('accountEmail', root.querySelector('#account-email')?.value);
        State.setStateIfEmpty?.('accountPassword', root.querySelector('#account-password')?.value);
    };

    const applyStep3State = (root) => {
        const accountName = root.querySelector('#account-name');
        if (accountName && formState.accountName) accountName.value = formState.accountName;

        const email = root.querySelector('#account-email');
        if (email && formState.accountEmail) email.value = formState.accountEmail;

        const password = root.querySelector('#account-password');
        if (password && formState.accountPassword) password.value = formState.accountPassword;
    };

    const initStep3 = (root) => {
        if (!root) return;
        syncInstallLockFlag?.();
        applyLockPayload?.();
        applyBodyDefaults?.();
        hydrateStep3State(root);
        applyStep3State(root);

        const accountName = root.querySelector('#account-name');
        const email = root.querySelector('#account-email');
        const password = root.querySelector('#account-password');
        const passwordToggle = root.querySelector('[data-password-toggle="account-password"]');

        bindInputToState(accountName, 'accountName');
        bindInputToState(email, 'accountEmail');
        bindInputToState(password, 'accountPassword');

        bindErrorClear?.(email);
        bindErrorClear?.(password);

        if (password && passwordToggle) {
            passwordToggle.addEventListener('click', () => {
                const isVisible = passwordToggle.classList.toggle('is-visible');
                password.type = isVisible ? 'text' : 'password';
                passwordToggle.setAttribute('aria-label', isVisible ? 'Hide password' : 'Show password');
            });
        }

        if (isInstallLocked?.()) {
            disableControls?.(root);
        }
    };

    const initStep4 = (root) => {
        if (!root) return;
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

    const initStep6 = (root) => {
        if (!root) return;
        syncInstallLockFlag?.();
        applyLockPayload?.();
        applyBodyDefaults?.();

        const checkbox = root.querySelector('#run-migration');
        if (checkbox) {
            if (formState.migrate !== undefined) {
                checkbox.checked = formState.migrate;
            } else {
                formState.migrate = checkbox.checked;
            }
            checkbox.addEventListener('change', () => {
                formState.migrate = checkbox.checked;
                dispatchStateChange?.('migrate');
            });
        }

        if (isInstallLocked?.()) {
            disableControls?.(root);
        }
    };

    const initStep = (step, container) => {
        if (!container) return;
        const root = container.querySelector('.step-layout') || container;
        const normalized = clampStep?.(step) ?? 1;
        Tooltips?.cleanupTooltipPortals?.();
        if (normalized !== 4 && reviewListener) {
            document.removeEventListener('installer:state-change', reviewListener);
            reviewListener = null;
        }
        if (normalized !== 5) {
            Progress.cleanupInstallFlow?.();
        }
        if (normalized === 1) initStep1(root);
        if (normalized === 2) initStep2(root);
        if (normalized === 3) initStep3(root);
        if (normalized === 4) initStep4(root);
        if (normalized === 5) Progress.initStep5?.(root);
        if (normalized === 6) initStep6(root);
    };

    window.InstallerSteps = {
        initStep1,
        initStep2,
        initStep3,
        initStep4,
        initStep5: Progress.initStep5,
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

                if (sslEmail && sslEmail.value.trim() && !isValidEmail?.(sslEmail.value.trim())) {
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
                    setFieldError?.(secretKey, 'Please enter or generate a secret key');
                    return false;
                }
                if (secretValue.length > 64) {
                    setFieldError?.(secretKey, 'Secret key must be 1-64 characters');
                    return false;
                }
            }

            if (normalized === 3) {
                clearFieldErrors?.(root);
                let valid = true;
                const email = root?.querySelector('#account-email');
                const password = root?.querySelector('#account-password');
                const emailValue = email?.value.trim() ?? '';
                const passwordValue = password?.value ?? '';

                // The account is optional -- the installer skips creating one when either
                // field is blank, and it can be created from the console afterwards. Half
                // an account is still an error, since that reads as an attempt to make one.
                if (emailValue === '' && passwordValue === '') {
                    return true;
                }

                if (emailValue === '') {
                    setFieldError?.(email, 'This field is required');
                    valid = false;
                } else if (!isValidEmail?.(emailValue)) {
                    setFieldError?.(email, 'Please enter a valid email address');
                    valid = false;
                }

                if (!/\S/.test(passwordValue)) {
                    setFieldError?.(password, 'This field is required');
                    valid = false;
                } else if (!isValidPassword?.(passwordValue)) {
                    setFieldError?.(password, 'Password must be at least 8 characters long');
                    valid = false;
                }

                return valid;
            }

            return true;
        }
    };
})();

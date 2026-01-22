(() => {
    const INSTALL_LOCK_KEY = 'appwrite-install-lock';
    const INSTALL_ID_KEY = 'appwrite-install-id';
    const isMockMode = () => document.body?.dataset.installMode === 'mock'
        || (typeof navigator !== 'undefined' && navigator.webdriver);
    const getBodyDataset = () => document.body?.dataset ?? {};
    const isUpgradeMode = () => getBodyDataset().upgrade === 'true';
    const getLockedDatabase = () => getBodyDataset().lockedDatabase || '';
    const isMockErrorMode = () => {
        if (typeof window === 'undefined') return false;
        const params = new URLSearchParams(window.location.search);
        return params.get('mock-error') === '1';
    };
    const isMockProgressMode = () => isMockMode() || isMockErrorMode();

    const buildInstallationSteps = (upgrade) => (upgrade ? [
        {
            id: 'config-files',
            inProgress: 'Updating configuration files...',
            done: 'Configuration files updated'
        },
        {
            id: 'docker-compose',
            inProgress: 'Updating Docker Compose file...',
            done: 'Docker Compose file updated'
        },
        {
            id: 'env-vars',
            inProgress: 'Updating environment variables...',
            done: 'Environment variables updated'
        },
        {
            id: 'docker-containers',
            inProgress: 'Restarting Docker containers...',
            done: 'Docker containers restarted'
        }
    ] : [
        {
            id: 'config-files',
            inProgress: 'Creating configuration files...',
            done: 'Configuration files created'
        },
        {
            id: 'docker-compose',
            inProgress: 'Generating Docker Compose file...',
            done: 'Docker Compose file generated'
        },
        {
            id: 'env-vars',
            inProgress: 'Configuring environment variables...',
            done: 'Environment variables configured'
        },
        {
            id: 'docker-containers',
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

    const formState = {
        appDomain: null,
        database: null,
        httpPort: null,
        httpsPort: null,
        emailCertificates: null,
        opensslKey: null,
        assistantOpenAIKey: null
    };

    let activeInstall = null;
    let unloadGuard = null;
    let reviewListener = null;

    const dispatchStateChange = (key) => {
        if (!key || typeof document === 'undefined') return;
        try {
            document.dispatchEvent(new CustomEvent('installer:state-change', {
                detail: { key, value: formState[key] }
            }));
        } catch (error) {}
    };

    const clampStep = (step) => {
        const numeric = Number(step);
        if (Number.isNaN(numeric)) return 1;
        return Math.max(1, Math.min(4, numeric));
    };

    const getInstallLock = () => {
        try {
            const raw = sessionStorage.getItem(INSTALL_LOCK_KEY);
            if (!raw) return null;
            const parsed = JSON.parse(raw);
            if (!parsed || typeof parsed !== 'object') return null;
            return parsed;
        } catch (error) {
            return null;
        }
    };

    const setInstallLock = (installId, payload) => {
        const lock = {
            installId,
            payload: payload || null,
            startedAt: Date.now()
        };
        try {
            sessionStorage.setItem(INSTALL_LOCK_KEY, JSON.stringify(lock));
        } catch (error) {}
        if (document.body) {
            document.body.dataset.installLocked = 'true';
        }
        return lock;
    };

    const clearInstallLock = () => {
        try {
            sessionStorage.removeItem(INSTALL_LOCK_KEY);
        } catch (error) {}
        if (document.body) {
            delete document.body.dataset.installLocked;
        }
    };

    const isInstallLocked = () => {
        if (isMockProgressMode()) return false;
        return Boolean(getInstallLock());
    };

    const syncInstallLockFlag = () => {
        if (!document.body) return;
        if (isInstallLocked()) {
            document.body.dataset.installLocked = 'true';
        } else {
            delete document.body.dataset.installLocked;
        }
    };

    const getStepDefinition = (id) => INSTALLATION_STEPS.find((step) => step.id === id);

    const isValidEmail = (email) => {
        if (!email) return false;
        const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return re.test(email);
    };

    const isValidPort = (value) => {
        const numeric = Number(value);
        if (!Number.isInteger(numeric)) return false;
        return numeric >= 1 && numeric <= 65535;
    };

    const isValidIPv4 = (host) => {
        if (!/^\d{1,3}(\.\d{1,3}){3}$/.test(host)) return false;
        return host.split('.').every((part) => {
            const num = Number(part);
            return num >= 0 && num <= 255;
        });
    };

    const isValidIPv6 = (host) => {
        try {
            const url = new URL(`http://[${host}]`);
            return url.hostname.toLowerCase() === host.toLowerCase();
        } catch (error) {
            return false;
        }
    };

    const isValidHostnameLabel = (label) => {
        if (!label || label.length > 63) return false;
        if (label.startsWith('-') || label.endsWith('-')) return false;
        return /^[a-zA-Z0-9-]+$/.test(label);
    };

    const isValidDomain = (host) => {
        if (host.length > 253) return false;
        const labels = host.split('.');
        return labels.every((label) => isValidHostnameLabel(label));
    };

    const isValidHost = (host) => {
        if (host === 'localhost') return true;
        if (isValidIPv4(host)) return true;
        if (isValidIPv6(host)) return true;
        return isValidDomain(host);
    };

    const isValidHostnameInput = (value) => {
        if (!value) return false;
        const trimmed = value.trim();
        if (!trimmed) return false;

        let host = trimmed;
        let port = null;

        if (trimmed.startsWith('[')) {
            const match = trimmed.match(/^\[([^\]]+)\](?::(\d+))?$/);
            if (!match) return false;
            host = match[1] || '';
            port = match[2] || null;
        } else {
            const parts = trimmed.split(':');
            if (parts.length > 2) return false;
            if (parts.length === 2) {
                host = parts[0];
                port = parts[1];
            }
        }

        if (port !== null && port !== '' && !isValidPort(port)) {
            return false;
        }

        return isValidHost(host);
    };

    const clearFieldErrors = (root) => {
        if (!root) return;
        root.querySelectorAll('.field-error').forEach((node) => node.remove());
        root.querySelectorAll('.input-field.is-error, .input-action.is-error').forEach((node) => {
            node.classList.remove('is-error');
        });
    };

    const setFieldError = (input, message) => {
        if (!input) return;
        const group = input.closest('.input-group');
        if (!group) return;
        let error = group.querySelector('.field-error');
        let errorText = error?.querySelector('.field-error-text');
        const hasSameMessage = Boolean(errorText && errorText.textContent === message);
        const alreadyVisible = Boolean(error && error.classList.contains('is-visible'));
        if (!error) {
            const template = document.getElementById('field-error-template');
            if (template && template.content) {
                const fragment = template.content.cloneNode(true);
                error = fragment.querySelector('.field-error');
                group.appendChild(fragment);
            } else {
                error = document.createElement('div');
                error.className = 'field-error typography-text-xs-400 text-error';
                const text = document.createElement('span');
                text.className = 'field-error-text';
                error.appendChild(text);
                group.appendChild(error);
            }
            errorText = error.querySelector('.field-error-text');
        }
        if (errorText) {
            errorText.textContent = message;
        }
        if (!hasSameMessage || !alreadyVisible) {
            requestAnimationFrame(() => {
                error.classList.add('is-visible');
            });
        }
        input.classList.add('is-error');
        const actionWrapper = input.closest('.input-action');
        if (actionWrapper) {
            actionWrapper.classList.add('is-error');
        }
    };

    const bindErrorClear = (input) => {
        if (!input) return;
        const handler = () => {
            const group = input.closest('.input-group');
            const error = group?.querySelector('.field-error');
            if (error) {
                error.classList.remove('is-visible');
                setTimeout(() => {
                    error.remove();
                }, TIMINGS.errorClear);
            }
            input.classList.remove('is-error');
            const actionWrapper = input.closest('.input-action');
            if (actionWrapper) {
                actionWrapper.classList.remove('is-error');
            }
        };
        input.addEventListener('input', handler);
        input.addEventListener('change', handler);
    };

    const setStateIfEmpty = (key, value) => {
        if (value === null || value === undefined || value === '') return;
        if (formState[key] === null || formState[key] === undefined || formState[key] === '') {
            formState[key] = value;
        }
    };

    const applyBodyDefaults = () => {
        const data = getBodyDataset();
        setStateIfEmpty('appDomain', data.defaultAppDomain);
        setStateIfEmpty('httpPort', data.defaultHttpPort);
        setStateIfEmpty('httpsPort', data.defaultHttpsPort);
        setStateIfEmpty('emailCertificates', data.defaultEmailCertificates);
        setStateIfEmpty('opensslKey', data.defaultSecretKey);
        setStateIfEmpty('assistantOpenAIKey', data.defaultAssistantOpenaiKey);
        if (data.lockedDatabase) {
            formState.database = data.lockedDatabase;
        }
        if (!isUpgradeMode()) {
            setStateIfEmpty('database', data.defaultDatabase);
        }
    };

    const toDatabaseLabel = (value) => {
        if (!value) return '';
        return value.toLowerCase() === 'mariadb' ? 'MariaDB' : 'MongoDB';
    };

    const updateDatabaseSelection = (radio, root) => {
        if (!radio || !root) return;
        const allOptions = root.querySelectorAll('.selector-card');
        allOptions.forEach((option) => option.classList.remove('selected'));
        const selectedOption = radio.closest('.selector-card');
        if (selectedOption) {
            selectedOption.classList.add('selected');
        }
    };

    const syncResetButton = (input, button) => {
        const defaultValue = input.dataset.default ?? '';
        button.disabled = input.value === defaultValue;
    };

    const setupResetButtons = (root) => {
        const inputs = root.querySelectorAll('.input-field[data-default]');
        inputs.forEach((input) => {
            const button = root.querySelector(`[data-reset-target="${input.id}"]`);
            if (!button) return;

            syncResetButton(input, button);

            input.addEventListener('input', () => syncResetButton(input, button));
            button.addEventListener('click', () => {
                input.value = input.dataset.default ?? '';
                syncResetButton(input, button);
                input.dispatchEvent(new Event('input', { bubbles: true }));
            });
        });
    };

    const toggleAccordion = (button) => {
        const content = button.nextElementSibling;
        const icon = button.querySelector('.accordion-chevron');
        const isOpen = button.classList.contains('is-open');

        button.classList.toggle('is-open', !isOpen);
        button.setAttribute('aria-expanded', String(!isOpen));

        if (content) {
            if (!isOpen) {
                content.classList.add('open');
                content.style.maxHeight = `${content.scrollHeight}px`;
            } else {
                content.style.maxHeight = '0px';
                content.classList.remove('open');
            }
        }

        if (icon) {
            icon.setAttribute('data-open', String(!isOpen));
        }
    };

    const setupAccordion = (root) => {
        const buttons = root.querySelectorAll('.accordion-toggle');
        buttons.forEach((button) => {
            button.addEventListener('click', () => toggleAccordion(button));
        });
    };

    const setUnloadGuard = (enabled) => {
        if (!enabled && unloadGuard) {
            window.removeEventListener('beforeunload', unloadGuard);
            unloadGuard = null;
            return;
        }

        if (enabled && !unloadGuard) {
            unloadGuard = (event) => {
                event.preventDefault();
                event.returnValue = '';
                return '';
            };
            window.addEventListener('beforeunload', unloadGuard);
        }
    };

    const bindInputToState = (input, key) => {
        if (!input) return;
        const update = () => {
            formState[key] = input.value;
            dispatchStateChange(key);
        };
        input.addEventListener('input', update);
        input.addEventListener('change', update);
        update();
    };

    const bindDatabaseSelection = (root) => {
        const radios = root.querySelectorAll('input[name="database"]');
        radios.forEach((radio) => {
            radio.addEventListener('change', () => {
                formState.database = radio.value;
                updateDatabaseSelection(radio, root);
            });
        });
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
                    updateDatabaseSelection(radio, root);
                }
            });
        }
    };

    const hydrateStep1State = (root) => {
        setStateIfEmpty('appDomain', root.querySelector('#hostname')?.value);
        setStateIfEmpty('database', root.querySelector('input[name="database"]:checked')?.value);
        setStateIfEmpty('httpPort', root.querySelector('#http-port')?.value);
        setStateIfEmpty('httpsPort', root.querySelector('#https-port')?.value);
        setStateIfEmpty('emailCertificates', root.querySelector('#ssl-email')?.value);
        setStateIfEmpty('assistantOpenAIKey', root.querySelector('#assistant-openai-key')?.value);
    };

    const applyLockPayload = () => {
        const lock = getInstallLock();
        if (!lock || !lock.payload) return;
        const payload = lock.payload;
        setStateIfEmpty('appDomain', payload.appDomain);
        setStateIfEmpty('database', payload.database);
        setStateIfEmpty('httpPort', payload.httpPort);
        setStateIfEmpty('httpsPort', payload.httpsPort);
        setStateIfEmpty('emailCertificates', payload.emailCertificates);
        setStateIfEmpty('opensslKey', payload.opensslKey);
        setStateIfEmpty('assistantOpenAIKey', payload.assistantOpenAIKey);
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
                updateDatabaseSelection(radio, root);
            }
        }
    };

    const openAccordion = (root) => {
        const toggle = root.querySelector('.accordion-toggle');
        const content = root.querySelector('.accordion-content');
        if (!toggle || !content) return;
        if (!toggle.classList.contains('is-open')) {
            toggle.classList.add('is-open');
            toggle.setAttribute('aria-expanded', 'true');
            content.classList.add('open');
            content.style.maxHeight = `${content.scrollHeight}px`;
        }
    };

    const disableControls = (root) => {
        const inputs = root.querySelectorAll('input, select, textarea');
        inputs.forEach((input) => {
            if (input.type === 'radio' || input.type === 'checkbox') {
                input.disabled = true;
            } else {
                input.readOnly = true;
                input.setAttribute('aria-disabled', 'true');
            }
        });

        const buttons = root.querySelectorAll('button');
        buttons.forEach((button) => {
            if (button.matches('[data-copy-target]')) return;
            button.disabled = true;
            button.setAttribute('aria-disabled', 'true');
        });

        root.classList.add('is-locked');
    };

    const initStep1 = (root) => {
        if (!root) return;
        if (isMockMode()) {
            clearInstallLock();
            clearInstallId();
        }
        syncInstallLockFlag();
        applyLockPayload();
        applyBodyDefaults();
        hydrateStep1State(root);
        applyStep1State(root);

        if (isInstallLocked()) {
            openAccordion(root);
            disableControls(root);
            return;
        }

        const lockedDatabase = getLockedDatabase();
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

        bindErrorClear(hostname);
        bindErrorClear(httpPort);
        bindErrorClear(httpsPort);
        bindErrorClear(sslEmail);
        bindErrorClear(assistantKey);

        const checked = root.querySelector('input[name="database"]:checked');
        if (checked) {
            updateDatabaseSelection(checked, root);
        }

        setupResetButtons(root);
        setupAccordion(root);
        Tooltips?.setupTooltipPortals?.(root);
    };

    const generateSecretKey = () => {
        const array = new Uint8Array(32);
        window.crypto.getRandomValues(array);
        return Array.from(array, (byte) => byte.toString(16).padStart(2, '0')).join('');
    };

    const copyToClipboard = (value, input) => {
        if (!value) return;
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(value);
            return;
        }
        if (input) {
            input.select();
            document.execCommand('copy');
            input.setSelectionRange(0, 0);
            return;
        }
        const textArea = document.createElement('textarea');
        textArea.value = value;
        textArea.style.position = 'fixed';
        textArea.style.top = '-9999px';
        document.body.appendChild(textArea);
        textArea.focus();
        textArea.select();
        try {
            document.execCommand('copy');
        } catch (error) {} finally {
            document.body.removeChild(textArea);
        }
    };

    const setTooltipText = (wrapper, message) => {
        if (!wrapper) return;
        const tooltip = wrapper.querySelector('.tooltip');
        if (tooltip && message) {
            tooltip.textContent = message;
        }
    };

    const resetTooltipText = (wrapper) => {
        if (!wrapper) return;
        const defaultText = wrapper.dataset.tooltipDefault;
        if (!defaultText) return;
        setTooltipText(wrapper, defaultText);
    };

    const Tooltips = window.InstallerTooltips || null;

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
        if (isMockMode()) {
            clearInstallLock();
            clearInstallId();
        }
        syncInstallLockFlag();
        applyLockPayload();
        applyBodyDefaults();
        hydrateStep2State(root);
        if (!formState.opensslKey || !formState.opensslKey.trim()) {
            formState.opensslKey = generateSecretKey();
            dispatchStateChange('opensslKey');
        }
        applyStep2State(root);

        const input = root.querySelector('#secret-key');
        if (input) {
            bindInputToState(input, 'opensslKey');
            bindErrorClear(input);
        }

        const copyButton = root.querySelector('[data-copy-target]');
        const tooltipWrapper = copyButton?.closest('.tooltip-wrapper');

        if (tooltipWrapper) {
            tooltipWrapper.addEventListener('mouseenter', () => resetTooltipText(tooltipWrapper));
            tooltipWrapper.addEventListener('focusin', () => resetTooltipText(tooltipWrapper));
        }

        if (copyButton) {
            copyButton.addEventListener('click', () => {
                const targetId = copyButton.getAttribute('data-copy-target');
                const targetInput = targetId ? root.querySelector(`#${targetId}`) : null;
                const value = targetInput?.value || '';
                copyToClipboard(value, targetInput);
                copyButton.blur();

                if (tooltipWrapper) {
                    const successText = tooltipWrapper.dataset.tooltipSuccess || 'Copied';
                    setTooltipText(tooltipWrapper, successText);
                }
            });
        }

        const regenerateButton = root.querySelector('[data-regenerate-target]');
        if (regenerateButton && !isInstallLocked()) {
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
                targetInput.value = generateSecretKey();
                targetInput.dispatchEvent(new Event('input', { bubbles: true }));
            });
        }

        if (isInstallLocked()) {
            disableControls(root);
        }
    };

    const updateReviewSummary = (root) => {
        if (!root) return;
        const valueNodes = root.querySelectorAll('[data-review-value]');
        valueNodes.forEach((node) => {
            const key = node.dataset.reviewValue;
            if (!key) return;
            let value = formState[key];
            if (key === 'database') {
                value = toDatabaseLabel(formState.database);
            }
            if (value) {
                node.textContent = value;
            }
        });

        const badge = root.querySelector('[data-review-badge]');
        if (badge) {
            const hasKey = Boolean((formState.opensslKey || '').trim());
            badge.textContent = hasKey ? 'Generated' : 'Missing';
            badge.classList.remove('badge-success', 'badge-warning');
            badge.classList.add(hasKey ? 'badge-success' : 'badge-warning');
        }

        const assistantBadge = root.querySelector('[data-review-assistant-badge]');
        if (assistantBadge) {
            const hasAssistantKey = Boolean((formState.assistantOpenAIKey || '').trim());
            assistantBadge.textContent = hasAssistantKey ? 'Enabled' : 'Disabled';
            assistantBadge.classList.remove('badge-success', 'badge-neutral');
            assistantBadge.classList.add(hasAssistantKey ? 'badge-success' : 'badge-neutral');
        }
    };

    const initStep3 = (root) => {
        if (!root) return;
        if (isMockMode()) {
            clearInstallLock();
            clearInstallId();
        }
        syncInstallLockFlag();
        applyLockPayload();
        applyBodyDefaults();
        updateReviewSummary(root);
        if (reviewListener) {
            document.removeEventListener('installer:state-change', reviewListener);
        }
        reviewListener = () => updateReviewSummary(root);
        document.addEventListener('installer:state-change', reviewListener);
        if (isInstallLocked()) {
            disableControls(root);
        }
    };

    const getProgressLabel = (step, status, message) => {
        if (!step) return message || '';
        if (status === 'error') {
            const normalized = normalizeInstallError(message || '');
            return normalized.summary || 'Installation failed.';
        }
        if (status === 'completed') return step.done;
        return step.inProgress;
    };

    const updateInstallRow = (row, step, status, message) => {
        if (!row || !step) return;
        row.dataset.status = status;
        row.dataset.step = step.id;
        if (status !== 'error') {
            row.classList.remove('is-open');
            const toggle = row.querySelector('[data-install-toggle]');
            if (toggle) {
                toggle.setAttribute('aria-expanded', 'false');
            }
        }
        const label = getProgressLabel(step, status, message);
        const text = row.querySelector('[data-install-text]');
        if (text) {
            text.textContent = label;
        }
    };

    const normalizeInstallError = (message) => {
        const text = String(message || '').trim();
        if (!text) {
            return { summary: '', details: '' };
        }
        const colonIndex = text.indexOf(':');
        if (colonIndex > 0 && colonIndex < 80) {
            const summary = text.slice(0, colonIndex).trim();
            const details = text.slice(colonIndex + 1).trim();
            return { summary, details };
        }
        if (text.length > 180) {
            return { summary: text.slice(0, 180).trim() + '…', details: text };
        }
        return { summary: text, details: '' };
    };

    const updateInstallErrorDetails = (row, error) => {
        if (!row) return;
        const traceNode = row.querySelector('[data-install-trace]');
        const normalized = normalizeInstallError(error?.message || '');
        const output = error?.output || '';
        const trace = error?.trace || '';
        const detailChunks = [];
        if (normalized.details) detailChunks.push(normalized.details);
        if (output) detailChunks.push(output);
        if (trace) detailChunks.push(trace);
        const detailText = detailChunks.join('\n\n');

        if (traceNode) {
            traceNode.textContent = detailText;
            traceNode.style.display = detailText ? 'block' : 'none';
        }
    };

        const createInstallRow = (template, step) => {
            const fragment = template.content.cloneNode(true);
            const row = fragment.querySelector('.install-row');
            if (!row) return null;
            const toggle = row.querySelector('[data-install-toggle]');
            const setOpenState = (isOpen) => {
                row.classList.toggle('is-open', isOpen);
                if (toggle) {
                    toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
                }
            };
        const toggleRow = () => {
            if (!row.dataset.status || row.dataset.status !== 'error') {
                return;
            }
            setOpenState(!row.classList.contains('is-open'));
        };
        row.addEventListener('click', (event) => {
            if (event.target.closest('[data-install-retry]')) {
                return;
            }
            if (event.target.closest('[data-install-toggle]')) {
                return;
            }
            if (event.target.closest('.install-row-details')) {
                return;
            }
            toggleRow();
        });
            if (toggle) {
                toggle.addEventListener('click', (event) => {
                    event.stopPropagation();
                    toggleRow();
                });
            }
        updateInstallRow(row, step, 'in-progress');
        return row;
    };

    const generateInstallId = () => {
        if (window.crypto?.randomUUID) {
            return window.crypto.randomUUID();
        }
        const bytes = new Uint8Array(16);
        window.crypto.getRandomValues(bytes);
        return Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
    };

    const extractHostname = (value) => {
        if (!value) return '';
        const trimmed = value.trim();
        if (trimmed.startsWith('[')) {
            const end = trimmed.indexOf(']');
            if (end !== -1) {
                return trimmed.slice(1, end);
            }
            return trimmed;
        }
        const colonCount = (trimmed.match(/:/g) || []).length;
        if (colonCount === 1) {
            return trimmed.split(':')[0];
        }
        return trimmed;
    };

    const LOCAL_HOSTS = new Set(['localhost', '127.0.0.1', '::1', '0.0.0.0']);

    const isLocalHost = (host) => {
        if (!host) return false;
        const normalized = host.toLowerCase();
        return LOCAL_HOSTS.has(normalized);
    };

    const buildRedirectUrl = () => {
        const dataset = getBodyDataset();
        const rawDomain = (formState.appDomain || dataset.defaultAppDomain || '').trim();
        if (!rawDomain) return '';
        const httpPort = (formState.httpPort || dataset.defaultHttpPort || '').trim();
        const httpsPort = (formState.httpsPort || dataset.defaultHttpsPort || '').trim();
        const hasPort = rawDomain.includes(':') || rawDomain.startsWith('[');
        let host = rawDomain;
        const hostForProtocol = extractHostname(rawDomain);
        const normalizedHost = hostForProtocol.toLowerCase();
        if (hostForProtocol === '0.0.0.0') {
            host = rawDomain.replace('0.0.0.0', 'localhost');
        } else if (normalizedHost === 'traefik') {
            host = rawDomain.replace(hostForProtocol, 'localhost');
        }
        let protocol = 'http';
        let port = httpPort;
        if (httpsPort && httpsPort !== '0' && !isLocalHost(normalizedHost)) {
            protocol = 'https';
            port = httpsPort;
        }
        if (!hasPort && port && ((protocol === 'http' && port !== '80') || (protocol === 'https' && port !== '443'))) {
            host = `${rawDomain}:${port}`;
        }
        return `${protocol}://${host}`;
    };

    const redirectToApp = () => {
        if (isMockMode()) return;
        const url = buildRedirectUrl();
        if (!url) return;
        window.location.href = url;
    };

    const notifyInstallComplete = (installId) => {
        if (isMockProgressMode()) return Promise.resolve();
        if (!installId) return Promise.resolve();
        return fetch('/install/complete', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ installId })
        }).catch(() => {});
    };

    const getStoredInstallId = () => {
        try {
            return sessionStorage.getItem(INSTALL_ID_KEY);
        } catch (error) {
            return null;
        }
    };

    const storeInstallId = (installId) => {
        try {
            sessionStorage.setItem(INSTALL_ID_KEY, installId);
        } catch (error) {}
    };

    const clearInstallId = () => {
        try {
            sessionStorage.removeItem(INSTALL_ID_KEY);
        } catch (error) {}
    };

    const buildInstallPayload = (installId) => {
        const normalizedSecret = (formState.opensslKey || '').trim();
        if (!normalizedSecret) {
            formState.opensslKey = generateSecretKey();
        }
        const normalizedDomain = (formState.appDomain || '').trim() || 'localhost';
        const normalizedHttpPort = (formState.httpPort || '').trim() || '80';
        const normalizedHttpsPort = (formState.httpsPort || '').trim() || '443';
        const normalizedEmail = (formState.emailCertificates || '').trim();
        const normalizedAssistantKey = (formState.assistantOpenAIKey || '').trim();

        return {
            installId,
            httpPort: normalizedHttpPort,
            httpsPort: normalizedHttpsPort,
            database: formState.database || 'mongodb',
            appDomain: normalizedDomain,
            emailCertificates: normalizedEmail,
            opensslKey: (formState.opensslKey || '').trim(),
            assistantOpenAIKey: normalizedAssistantKey
        };
    };

    const fetchInstallStatus = async (installId) => {
        if (isMockProgressMode()) return null;
        if (!installId) return null;
        const response = await fetch(`/install/status?installId=${encodeURIComponent(installId)}`, {
            cache: 'no-store'
        });
        if (!response.ok) return null;
        const json = await response.json();
        return json.progress || null;
    };

    const readEventStream = async (stream, onEvent) => {
        const reader = stream.getReader();
        const decoder = new TextDecoder('utf-8');
        let buffer = '';

        try {
            while (true) {
                const { value, done } = await reader.read();
                if (done) break;
                buffer += decoder.decode(value, { stream: true });
                let separatorIndex = buffer.indexOf('\n\n');

                while (separatorIndex !== -1) {
                    const rawEvent = buffer.slice(0, separatorIndex);
                    buffer = buffer.slice(separatorIndex + 2);
                    const lines = rawEvent.split('\n');
                    let eventName = 'message';
                    let data = '';

                    lines.forEach((line) => {
                        if (line.startsWith('event:')) {
                            eventName = line.replace('event:', '').trim();
                        } else if (line.startsWith('data:')) {
                            data += line.replace('data:', '').trim();
                        }
                    });

                    if (data) {
                        try {
                            onEvent(eventName, JSON.parse(data));
                        } catch (error) {
                            onEvent(eventName, { message: data });
                        }
                    }

                    separatorIndex = buffer.indexOf('\n\n');
                }
            }
        } finally {
            try {
                reader.releaseLock();
            } catch (error) {}
        }
    };

    const initStep4 = (root) => {
        if (!root) return;
        if (isMockProgressMode()) {
            clearInstallLock();
            clearInstallId();
        }

        if (activeInstall?.controller) {
            activeInstall.controller.abort();
        }
        if (activeInstall?.pollTimer) {
            clearInterval(activeInstall.pollTimer);
        }
        if (activeInstall?.fallbackTimer) {
            clearTimeout(activeInstall.fallbackTimer);
        }
        activeInstall = null;

        const list = root.querySelector('[data-install-list]');
        const template = root.querySelector('#install-row-template');
        if (!list || !template) return;

        list.innerHTML = '';
        const rowsById = new Map();
        const progressState = new Map();
        let pendingProgressTimer = null;
        let pendingProgressStep = null;
        let pendingCompletionTimer = null;
        syncInstallLockFlag();
        applyLockPayload();
        applyBodyDefaults();

        const ensureRow = (step) => {
            if (!step) return null;
            if (rowsById.has(step.id)) {
                return rowsById.get(step.id);
            }
            const row = createInstallRow(template, step);
            if (!row) return null;
            row.classList.add('is-entering');
            list.appendChild(row);
            row.getBoundingClientRect();
            requestAnimationFrame(() => {
                row.classList.remove('is-entering');
            });
            rowsById.set(step.id, row);
            return row;
        };

        const installPanel = root.querySelector('.install-panel');
        let panelHeightCleanup = null;
        const animatePanelHeight = (mutate) => {
            if (!installPanel) {
                mutate();
                return;
            }
            if (panelHeightCleanup) {
                panelHeightCleanup();
                panelHeightCleanup = null;
            }
            const currentHeight = installPanel.getBoundingClientRect().height;
            installPanel.style.height = `${currentHeight}px`;
            installPanel.getBoundingClientRect();
            mutate();
            const nextHeight = installPanel.getBoundingClientRect().height;
            if (currentHeight === nextHeight) {
                installPanel.style.height = '';
                return;
            }
            installPanel.style.height = `${currentHeight}px`;
            installPanel.getBoundingClientRect();
            installPanel.style.height = `${nextHeight}px`;
            const cleanup = () => {
                installPanel.style.height = '';
                installPanel.removeEventListener('transitionend', onEnd);
            };
            const onEnd = (event) => {
                if (event.propertyName === 'height') {
                    cleanup();
                }
            };
            panelHeightCleanup = cleanup;
            installPanel.addEventListener('transitionend', onEnd);
        };

        const renderProgress = () => {
            animatePanelHeight(() => {
                const visibleSteps = [];
                for (const step of INSTALLATION_STEPS) {
                    const state = progressState.get(step.id);
                    if (!state) break;
                    visibleSteps.push(step);
                }

                visibleSteps.forEach((step) => {
                    const state = progressState.get(step.id);
                    if (!state) return;
                    const row = ensureRow(step);
                    if (row) {
                        updateInstallRow(row, step, state.status || 'in-progress', state.message);
                        if (state.status === 'error') {
                            updateInstallErrorDetails(row, {
                                message: state.message,
                                trace: state.details?.trace,
                                output: state.details?.output
                            });
                        }
                    }
                });
            });
        };

        const firstStep = INSTALLATION_STEPS[0];
        if (firstStep) {
            progressState.set(firstStep.id, {
                status: 'in-progress',
                message: firstStep.inProgress
            });
        }
        renderProgress();

        const applyProgress = (payload) => {
            const step = getStepDefinition(payload.step) || {
                id: payload.step,
                inProgress: payload.message || payload.step,
                done: payload.message || payload.step
            };
            progressState.set(step.id, {
                status: payload.status || 'in-progress',
                message: payload.message,
                details: payload.details
            });
            renderProgress();
            if (activeInstall) {
                activeInstall.lastEventAt = Date.now();
                if (payload.status === 'error') {
                    if (activeInstall.pollTimer) {
                        clearInterval(activeInstall.pollTimer);
                        activeInstall.pollTimer = null;
                    }
                    if (activeInstall.fallbackTimer) {
                        clearTimeout(activeInstall.fallbackTimer);
                        activeInstall.fallbackTimer = null;
                    }
                }
            }
            scheduleFallback();
        };

        const handleProgress = (payload) => {
            if (!payload || !payload.step) return;
            if (pendingProgressTimer && pendingProgressStep === payload.step && payload.status !== 'in-progress') {
                clearTimeout(pendingProgressTimer);
                pendingProgressTimer = null;
                pendingProgressStep = null;
            }
            if (pendingCompletionTimer) {
                clearTimeout(pendingCompletionTimer);
                pendingCompletionTimer = null;
            }
            const step = getStepDefinition(payload.step) || {
                id: payload.step,
                inProgress: payload.message || payload.step,
                done: payload.message || payload.step
            };
            if (payload.status === 'in-progress') {
                const currentIndex = INSTALLATION_STEPS.findIndex((candidate) => candidate.id === step.id);
                const completionTargets = [];
                if (currentIndex > 0) {
                    for (let i = 0; i < currentIndex; i += 1) {
                        const previousStep = INSTALLATION_STEPS[i];
                        const previousState = progressState.get(previousStep.id);
                        if (previousState && previousState.status !== 'completed') {
                            completionTargets.push({
                                step: previousStep,
                                details: previousState.details
                            });
                        }
                    }
                }
                if (completionTargets.length && currentIndex > 0) {
                    pendingProgressStep = payload.step;
                    pendingCompletionTimer = setTimeout(() => {
                        pendingCompletionTimer = null;
                        completionTargets.forEach(({ step: previousStep, details }) => {
                            progressState.set(previousStep.id, {
                                status: 'completed',
                                message: previousStep.done,
                                details
                            });
                        });
                        renderProgress();
                    }, TIMINGS.progressCompleteDelay);
                    pendingProgressTimer = setTimeout(() => {
                        pendingProgressTimer = null;
                        pendingProgressStep = null;
                        applyProgress(payload);
                    }, TIMINGS.progressTransitionDelay);
                    scheduleFallback();
                    return;
                }
            }
            applyProgress(payload);
        };

        const applySnapshot = (snapshot) => {
            if (!snapshot || !snapshot.steps) return;
            INSTALLATION_STEPS.forEach((step) => {
                const detail = snapshot.steps[step.id];
                if (!detail) return;
                progressState.set(step.id, {
                    status: detail.status,
                    message: detail.message,
                    details: snapshot.details?.[step.id]
                });
            });
            renderProgress();
        };

        const startPolling = () => {
            if (isMockProgressMode()) return;
            if (!activeInstall || activeInstall.pollTimer) return;
            activeInstall.pollTimer = setInterval(async () => {
                if (!activeInstall || activeInstall.completed) return;
                const snapshot = await fetchInstallStatus(activeInstall.installId);
                if (snapshot) {
                    applySnapshot(snapshot);
                }
            }, TIMINGS.installPollInterval);
        };

        const scheduleFallback = () => {
            if (isMockProgressMode()) return;
            if (!activeInstall) return;
            if (activeInstall.fallbackTimer) {
                clearTimeout(activeInstall.fallbackTimer);
            }
            activeInstall.fallbackTimer = setTimeout(() => {
                if (!activeInstall) return;
                startPolling();
            }, TIMINGS.installFallbackDelay);
        };

        const finalizeInstall = () => {
            if (!activeInstall) return;
            activeInstall.completed = true;
            if (activeInstall.pollTimer) {
                clearInterval(activeInstall.pollTimer);
            }
            if (activeInstall.fallbackTimer) {
                clearTimeout(activeInstall.fallbackTimer);
            }
            setUnloadGuard(false);
        };

        const startInstallStream = async (installId, options = {}) => {
            activeInstall = {
                installId,
                controller: new AbortController(),
                lastEventAt: Date.now(),
                pollTimer: null,
                fallbackTimer: null,
                completed: false
            };

            const payload = buildInstallPayload(installId);
            if (options.retryStep) {
                payload.retryStep = options.retryStep;
            }
            if (!isMockProgressMode()) {
                setInstallLock(installId, payload);
                setUnloadGuard(true);
            }

            try {
                if (options.forceMock || isMockProgressMode()) {
                    simulateInstallProgress(options);
                    return;
                }
                scheduleFallback();
                const response = await fetch('/install', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'text/event-stream'
                    },
                    body: JSON.stringify(payload),
                    signal: activeInstall.controller.signal
                });

                if (!response.ok || !response.body) {
                    let errorMessage = null;
                    try {
                        const contentType = response.headers.get('Content-Type') || '';
                        if (contentType.includes('application/json')) {
                            const data = await response.json();
                            errorMessage = data?.message || null;
                        }
                    } catch (error) {
                        errorMessage = null;
                    }
                    if (errorMessage) {
                        handleProgress({
                            step: 'config-files',
                            status: 'error',
                            message: errorMessage
                        });
                        finalizeInstall();
                        return;
                    }
                    startPolling();
                    return;
                }

                await readEventStream(response.body, (event, data) => {
                    if (!activeInstall) return;
                    if (event === 'install-id' && data?.installId) {
                        activeInstall.installId = data.installId;
                        storeInstallId(data.installId);
                        return;
                    }
                    if (event === 'progress') {
                        handleProgress(data);
                        return;
                    }
                    if (event === 'done') {
                        const lastStep = INSTALLATION_STEPS[INSTALLATION_STEPS.length - 1];
                        if (lastStep) {
                            const lastState = progressState.get(lastStep.id);
                            if (!lastState || lastState.status !== 'completed') {
                                progressState.set(lastStep.id, {
                                    status: 'completed',
                                    message: lastStep.done,
                                    details: lastState?.details
                                });
                                renderProgress();
                            }
                        }
                        finalizeInstall();
                        notifyInstallComplete(activeInstall?.installId);
                        setTimeout(() => redirectToApp(), TIMINGS.redirectDelay);
                        return;
                    }
                    if (event === 'error') {
                        if (data?.message) {
                            const existingError = Array.from(progressState.values()).some((state) => state?.status === 'error');
                            if (data.step || !existingError) {
                                let targetStep = data.step;
                                if (!targetStep) {
                                    for (const candidate of INSTALLATION_STEPS) {
                                        const state = progressState.get(candidate.id);
                                        if (!state || state.status !== 'completed') {
                                            targetStep = candidate.id;
                                            break;
                                        }
                                    }
                                }
                                handleProgress({
                                    step: targetStep || 'config-files',
                                    status: 'error',
                                    message: data.message,
                                    details: data.details
                                });
                            }
                        }
                        finalizeInstall();
                    }
                });
                if (activeInstall && !activeInstall.completed) {
                    startPolling();
                }
            } catch (error) {
                if (!activeInstall || activeInstall.controller.signal.aborted) {
                    return;
                }
                startPolling();
            }
        };

        const resumeInstall = async (installId) => {
            const snapshot = await fetchInstallStatus(installId);
            if (!snapshot) return false;
            activeInstall = {
                installId,
                controller: new AbortController(),
                lastEventAt: Date.now(),
                pollTimer: null,
                fallbackTimer: null,
                completed: false
            };
            applySnapshot(snapshot);
            startPolling();
            setUnloadGuard(true);
            return true;
        };

        const resetProgressFrom = (stepId) => {
            const index = INSTALLATION_STEPS.findIndex((step) => step.id === stepId);
            if (index === -1) return;
            INSTALLATION_STEPS.slice(index).forEach((step) => {
                progressState.delete(step.id);
                const row = rowsById.get(step.id);
                if (row && row.parentNode) {
                    row.parentNode.removeChild(row);
                }
                rowsById.delete(step.id);
            });
        };

        const retryInstallStep = (stepId) => {
            if (!stepId) return;
            if (activeInstall?.controller) {
                activeInstall.controller.abort();
            }
            if (activeInstall?.pollTimer) {
                clearInterval(activeInstall.pollTimer);
            }
            if (activeInstall?.fallbackTimer) {
                clearTimeout(activeInstall.fallbackTimer);
            }
            resetProgressFrom(stepId);
            handleProgress({
                step: stepId,
                status: 'in-progress',
                message: getStepDefinition(stepId)?.inProgress || 'Retrying...'
            });
            const installId = activeInstall?.installId || getInstallLock()?.installId || generateInstallId();
            storeInstallId(installId);
            startInstallStream(installId, { retryStep: stepId, forceMock: isMockProgressMode() });
        };

        list.addEventListener('click', (event) => {
            const button = event.target.closest('[data-install-retry]');
            if (!button) return;
            const row = button.closest('.install-row');
            const stepId = row?.dataset.step;
            retryInstallStep(stepId);
        });

        const simulateInstallProgress = (options = {}) => {
            if (activeInstall?.pollTimer) {
                clearInterval(activeInstall.pollTimer);
            }
            const startIndex = options.retryStep
                ? Math.max(0, INSTALLATION_STEPS.findIndex((step) => step.id === options.retryStep))
                : 0;
            let index = startIndex;
            const errorStepId = isMockErrorMode() && !options.retryStep ? 'docker-containers' : null;
            const mockErrorDetails = CONSTANTS.mockErrorDetails || {
                output: 'Error response from daemon: manifest for appwrite/appwrite:local not found: manifest unknown',
                trace: '#0 /usr/src/code/src/Appwrite/Platform/Tasks/Install.php(540): Appwrite\\\\Platform\\\\Tasks\\\\Install->performInstallation(...)\\n#1 {main}'
            };
            const advance = () => {
                if (index > 0) {
                    const previous = INSTALLATION_STEPS[index - 1];
                    const previousState = progressState.get(previous.id);
                    if (!previousState || previousState.status !== 'completed') {
                        handleProgress({
                            step: previous.id,
                            status: 'completed',
                            message: previous.done
                        });
                    }
                }

                if (index >= INSTALLATION_STEPS.length) {
                    finalizeInstall();
                    return;
                }

                const step = INSTALLATION_STEPS[index];
                handleProgress({
                    step: step.id,
                    status: 'in-progress',
                    message: step.inProgress
                });

                if (errorStepId && step.id === errorStepId) {
                    index += 1;
                    activeInstall.pollTimer = setTimeout(() => {
                        handleProgress({
                            step: step.id,
                            status: 'error',
                            message: 'Failed to start containers',
                            details: mockErrorDetails
                        });
                        finalizeInstall();
                    }, TIMINGS.mockStepDelay);
                    return;
                }

                index += 1;
                activeInstall.pollTimer = setTimeout(advance, TIMINGS.mockStepDelay);
            };
            advance();
        };

        if (isMockProgressMode()) {
            const newInstallId = generateInstallId();
            storeInstallId(newInstallId);
            startInstallStream(newInstallId);
            return;
        }

        const lock = getInstallLock();
        const existingInstallId = lock?.installId || getStoredInstallId();
        if (existingInstallId) {
            resumeInstall(existingInstallId).then((resumed) => {
                if (!resumed) {
                    clearInstallId();
                    clearInstallLock();
                    const newInstallId = generateInstallId();
                    storeInstallId(newInstallId);
                    startInstallStream(newInstallId);
                }
            });
        } else {
            const newInstallId = generateInstallId();
            storeInstallId(newInstallId);
            startInstallStream(newInstallId);
        }
    };

    const initStep = (step, container) => {
        if (!container) return;
        const root = container.querySelector('.step-layout') || container;
        const normalized = clampStep(step);
        Tooltips?.cleanupTooltipPortals?.();
        if (normalized !== 3 && reviewListener) {
            document.removeEventListener('installer:state-change', reviewListener);
            reviewListener = null;
        }
        if (normalized !== 4 && activeInstall?.controller) {
            activeInstall.controller.abort();
            if (activeInstall.pollTimer) {
                clearInterval(activeInstall.pollTimer);
            }
            if (activeInstall.fallbackTimer) {
                clearTimeout(activeInstall.fallbackTimer);
            }
            activeInstall = null;
            setUnloadGuard(false);
        }
        if (normalized === 1) initStep1(root);
        if (normalized === 2) initStep2(root);
        if (normalized === 3) initStep3(root);
        if (normalized === 4) initStep4(root);
    };

    window.InstallerSteps = {
        initStep1,
        initStep2,
        initStep3,
        initStep4,
        installationSteps: INSTALLATION_STEPS,
        isInstallLocked,
        getInstallLock,
        clearInstallLock,
        initStep,
        validateStep: (step, container) => {
            const root = container?.querySelector('.step-layout') || container;
            const normalized = clampStep(step);
            if (normalized === 1) {
                clearFieldErrors(root);
                let valid = true;
                const hostname = root?.querySelector('#hostname');
                const httpPort = root?.querySelector('#http-port');
                const httpsPort = root?.querySelector('#https-port');
                const sslEmail = root?.querySelector('#ssl-email');

                if (!hostname || !hostname.value.trim()) {
                    setFieldError(hostname, 'Please enter your Appwrite hostname');
                    valid = false;
                } else if (!isValidHostnameInput(hostname.value.trim())) {
                    setFieldError(hostname, 'Please enter a valid hostname');
                    valid = false;
                }

                const parsePort = (input, label) => {
                    const value = input?.value;
                    if (!value || !isValidPort(value)) {
                        setFieldError(input, `Please enter a valid ${label} port (1-65535)`);
                        return false;
                    }
                    return true;
                };

                if (!parsePort(httpPort, 'HTTP')) valid = false;
                if (!parsePort(httpsPort, 'HTTPS')) valid = false;

                if (!sslEmail || !sslEmail.value.trim()) {
                    setFieldError(sslEmail, 'Please enter an email address for SSL certificates');
                    valid = false;
                } else if (!isValidEmail(sslEmail.value.trim())) {
                    setFieldError(sslEmail, 'Please enter a valid email address');
                    valid = false;
                }

                if (!valid) {
                    openAccordion(root);
                }

                return valid;
            }

            if (normalized === 2) {
                clearFieldErrors(root);
                const secretKey = root?.querySelector('#secret-key');
                const secretValue = secretKey?.value.trim() || '';
                if (!secretKey || !secretValue) {
                    setFieldError(secretKey, 'Please enter or generate a secret API key');
                    return false;
                }
                if (secretValue.length > 64) {
                    setFieldError(secretKey, 'Secret API key must be 1-64 characters');
                    return false;
                }
            }

            return true;
        }
    };
})();

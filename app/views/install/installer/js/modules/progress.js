(() => {
    const {
        INSTALLATION_STEPS,
        TIMINGS,
        getBodyDataset,
        isUpgradeMode,
        STEP_IDS,
        STATUS,
        SSE_EVENTS
    } = window.InstallerStepsContext;
    const {
        formState,
        applyLockPayload,
        applyBodyDefaults,
        setInstallLock,
        getInstallLock,
        clearInstallLock,
        isInstallLocked,
        syncInstallLockFlag,
        getStoredInstallId,
        storeInstallId,
        clearInstallId
    } = window.InstallerStepsState || {};
    const { extractHostname, isLocalHost, isIPAddress } = window.InstallerStepsValidation || {};
    const { generateSecretKey } = window.InstallerStepsUI || {};
    const { showToast } = window.InstallerToast || {};

    let activeInstall = null;
    let unloadGuard = null;
    let sseSessionDetails = null;
    const csrfToken = document.querySelector('meta[name="appwrite-installer-csrf"]')?.getAttribute('content') || '';

    const withCsrfHeader = (headers = {}) => {
        if (!csrfToken) {
            return headers;
        }
        return { ...headers, 'X-Appwrite-Installer-CSRF': csrfToken };
    };

    const showCsrfToast = () => {
        showToast?.({
            status: 'error',
            title: 'Session expired',
            description: 'Refresh the page and try again.',
            dismissible: true
        });
    };

    const validateInstallRequest = async () => {
        try {
            const response = await fetch('/install/validate', {
                method: 'POST',
                headers: withCsrfHeader({
                    'Content-Type': 'application/json'
                })
            });
            if (!response.ok) {
                showCsrfToast();
                return false;
            }
            const data = await response.json().catch(() => ({}));
            if (!data?.success) {
                showCsrfToast();
                return false;
            }
            return true;
        } catch (error) {
            showCsrfToast();
            return false;
        }
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

    const cleanupInstallFlow = () => {
        if (activeInstall?.controller) {
            activeInstall.controller.abort();
            if (activeInstall.pollTimer) {
                clearInterval(activeInstall.pollTimer);
            }
            if (activeInstall.fallbackTimer) {
                clearTimeout(activeInstall.fallbackTimer);
            }
            activeInstall = null;
        }
        stopSyncedSpinnerRotation();
        setUnloadGuard(false);
    };

    const getStepDefinition = (id) => INSTALLATION_STEPS.find((step) => step.id === id);

    const getProgressLabel = (step, status, message) => {
        if (!step) return message || '';
        if (status === STATUS.ERROR) {
            const normalized = normalizeInstallError(message || '');
            return normalized.summary || 'Installation failed.';
        }
        if (status === STATUS.COMPLETED) return step.done;
        return message || step.inProgress;
    };

    const updateInstallRow = (row, step, status, message, details) => {
        if (!row || !step) return;
        row.dataset.status = status;
        row.dataset.step = step.id;
        if (status !== STATUS.ERROR) {
            row.classList.remove('is-open');
            const toggle = row.querySelector('[data-install-toggle]');
            if (toggle) {
                toggle.setAttribute('aria-expanded', 'false');
            }
        }
        const label = getProgressLabel(step, status, message);
        const text = row.querySelector('[data-install-text]');
        if (text) {
            if (text.textContent !== label) {
                text.classList.remove('is-enter');
                text.textContent = label;
                text.classList.add('is-enter');
                requestAnimationFrame(() => {
                    text.classList.remove('is-enter');
                });
            }
        }

        const counter = row.querySelector('[data-install-counter]');
        if (counter) {
            const started = details?.containerStarted ?? 0;
            const total = details?.containerTotal;
            counter.textContent = (status === STATUS.IN_PROGRESS && total > 0 && started < total)
                ? `${started}/${total}`
                : '';
        }

        // Show/hide "Navigate to Console" button for account setup errors
        const consoleBtn = row.querySelector('[data-install-console]');
        if (consoleBtn) {
            const shouldShow = step.id === STEP_IDS.ACCOUNT_SETUP && status === STATUS.ERROR;
            consoleBtn.classList.toggle('is-hidden', !shouldShow);
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

    let spinnerAnimationFrame = null;
    const stopSyncedSpinnerRotation = () => {
        if (spinnerAnimationFrame) {
            cancelAnimationFrame(spinnerAnimationFrame);
            spinnerAnimationFrame = null;
        }
    };

    const startSyncedSpinnerRotation = (container) => {
        stopSyncedSpinnerRotation();
        if (!container) return;
        let startTime = null;
        const animate = (timestamp) => {
            if (!startTime) startTime = timestamp;
            const elapsed = timestamp - startTime;
            const rotation = ((elapsed / 1000) * 360 * 1.5) % 360;
            container.style.setProperty('--spinner-rotation', `${rotation}deg`);
            spinnerAnimationFrame = requestAnimationFrame(animate);
        };
        spinnerAnimationFrame = requestAnimationFrame(animate);
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
            if (!row.dataset.status || row.dataset.status !== STATUS?.ERROR) {
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
        updateInstallRow(row, step, STATUS.IN_PROGRESS);
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

    const buildRedirectUrl = (protocol) => {
        const dataset = getBodyDataset?.() ?? {};
        const rawDomain = (formState?.appDomain || dataset.defaultAppDomain || '').trim();
        if (!rawDomain) return '';
        const httpPort = (formState?.httpPort || dataset.defaultHttpPort || '').trim();
        const httpsPort = (formState?.httpsPort || dataset.defaultHttpsPort || '').trim();
        const hasPort = rawDomain.includes(':') || rawDomain.startsWith('[');
        let host = rawDomain;
        const hostForProtocol = extractHostname?.(rawDomain);
        const normalizedHost = hostForProtocol?.toLowerCase?.() ?? '';
        if (hostForProtocol === '0.0.0.0') {
            host = rawDomain.replace('0.0.0.0', 'localhost');
        } else if (normalizedHost === 'traefik') {
            host = rawDomain.replace(hostForProtocol, 'localhost');
        }
        const port = protocol === 'https' ? httpsPort : httpPort;
        const defaultPort = protocol === 'https' ? '443' : '80';
        if (!hasPort && port && port !== defaultPort) {
            host = `${host}:${port}`;
        }
        return `${protocol}://${host}`;
    };

    const normalizeHostname = (rawDomain) => {
        const hostname = extractHostname?.(rawDomain)?.toLowerCase?.() ?? '';
        if (hostname === '0.0.0.0' || hostname === 'traefik') return 'localhost';
        return hostname;
    };

    const canUseHttps = () => {
        const dataset = getBodyDataset?.() ?? {};
        const rawDomain = (formState?.appDomain || dataset.defaultAppDomain || '').trim();
        const httpsPort = (formState?.httpsPort || dataset.defaultHttpsPort || '').trim();
        if (!httpsPort || httpsPort === '0') return false;
        const hostname = normalizeHostname(rawDomain);
        return !isLocalHost?.(hostname) && !isIPAddress?.(hostname);
    };

    const pollCertificate = async (domain, port, maxAttempts, intervalMs) => {
        for (let i = 0; i < maxAttempts; i++) {
            try {
                const response = await fetch(
                    `/install/certificate?domain=${encodeURIComponent(domain)}&port=${encodeURIComponent(port)}`,
                    { cache: 'no-store' }
                );
                if (response.ok) {
                    const data = await response.json();
                    if (data.ready) return true;
                }
            } catch {
                // Installer server may have shut down
            }
            if (i < maxAttempts - 1) {
                await new Promise((resolve) => setTimeout(resolve, intervalMs));
            }
        }
        return false;
    };

    const redirectToApp = (protocol) => {
        const url = buildRedirectUrl(protocol);
        if (!url) return;
        fetch('/install/shutdown', { method: 'POST', headers: withCsrfHeader() }).catch(() => {});
        window.location.href = url;
    };

    const notifyInstallComplete = (installId, session) => {
        if (!installId) return Promise.resolve();
        const payload = { installId };
        const sessionSecret = session?.sessionSecret || session?.secret;
        const sessionId = session?.sessionId || session?.id;
        const sessionExpire = session?.sessionExpire || session?.expire;
        if (sessionSecret) {
            payload.sessionSecret = sessionSecret;
        }
        if (sessionId) {
            payload.sessionId = sessionId;
        }
        if (sessionExpire) {
            payload.sessionExpire = sessionExpire;
        }
        return fetch('/install/complete', {
            method: 'POST',
            headers: withCsrfHeader({
                'Content-Type': 'application/json'
            }),
            body: JSON.stringify(payload)
        }).catch(() => {});
    };

    const buildInstallPayload = (installId) => {
        const normalizedSecret = (formState?.opensslKey || '').trim();
        if (!normalizedSecret && generateSecretKey && !isUpgradeMode?.()) {
            formState.opensslKey = generateSecretKey();
        }
        const normalizedDomain = (formState?.appDomain || '').trim() || 'localhost';
        const normalizedHttpPort = (formState?.httpPort || '').trim() || '80';
        const normalizedHttpsPort = (formState?.httpsPort || '').trim() || '443';
        const normalizedEmail = (formState?.emailCertificates || '').trim() || (formState?.accountEmail || '').trim();
        const normalizedAssistantKey = (formState?.assistantOpenAIKey || '').trim();
        const normalizedAccountEmail = (formState?.accountEmail || '').trim();
        const normalizedAccountPassword = (formState?.accountPassword || '').trim();

        return {
            installId,
            httpPort: normalizedHttpPort,
            httpsPort: normalizedHttpsPort,
            database: formState?.database || 'mongodb',
            appDomain: normalizedDomain,
            emailCertificates: normalizedEmail,
            opensslKey: (formState?.opensslKey || '').trim(),
            assistantOpenAIKey: normalizedAssistantKey,
            accountEmail: normalizedAccountEmail,
            accountPassword: normalizedAccountPassword,
            migrate: formState?.migrate ?? false
        };
    };

    const fetchInstallStatus = async (installId) => {
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
            const processEvent = (rawEvent) => {
                if (!rawEvent) return;
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
                        const parsed = JSON.parse(data);
                        onEvent(eventName, parsed);
                    } catch (error) {
                        onEvent(eventName, { message: data });
                    }
                }
            };

            while (true) {
                const { value, done } = await reader.read();
                if (done) {
                    buffer = buffer.replace(/\r\n/g, '\n');
                    if (buffer.trim()) {
                        processEvent(buffer);
                    }
                    break;
                }
                buffer += decoder.decode(value, { stream: true });
                buffer = buffer.replace(/\r\n/g, '\n');
                let separatorIndex = buffer.indexOf('\n\n');

                while (separatorIndex !== -1) {
                    const rawEvent = buffer.slice(0, separatorIndex);
                    buffer = buffer.slice(separatorIndex + 2);
                    processEvent(rawEvent);
                    separatorIndex = buffer.indexOf('\n\n');
                }
            }
        } finally {
            try {
                reader.releaseLock();
            } catch (error) {}
        }
    };

    const initStep5 = (root) => {
        if (!root) return;
        let resolvedProtocol = 'http';

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
        startSyncedSpinnerRotation(list);

        list.innerHTML = '';
        const rowsById = new Map();
        const progressState = new Map();
        syncInstallLockFlag?.();
        applyLockPayload?.();
        applyBodyDefaults?.();

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
                        updateInstallRow(row, step, state.status || STATUS.IN_PROGRESS, state.message, state.details);
                        if (state.status === STATUS?.ERROR) {
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
                status: STATUS.IN_PROGRESS,
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
            if (step.id === STEP_IDS.ACCOUNT_SETUP && payload.details?.sessionSecret) {
                sseSessionDetails = payload.details;
            }
            progressState.set(step.id, {
                status: payload.status || STATUS.IN_PROGRESS,
                message: payload.message,
                details: payload.details
            });
            renderProgress();
            if (activeInstall) {
                activeInstall.lastEventAt = Date.now();
                if (payload.status === STATUS.ERROR) {
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
            if (payload.status === STATUS.ERROR) {
                showGlobalActions();
            }
            scheduleFallback();
        };

        const handleProgress = (payload) => {
            if (!payload || !payload.step) return;

            const existingState = progressState.get(payload.step);
            if (existingState && existingState.status === STATUS.COMPLETED && payload.status === STATUS.IN_PROGRESS) {
                return;
            }

            const step = getStepDefinition(payload.step) || {
                id: payload.step,
                inProgress: payload.message || payload.step,
                done: payload.message || payload.step
            };
            if (payload.status === STATUS.IN_PROGRESS) {
                const currentIndex = INSTALLATION_STEPS.findIndex((candidate) => candidate.id === step.id);
                if (currentIndex > 0) {
                    for (let i = 0; i < currentIndex; i += 1) {
                        const previousStep = INSTALLATION_STEPS[i];
                        const previousState = progressState.get(previousStep.id);
                        if (previousState && previousState.status !== STATUS.COMPLETED) {
                            progressState.set(previousStep.id, {
                                status: STATUS.COMPLETED,
                                message: previousStep.done,
                                details: previousState.details
                            });
                        }
                    }
                }
            }
            applyProgress(payload);
        };

        const applySnapshot = (snapshot) => {
            if (!snapshot || !snapshot.steps) return;
            let hasErrors = false;
            INSTALLATION_STEPS.forEach((step) => {
                const detail = snapshot.steps[step.id];
                if (!detail) return;
                progressState.set(step.id, {
                    status: detail.status,
                    message: detail.message,
                    details: snapshot.details?.[step.id]
                });
                if (detail.status === STATUS.ERROR) {
                    hasErrors = true;
                }
            });
            renderProgress();
            if (hasErrors) {
                showGlobalActions();
            }
        };

        const checkAllCompleted = () => {
            const allDone = INSTALLATION_STEPS.every((step) => {
                const state = progressState.get(step.id);
                return state && state.status === STATUS.COMPLETED;
            });
            if (!allDone) return;
            const accountState = progressState.get(STEP_IDS.ACCOUNT_SETUP);
            const sessionDetails = sseSessionDetails || accountState?.details;
            finalizeInstall();
            startSslCheck(sessionDetails);
        };

        const startPolling = () => {
            if (!activeInstall || activeInstall.pollTimer) return;
            activeInstall.pollTimer = setInterval(async () => {
                if (!activeInstall || activeInstall.completed) return;
                const snapshot = await fetchInstallStatus(activeInstall.installId);
                if (snapshot) {
                    applySnapshot(snapshot);
                    checkAllCompleted();
                }
            }, TIMINGS?.installPollInterval ?? 0);
        };

        const scheduleFallback = () => {
            if (!activeInstall) return;
            if (activeInstall.fallbackTimer) {
                clearTimeout(activeInstall.fallbackTimer);
            }
            activeInstall.fallbackTimer = setTimeout(() => {
                if (!activeInstall) return;
                startPolling();
            }, TIMINGS?.installFallbackDelay ?? 0);
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
            stopSyncedSpinnerRotation();
            setUnloadGuard(false);
            clearInstallLock?.();
        };

        const SSL_STEP = {
            id: STEP_IDS.SSL_CERTIFICATE,
            inProgress: 'Generating SSL certificate...',
            done: 'SSL certificate verified'
        };

        const REDIRECT_STEP = {
            id: STEP_IDS.REDIRECT,
            inProgress: 'Redirecting to console...',
            done: 'Redirecting to console...'
        };

        const showRedirectStep = (sessionDetails, protocol) => {
            animatePanelHeight(() => {
                progressState.set(REDIRECT_STEP.id, {
                    status: STATUS.IN_PROGRESS,
                    message: REDIRECT_STEP.inProgress
                });
                const row = ensureRow(REDIRECT_STEP);
                if (row) {
                    updateInstallRow(row, REDIRECT_STEP, STATUS.IN_PROGRESS, REDIRECT_STEP.inProgress);
                }
            });
            startSyncedSpinnerRotation(list);

            const completeId = activeInstall?.installId || getStoredInstallId?.();
            notifyInstallComplete(completeId, sessionDetails).finally(() => {
                setTimeout(() => redirectToApp(protocol), TIMINGS?.redirectDelay ?? 0);
            });
        };

        const startSslCheck = (sessionDetails) => {
            if (!canUseHttps()) {
                showRedirectStep(sessionDetails, 'http');
                return;
            }

            animatePanelHeight(() => {
                progressState.set(SSL_STEP.id, {
                    status: STATUS.IN_PROGRESS,
                    message: SSL_STEP.inProgress
                });
                const row = ensureRow(SSL_STEP);
                if (row) {
                    updateInstallRow(row, SSL_STEP, STATUS.IN_PROGRESS, SSL_STEP.inProgress);
                }
            });
            startSyncedSpinnerRotation(list);

            const dataset = getBodyDataset?.() ?? {};
            const rawDomain = (formState?.appDomain || dataset.defaultAppDomain || '').trim();
            const httpsPort = (formState?.httpsPort || dataset.defaultHttpsPort || '443').trim();
            const domain = normalizeHostname(rawDomain);
            pollCertificate(domain, httpsPort, 15, 2000).then((ready) => {
                stopSyncedSpinnerRotation();
                const certMessage = ready ? SSL_STEP.done : 'Certificate not ready, continuing over HTTP';
                animatePanelHeight(() => {
                    progressState.set(SSL_STEP.id, {
                        status: STATUS.COMPLETED,
                        message: certMessage
                    });
                    const row = ensureRow(SSL_STEP);
                    if (row) {
                        updateInstallRow(row, SSL_STEP, STATUS.COMPLETED, certMessage);
                    }
                });
                resolvedProtocol = ready ? 'https' : 'http';
                showRedirectStep(sessionDetails, resolvedProtocol);
            });
        };

        const startInstallStream = async (installId, options = {}) => {
            const isValid = await validateInstallRequest();
            if (!isValid) {
                return;
            }
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
            setInstallLock?.(installId, payload);
            setUnloadGuard(true);

            try {
                scheduleFallback();
                const response = await fetch('/install', {
                    method: 'POST',
                    headers: withCsrfHeader({
                        'Content-Type': 'application/json',
                        'Accept': 'text/event-stream'
                    }),
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
                            step: STEP_IDS.CONFIG_FILES,
                            status: STATUS.ERROR,
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
                    if (event === SSE_EVENTS.INSTALL_ID && data?.installId) {
                        activeInstall.installId = data.installId;
                        storeInstallId?.(data.installId);
                        return;
                    }
                    if (event === SSE_EVENTS.PROGRESS) {
                        handleProgress(data);
                        return;
                    }
                    if (event === SSE_EVENTS.DONE) {
                        // Mark every step as completed (preserving details
                        // from earlier progress events, e.g. session info).
                        INSTALLATION_STEPS.forEach((step) => {
                            const existing = progressState.get(step.id);
                            if (!existing || (existing.status !== STATUS.COMPLETED && existing.status !== STATUS.ERROR)) {
                                progressState.set(step.id, {
                                    status: STATUS.COMPLETED,
                                    message: step.done,
                                    details: existing?.details
                                });
                            }
                        });
                        renderProgress();

                        // If any step ended in error (e.g. account creation
                        // failed), stay on the progress screen so the user can
                        // see the error and choose to retry or navigate to the
                        // console manually — don't auto-redirect.
                        const hasErrors = INSTALLATION_STEPS.some((step) => {
                            const state = progressState.get(step.id);
                            return state && state.status === STATUS.ERROR;
                        });

                        if (hasErrors) {
                            finalizeInstall();
                            return;
                        }

                        const accountState = progressState.get(STEP_IDS.ACCOUNT_SETUP);
                        const sessionDetails = sseSessionDetails || accountState?.details;
                        finalizeInstall();
                        startSslCheck(sessionDetails);
                        return;
                    }
                    if (event === SSE_EVENTS.ERROR) {
                        if (data?.message) {
                            const existingError = Array.from(progressState.values()).some((state) => state?.status === STATUS.ERROR);
                            if (data.step || !existingError) {
                                let targetStep = data.step;
                                if (!targetStep) {
                                    for (const candidate of INSTALLATION_STEPS) {
                                        const state = progressState.get(candidate.id);
                                        if (!state || state.status !== STATUS.COMPLETED) {
                                            targetStep = candidate.id;
                                            break;
                                        }
                                    }
                                }
                                handleProgress({
                                    step: targetStep || STEP_IDS.CONFIG_FILES,
                                    status: STATUS.ERROR,
                                    message: data.message,
                                    details: data.details
                                });
                            }
                        }
                        finalizeInstall();
                    }
                });
                if (activeInstall && !activeInstall.completed) {
                    // Stream ended without a "done" event (e.g. browser
                    // throttled the background tab). Check if we're done.
                    checkAllCompleted();
                    if (!activeInstall?.completed) {
                        startPolling();
                    }
                }
            } catch (error) {
                if (!activeInstall || activeInstall.controller.signal.aborted) {
                    return;
                }
                startPolling();
            }
        };

        const isSnapshotTerminal = (snapshot) => {
            if (!snapshot?.steps) return 'empty';
            const stepEntries = Object.values(snapshot.steps);
            if (stepEntries.length === 0) return 'empty';
            const hasError = stepEntries.some((s) => s.status === STATUS.ERROR);
            if (hasError) return 'error';
            const allCompleted = INSTALLATION_STEPS.every((step) => {
                const detail = snapshot.steps[step.id];
                return detail && detail.status === STATUS.COMPLETED;
            });
            if (allCompleted) return 'completed';
            return false;
        };

        const resumeInstall = async (installId) => {
            const snapshot = await fetchInstallStatus(installId);
            const terminal = isSnapshotTerminal(snapshot);
            if (!snapshot || terminal) {
                if (terminal === 'completed') {
                    return 'completed';
                }
                return false;
            }
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

            const step = getStepDefinition(stepId);
            progressState.set(stepId, {
                status: STATUS.IN_PROGRESS,
                message: step?.inProgress || 'Retrying...'
            });

            const row = ensureRow(step);
            if (row) {
                updateInstallRow(row, step, STATUS.IN_PROGRESS, step.inProgress || 'Retrying...');
            }

            const installId = activeInstall?.installId || getInstallLock?.()?.installId || generateInstallId();
            storeInstallId?.(installId);
            startInstallStream(installId, { retryStep: stepId });
        };

        list.addEventListener('click', (event) => {
            const consoleButton = event.target.closest('[data-install-console]');
            const retryButton = event.target.closest('[data-install-retry]');

            if (consoleButton) {
                redirectToApp(resolvedProtocol);
                return;
            }

            if (retryButton) {
                const row = retryButton.closest('.install-row');
                const stepId = row?.dataset.step;
                retryInstallStep(stepId);
            }
        });

        const globalActions = root.querySelector('[data-install-global-actions]');

        const showGlobalActions = () => {
            if (globalActions) {
                globalActions.classList.remove('is-hidden');
            }
        };

        const performReset = async (hard) => {
            const installId = activeInstall?.installId || getInstallLock?.()?.installId || getStoredInstallId?.();

            try {
                const res = await fetch('/install/reset', {
                    method: 'POST',
                    headers: withCsrfHeader({ 'Content-Type': 'application/json' }),
                    body: JSON.stringify({ installId: installId || '', hard })
                });
                if (hard && !res.ok) {
                    const data = await res.json().catch(() => ({}));
                    showToast?.({
                        status: 'error',
                        title: 'Reset failed',
                        description: data?.message || 'Could not stop containers. Try running "docker compose down -v" manually.',
                        dismissible: true
                    });
                    return;
                }
            } catch (e) {
                console.error('Reset request failed:', e);
            }

            clearInstallLock?.();
            clearInstallId?.();
            cleanupInstallFlow();
            window.location.href = '/?step=1';
        };

        const startOverButton = root.querySelector('[data-install-start-over]');
        if (startOverButton) {
            startOverButton.addEventListener('click', () => performReset(false));
        }

        const hardResetButton = root.querySelector('[data-install-hard-reset]');
        if (hardResetButton) {
            hardResetButton.addEventListener('click', () => {
                const confirmed = window.confirm(
                    'This will stop all containers, remove all volumes (including database data, uploads, and certificates), and delete configuration files.\n\nThis action cannot be undone. Continue?'
                );
                if (confirmed) {
                    performReset(true);
                }
            });
        }

        // When the user switches back to this tab, check if installation
        // finished while the tab was in the background.
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible' && activeInstall && !activeInstall.completed) {
                checkAllCompleted();
            }
        });

        const startFreshInstall = () => {
            clearInstallId?.();
            clearInstallLock?.();
            const newInstallId = generateInstallId();
            storeInstallId?.(newInstallId);
            startInstallStream(newInstallId);
        };

        const recoverToLastStep = () => {
            clearInstallId?.();
            clearInstallLock?.();
            const url = new URL(window.location.href);
            const lastStep = url.searchParams.get('step');
            // Stay on the current URL so the user keeps their place;
            // only navigate away if we're already on step 5 (the
            // progress screen) since there's nothing to show.
            if (!lastStep || String(lastStep) === '5') {
                window.location.href = '/?step=1';
            }
        };

        const lock = getInstallLock?.();
        const existingInstallId = lock?.installId || getStoredInstallId?.();
        if (existingInstallId) {
            resumeInstall(existingInstallId).then((result) => {
                if (result === 'completed') {
                    // Install already finished — redirect to console
                    // instead of bouncing back to step 1.
                    stopSyncedSpinnerRotation();
                    setUnloadGuard(false);
                    clearInstallLock?.();
                    clearInstallId?.();
                    startSslCheck(null);
                } else if (!result) {
                    recoverToLastStep();
                }
            });
        } else {
            startFreshInstall();
        }
    };

    window.InstallerStepsProgress = {
        initStep5,
        cleanupInstallFlow,
        validateInstallRequest
    };
})();

(() => {
    const {
        INSTALLATION_STEPS,
        TIMINGS,
        isMockMode,
        isMockErrorMode,
        isMockProgressMode,
        isMockToastMode,
        getBodyDataset,
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
    const { extractHostname, isLocalHost } = window.InstallerStepsValidation || {};
    const { generateSecretKey } = window.InstallerStepsUI || {};
    const { showToast } = window.InstallerToast || {};

    let activeInstall = null;
    let unloadGuard = null;
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
        if (isMockProgressMode?.()) return true;
        if (isMockToastMode?.()) {
            showCsrfToast();
            return false;
        }
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
        return step.inProgress;
    };

    const updateInstallRow = (row, step, status, message) => {
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

    const buildRedirectUrl = () => {
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
        let protocol = 'http';
        let port = httpPort;
        if (httpsPort && httpsPort !== '0' && !isLocalHost?.(normalizedHost)) {
            protocol = 'https';
            port = httpsPort;
        }
        if (!hasPort && port && ((protocol === 'http' && port !== '80') || (protocol === 'https' && port !== '443'))) {
            host = `${rawDomain}:${port}`;
        }
        return `${protocol}://${host}`;
    };

    const redirectToApp = () => {
        if (isMockMode?.()) return;
        const url = buildRedirectUrl();
        if (!url) return;
        window.location.href = url;
    };

    const notifyInstallComplete = (installId) => {
        if (isMockProgressMode?.()) return Promise.resolve();
        if (!installId) return Promise.resolve();
        return fetch('/install/complete', {
            method: 'POST',
            headers: withCsrfHeader({
                'Content-Type': 'application/json'
            }),
            body: JSON.stringify({ installId })
        }).catch(() => {});
    };

    const buildInstallPayload = (installId) => {
        const normalizedSecret = (formState?.opensslKey || '').trim();
        if (!normalizedSecret && generateSecretKey) {
            formState.opensslKey = generateSecretKey();
        }
        const normalizedDomain = (formState?.appDomain || '').trim() || 'localhost';
        const normalizedHttpPort = (formState?.httpPort || '').trim() || '80';
        const normalizedHttpsPort = (formState?.httpsPort || '').trim() || '443';
        const normalizedEmail = (formState?.emailCertificates || '').trim();
        const normalizedAssistantKey = (formState?.assistantOpenAIKey || '').trim();

        return {
            installId,
            httpPort: normalizedHttpPort,
            httpsPort: normalizedHttpsPort,
            database: formState?.database || 'mongodb',
            appDomain: normalizedDomain,
            emailCertificates: normalizedEmail,
            opensslKey: (formState?.opensslKey || '').trim(),
            assistantOpenAIKey: normalizedAssistantKey
        };
    };

    const fetchInstallStatus = async (installId) => {
        if (isMockProgressMode?.()) return null;
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

    const initStep4 = (root) => {
        if (!root) return;
        if (isMockProgressMode?.()) {
            clearInstallLock?.();
            clearInstallId?.();
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
                        updateInstallRow(row, step, state.status || STATUS.IN_PROGRESS, state.message);
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
            scheduleFallback();
        };

        const handleProgress = (payload) => {
            if (!payload || !payload.step) return;

            const existingState = progressState.get(payload.step);
            if (existingState && existingState.status === STATUS.COMPLETED && payload.status === STATUS.IN_PROGRESS) {
                return;
            }

            if (pendingProgressTimer && pendingProgressStep === payload.step && payload.status !== STATUS.IN_PROGRESS) {
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
            if (payload.status === STATUS.IN_PROGRESS) {
                const currentIndex = INSTALLATION_STEPS.findIndex((candidate) => candidate.id === step.id);
                const completionTargets = [];
                if (currentIndex > 0) {
                    for (let i = 0; i < currentIndex; i += 1) {
                        const previousStep = INSTALLATION_STEPS[i];
                        const previousState = progressState.get(previousStep.id);
                        if (previousState && previousState.status !== STATUS.COMPLETED) {
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
                                    status: STATUS.COMPLETED,
                                    message: previousStep.done,
                                    details
                                });
                            });
                            renderProgress();
                        }, TIMINGS?.progressCompleteDelay ?? 0);
                    pendingProgressTimer = setTimeout(() => {
                        pendingProgressTimer = null;
                        pendingProgressStep = null;
                        applyProgress(payload);
                    }, TIMINGS?.progressTransitionDelay ?? 0);
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
            if (isMockProgressMode?.()) return;
            if (!activeInstall || activeInstall.pollTimer) return;
            activeInstall.pollTimer = setInterval(async () => {
                if (!activeInstall || activeInstall.completed) return;
                const snapshot = await fetchInstallStatus(activeInstall.installId);
                if (snapshot) {
                    applySnapshot(snapshot);
                }
            }, TIMINGS?.installPollInterval ?? 0);
        };

        const scheduleFallback = () => {
            if (isMockProgressMode?.()) return;
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
            setUnloadGuard(false);
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
            if (!isMockProgressMode?.()) {
                setInstallLock?.(installId, payload);
                setUnloadGuard(true);
            }

            try {
                if (options.forceMock || isMockProgressMode?.()) {
                    simulateInstallProgress(options);
                    return;
                }
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
                        const lastStep = INSTALLATION_STEPS[INSTALLATION_STEPS.length - 1];
                        if (lastStep) {
                        const lastState = progressState.get(lastStep.id);
                        if (!lastState || lastState.status !== STATUS.COMPLETED) {
                            progressState.set(lastStep.id, {
                                status: STATUS.COMPLETED,
                                message: lastStep.done,
                                details: lastState?.details
                            });
                            renderProgress();
                        }
                        }
                        finalizeInstall();
                        notifyInstallComplete(activeInstall?.installId);
                        setTimeout(() => redirectToApp(), TIMINGS?.redirectDelay ?? 0);
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
            startInstallStream(installId, { retryStep: stepId, forceMock: isMockProgressMode?.() });
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
            const errorStepId = isMockErrorMode?.() && !options.retryStep
                ? STEP_IDS.DOCKER_CONTAINERS
                : null;
            const mockErrorDetails = (window.InstallerConstants || {}).mockErrorDetails || {
                output: 'Error response from daemon: manifest for appwrite/appwrite:local not found: manifest unknown',
                trace: '#0 /usr/src/code/src/Appwrite/Platform/Tasks/Install.php(540): Appwrite\\\\Platform\\\\Tasks\\\\Install->performInstallation(...)\\n#1 {main}'
            };
            const advance = () => {
                if (index > 0) {
                    const previous = INSTALLATION_STEPS[index - 1];
                    const previousState = progressState.get(previous.id);
                    if (!previousState || previousState.status !== STATUS.COMPLETED) {
                        handleProgress({
                            step: previous.id,
                            status: STATUS.COMPLETED,
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
                    status: STATUS.IN_PROGRESS,
                    message: step.inProgress
                });

                if (errorStepId && step.id === errorStepId) {
                    index += 1;
                    activeInstall.pollTimer = setTimeout(() => {
                        handleProgress({
                            step: step.id,
                            status: STATUS.ERROR,
                            message: 'Failed to start containers',
                            details: mockErrorDetails
                        });
                        finalizeInstall();
                    }, TIMINGS?.mockStepDelay ?? 0);
                    return;
                }

                index += 1;
                activeInstall.pollTimer = setTimeout(advance, TIMINGS?.mockStepDelay ?? 0);
            };
            advance();
        };

        if (isMockProgressMode?.()) {
            const newInstallId = generateInstallId();
            storeInstallId?.(newInstallId);
            startInstallStream(newInstallId);
            return;
        }

        const lock = getInstallLock?.();
        const existingInstallId = lock?.installId || getStoredInstallId?.();
        if (existingInstallId) {
            resumeInstall(existingInstallId).then((resumed) => {
                if (!resumed) {
                    clearInstallId?.();
                    clearInstallLock?.();
                    const newInstallId = generateInstallId();
                    storeInstallId?.(newInstallId);
                    startInstallStream(newInstallId);
                }
            });
        } else {
            const newInstallId = generateInstallId();
            storeInstallId?.(newInstallId);
            startInstallStream(newInstallId);
        }
    };

    window.InstallerStepsProgress = {
        initStep4,
        cleanupInstallFlow,
        validateInstallRequest
    };
})();

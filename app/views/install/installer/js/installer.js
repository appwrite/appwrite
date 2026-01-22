(() => {
    const stepContainer = document.querySelector('.installer-step');
    const installerCard = document.querySelector('.installer-card');
    const backButton = document.querySelector('[data-action="back"]');
    const nextButton = document.querySelector('[data-action="next"]');
    const installScreen = document.querySelector('.install-screen-content');
    const indicatorNodes = Array.from(document.querySelectorAll('.step-indicator'));
    const STEP_TRANSITION_TIMEOUT = window.InstallerConstants?.stepTransitionMs ?? 260;

    if (!stepContainer || !installerCard) return;

    const isUpgrade = document.body?.dataset.upgrade === 'true';
    const stepFlow = isUpgrade ? [1, 3, 4] : [1, 2, 3, 4];
    const cardSteps = stepFlow.filter((step) => step !== 4);

    const normalizeStep = (step) => {
        const numeric = clampStep(step);
        if (stepFlow.includes(numeric)) return numeric;
        if (numeric <= stepFlow[0]) return stepFlow[0];
        for (let i = 0; i < stepFlow.length; i += 1) {
            if (numeric < stepFlow[i]) {
                return stepFlow[i];
            }
        }
        return stepFlow[stepFlow.length - 1];
    };

    const buildStepConfig = () => {
        const config = {};
        stepFlow.forEach((step, index) => {
            if (step === 4) {
                config[step] = { back: { target: null }, next: { target: null } };
                return;
            }
            const prev = stepFlow[index - 1] ?? null;
            const next = stepFlow[index + 1] ?? null;
            const label = next === 4 ? (isUpgrade ? 'Upgrade' : 'Install') : 'Next';
            config[step] = {
                back: { target: prev },
                next: { label, target: next }
            };
        });
        return config;
    };

    const STEP_CONFIG = buildStepConfig();

    const stepCache = new Map();
    let maxStepHeight = 0;
    let isTransitioning = false;
    let pendingStep = null;
    let pendingPushState = false;

    const clampStep = (step) => Math.max(1, Math.min(4, step));
    const isInstallLocked = () => Boolean(window.InstallerSteps?.isInstallLocked?.());

    const scrollToFirstError = (panel) => {
        if (!panel) return;
        const getErrorNode = () => panel.querySelector('.field-error.is-visible')
            || panel.querySelector('.field-error')
            || panel.querySelector('.input-field.is-error, .input-action.is-error');
        const container = panel.closest('.step-panel') || panel;
        const attemptScroll = () => {
            const target = getErrorNode();
            if (!target || typeof target.getBoundingClientRect !== 'function') return false;
            const targetRect = target.getBoundingClientRect();
            const containerRect = container.getBoundingClientRect();
            const targetTop = targetRect.top - containerRect.top + container.scrollTop;
            const targetBottom = targetTop + targetRect.height;
            const viewTop = container.scrollTop;
            const viewBottom = viewTop + containerRect.height;
            const padding = 12;

            let nextScrollTop = viewTop;
            if (targetTop < viewTop + padding) {
                nextScrollTop = Math.max(0, targetTop - padding);
            } else if (targetBottom > viewBottom - padding) {
                nextScrollTop = Math.max(0, targetBottom - containerRect.height + padding);
            }

            if (Math.abs(nextScrollTop - viewTop) < 1) {
                return false;
            }

            container.scrollTo({ top: nextScrollTop, behavior: 'smooth' });
            return true;
        };

        let remaining = 20;
        let lastScrollTop = -1;
        const settle = () => {
            if (remaining <= 0) return;
            const moved = attemptScroll();
            remaining -= 1;
            const currentTop = container.scrollTop;
            const delta = Math.abs(currentTop - lastScrollTop);
            lastScrollTop = currentTop;
            if (!moved && delta < 0.5) {
                return;
            }
            requestAnimationFrame(settle);
        };
        requestAnimationFrame(settle);
    };

    const getStepFromUrl = () => {
        const url = new URL(window.location.href);
        const step = Number(url.searchParams.get('step') || 1);
        return normalizeStep(Number.isNaN(step) ? 1 : step);
    };

    const buildStepUrl = (step) => {
        const url = new URL(window.location.href);
        url.searchParams.set('step', step);
        return url;
    };

    const setStepInUrl = (step, pushState) => {
        const url = new URL(window.location.href);
        url.searchParams.set('step', step);

        if (pushState) {
            window.history.pushState({ step }, '', url.toString());
        }

        return url;
    };

    const updateActionBar = (step) => {
        const config = STEP_CONFIG[step] || STEP_CONFIG[1];
        if (!backButton || !nextButton) return;
        const locked = isInstallLocked();

        const setButtonLabel = (button, label) => {
            if (!button) return;
            let text = button.querySelector('.button-text');
            if (!text) {
                text = document.createElement('span');
                text.className = 'button-text typography-text-m-500';
                button.textContent = '';
                button.appendChild(text);
            }
            text.textContent = label;
        };

        if (!locked && config.back?.target) {
            backButton.disabled = false;
            backButton.setAttribute('data-step-target', String(config.back.target));
        } else {
            backButton.disabled = true;
            backButton.removeAttribute('data-step-target');
        }
        setButtonLabel(backButton, 'Back');

        if (!locked && config.next?.target) {
            setButtonLabel(nextButton, config.next?.label || 'Next');
            nextButton.setAttribute('data-step-target', String(config.next?.target || 1));
            nextButton.disabled = false;
        } else {
            setButtonLabel(nextButton, config.next?.label || 'Next');
            nextButton.removeAttribute('data-step-target');
            nextButton.disabled = true;
        }

        indicatorNodes.forEach((node, index) => {
            const isVisible = index < cardSteps.length;
            node.classList.toggle('is-hidden', !isVisible);
            if (!isVisible) {
                node.classList.remove('is-active');
                return;
            }
            node.classList.toggle('is-active', cardSteps[index] === step);
        });

        installerCard.setAttribute('data-step', String(step));
        document.body.dataset.step = String(step);
        if (locked) {
            document.body.dataset.installLocked = 'true';
        } else {
            delete document.body.dataset.installLocked;
        }
    };

    const measureStepHeight = (panel) => {
        if (!panel) return;
        const height = panel.getBoundingClientRect().height;
        if (!height) return;
        maxStepHeight = Math.max(maxStepHeight, height);
        stepContainer.style.setProperty('--step-min-height', `${maxStepHeight}px`);
    };

    const runStepInit = (step, rootElement) => {
        if (!window.InstallerSteps || typeof window.InstallerSteps.initStep !== 'function') return;
        const root = rootElement || stepContainer;
        window.InstallerSteps.initStep(step, root);
        updateActionBar(step);
    };

    const fetchStepHtml = (step, url) => {
        if (stepCache.has(step)) {
            return Promise.resolve(stepCache.get(step));
        }

        const fetchUrl = new URL(url);
        fetchUrl.searchParams.set('partial', '1');

        return fetch(fetchUrl.toString(), {
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
            .then((response) => {
                if (!response.ok) {
                    throw new Error('Failed to load step');
                }
                return response.text();
            })
            .then((html) => {
                stepCache.set(step, html);
                return html;
            });
    };

    const preloadSteps = (steps) => {
        const current = getStepFromUrl();
        const targets = steps.filter((step) => step !== current);

        return Promise.all(
            targets.map((step) => {
                const url = buildStepUrl(step);
                return fetchStepHtml(step, url)
                    .then((html) => {
                        const panel = document.createElement('div');
                        panel.className = 'step-panel is-measure';
                        panel.innerHTML = html;
                        stepContainer.appendChild(panel);
                        panel.getBoundingClientRect();
                        measureStepHeight(panel);
                        panel.remove();
                    })
                    .catch(() => null);
            })
        );
    };

    const swapPanels = (step, html, onDone) => {
        const activePanel = stepContainer.querySelector('.step-panel');

        const measurePanel = document.createElement('div');
        measurePanel.className = 'step-panel is-measure';
        measurePanel.innerHTML = html;
        stepContainer.appendChild(measurePanel);
        measurePanel.getBoundingClientRect();
        measureStepHeight(measurePanel);
        measurePanel.remove();

        const newPanel = document.createElement('div');
        newPanel.className = 'step-panel is-entering';
        newPanel.innerHTML = html;
        stepContainer.appendChild(newPanel);
        runStepInit(step, newPanel);

        newPanel.getBoundingClientRect();

        requestAnimationFrame(() => {
            newPanel.classList.remove('is-entering');
            newPanel.classList.add('is-active');
            if (activePanel) {
                activePanel.classList.add('is-exiting');
            }
        });

        const finalize = () => {
            if (activePanel && activePanel.parentNode) {
                activePanel.parentNode.removeChild(activePanel);
            }
            newPanel.classList.remove('is-entering');
            if (typeof onDone === 'function') {
                onDone();
            }
        };

        const prefersReduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (prefersReduced) {
            finalize();
            return;
        }

        let finished = false;
        const finishOnce = () => {
            if (finished) return;
            finished = true;
            finalize();
        };

        newPanel.addEventListener(
            'transitionend',
            (event) => {
                if (event.propertyName === 'opacity') {
                    finishOnce();
                }
            },
            { once: true }
        );

        setTimeout(finishOnce, STEP_TRANSITION_TIMEOUT);
    };

    const showInstallScreen = (step, html) => {
        if (!installScreen) return;
        installScreen.innerHTML = html;
        runStepInit(step, installScreen);
    };

    const hideInstallScreen = () => {
        if (!installScreen) return;
        installScreen.innerHTML = '';
    };

    const loadStep = (step, pushState) => {
        const targetStep = normalizeStep(Number(step));
        const currentStep = getStepFromUrl();
        if (targetStep === currentStep && pushState) return;

        isTransitioning = true;
        const url = setStepInUrl(targetStep, pushState);

        fetchStepHtml(targetStep, url)
            .then((html) => {
                if (targetStep === 4) {
                    showInstallScreen(targetStep, html);
                    isTransitioning = false;
                    if (pendingStep !== null && pendingStep !== targetStep) {
                        const nextStep = pendingStep;
                        const nextPushState = pendingPushState;
                        pendingStep = null;
                        pendingPushState = false;
                        loadStep(nextStep, nextPushState);
                        return;
                    }
                    pendingStep = null;
                    pendingPushState = false;
                    return;
                }

                hideInstallScreen();
                swapPanels(targetStep, html, () => {
                    isTransitioning = false;
                    if (pendingStep !== null && pendingStep !== targetStep) {
                        const nextStep = pendingStep;
                        const nextPushState = pendingPushState;
                        pendingStep = null;
                        pendingPushState = false;
                        loadStep(nextStep, nextPushState);
                        return;
                    }
                    pendingStep = null;
                    pendingPushState = false;
                });
            })
            .catch(() => {
                isTransitioning = false;
                window.location.href = url.toString();
            });
    };

    const requestStep = (step, pushState) => {
        const targetStep = normalizeStep(Number(step));
        if (isInstallLocked() && targetStep !== 4) {
            loadStep(4, true);
            return;
        }
        if (isTransitioning) {
            pendingStep = targetStep;
            pendingPushState = pendingPushState || pushState;
            return;
        }
        loadStep(targetStep, pushState);
    };

    document.addEventListener('click', (event) => {
        const button = event.target.closest('[data-step-target]');
        if (!button || button.disabled) return;
        event.preventDefault();
        const target = button.getAttribute('data-step-target');
        if (!target) return;
        const action = button.getAttribute('data-action');
        if (action === 'next') {
            const currentStep = getStepFromUrl();
            const panel = stepContainer.querySelector('.step-panel') || stepContainer;
            const validator = window.InstallerSteps?.validateStep;
            if (typeof validator === 'function') {
                const valid = validator(currentStep, panel);
                if (!valid) {
                    scrollToFirstError(panel);
                    return;
                }
            }
        }
        if (isInstallLocked() && Number(target) !== 4) {
            requestStep(4, true);
            return;
        }
        requestStep(target, true);
    });

    window.addEventListener('popstate', (event) => {
        const step = event.state?.step || getStepFromUrl();
        if (isInstallLocked() && Number(step) !== 4) {
            requestStep(4, false);
            return;
        }
        requestStep(step, false);
    });

    document.addEventListener('DOMContentLoaded', () => {
        let step = getStepFromUrl();
        if (isInstallLocked() && step !== 4) {
            const url = buildStepUrl(4);
            window.history.replaceState({ step: 4 }, '', url.toString());
            step = 4;
        } else {
            const url = buildStepUrl(step);
            window.history.replaceState({ step }, '', url.toString());
        }
        const activePanel = stepContainer.querySelector('.step-panel') || stepContainer;
        runStepInit(step, activePanel);
        measureStepHeight(activePanel);
        if (step === 4 && installScreen) {
            runStepInit(step, installScreen);
        }
        const preload = () => {
            measureStepHeight(activePanel);
            preloadSteps(cardSteps);
        };
        if (document.fonts && document.fonts.ready) {
            document.fonts.ready.then(preload).catch(preload);
        } else {
            preload();
        }
    });
})();

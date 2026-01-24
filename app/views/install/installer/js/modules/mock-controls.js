(() => {
    const { isMockMode, MOCK_SETTINGS_KEY, readMockSettings } = window.InstallerMock || {};
    if (!isMockMode?.()) return;

    const dialog = document.querySelector('[data-mock-dialog]');
    if (!dialog) return;

    const footerLogo = document.querySelector('.installer-footer .appwrite-logo');
    const closeButtons = dialog.querySelectorAll('[data-mock-close]');
    const toggleInputs = Array.from(dialog.querySelectorAll('[data-mock-toggle]'));

    const readSettings = () => readMockSettings?.() ?? {};

    const writeSettings = (settings) => {
        try {
            sessionStorage.setItem(MOCK_SETTINGS_KEY, JSON.stringify(settings));
        } catch (error) {}
    };

    const normalizeSettings = (settings) => ({
        error: Boolean(settings?.error),
        toast: Boolean(settings?.toast),
        accountError: Boolean(settings?.accountError)
    });

    const applySettingsToUI = (settings) => {
        const normalized = normalizeSettings(settings);
        toggleInputs.forEach((input) => {
            const key = input.dataset.mockToggle;
            if (!key) return;
            input.checked = Boolean(normalized[key]);
            if (key === 'accountError') {
                input.disabled = normalized.error;
                input.closest('.mock-control')?.classList.toggle('is-disabled', normalized.error);
            }
            if (key === 'error') {
                input.disabled = normalized.accountError;
                input.closest('.mock-control')?.classList.toggle('is-disabled', normalized.accountError);
            }
        });
    };

    const updateSetting = (key, value) => {
        const current = normalizeSettings(readSettings());
        const next = { ...current, [key]: value };

        // Make error and accountError mutually exclusive
        if (key === 'error' && value) {
            next.accountError = false;
        }
        if (key === 'accountError' && value) {
            next.error = false;
        }

        writeSettings(next);
        applySettingsToUI(next);
    };

    const openDialog = () => {
        applySettingsToUI(readSettings());
        if (typeof dialog.showModal === 'function') {
            dialog.showModal();
        } else {
            dialog.setAttribute('open', '');
        }
    };

    const closeDialog = () => {
        if (dialog.open) {
            dialog.close();
        } else {
            dialog.removeAttribute('open');
        }
    };

    footerLogo?.addEventListener('dblclick', openDialog);
    closeButtons.forEach((button) => button.addEventListener('click', closeDialog));
    dialog.addEventListener('cancel', (event) => {
        event.preventDefault();
        closeDialog();
    });
    dialog.addEventListener('click', (event) => {
        if (event.target === dialog) {
            closeDialog();
        }
    });

    toggleInputs.forEach((input) => {
        input.addEventListener('change', (event) => {
            const key = event.target?.dataset?.mockToggle;
            if (!key) return;
            updateSetting(key, event.target.checked);
        });
    });

    applySettingsToUI(readSettings());
})();

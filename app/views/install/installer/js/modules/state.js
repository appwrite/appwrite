(() => {
    const {
        getBodyDataset,
        isUpgradeMode,
        getLockedDatabase
    } = window.InstallerStepsContext || {};

    const INSTALL_LOCK_KEY = 'appwrite-install-lock';
    const INSTALL_ID_KEY = 'appwrite-install-id';
    const INSTALL_LOCK_LOCAL_KEY = 'appwrite-install-lock-backup';
    const INSTALL_ID_LOCAL_KEY = 'appwrite-install-id-backup';

    const formState = {
        appDomain: null,
        database: null,
        httpPort: null,
        httpsPort: null,
        emailCertificates: null,
        opensslKey: null,
        assistantOpenAIKey: null,
        accountEmail: null,
        accountPassword: null
    };

    const dispatchStateChange = (key) => {
        if (!key || typeof document === 'undefined') return;
        try {
            document.dispatchEvent(new CustomEvent('installer:state-change', {
                detail: { key, value: formState[key] }
            }));
        } catch (error) {}
    };

    const setStateIfEmpty = (key, value) => {
        if (value === null || value === undefined || value === '') return;
        if (formState[key] === null || formState[key] === undefined || formState[key] === '') {
            formState[key] = value;
        }
    };

    const applyBodyDefaults = () => {
        const data = getBodyDataset?.() ?? {};
        setStateIfEmpty('appDomain', data.defaultAppDomain);
        setStateIfEmpty('httpPort', data.defaultHttpPort);
        setStateIfEmpty('httpsPort', data.defaultHttpsPort);
        setStateIfEmpty('emailCertificates', data.defaultEmailCertificates);
        setStateIfEmpty('opensslKey', data.defaultSecretKey);
        setStateIfEmpty('assistantOpenAIKey', data.defaultAssistantOpenaiKey);
        if (data.lockedDatabase) {
            formState.database = data.lockedDatabase;
        }
        if (!isUpgradeMode?.()) {
            setStateIfEmpty('database', data.defaultDatabase);
        }
    };

    const getInstallLock = () => {
        try {
            const raw = sessionStorage.getItem(INSTALL_LOCK_KEY);
            if (raw) {
                const parsed = JSON.parse(raw);
                if (parsed && typeof parsed === 'object') return parsed;
            }
        } catch (error) {}

        try {
            const raw = localStorage.getItem(INSTALL_LOCK_LOCAL_KEY);
            if (raw) {
                const parsed = JSON.parse(raw);
                if (parsed && typeof parsed === 'object') {
                    sessionStorage.setItem(INSTALL_LOCK_KEY, raw);
                    return parsed;
                }
            }
        } catch (error) {}

        return null;
    };

    const setInstallLock = (installId, payload) => {
        const sanitizedPayload = payload ? { ...payload } : null;
        if (sanitizedPayload) {
            delete sanitizedPayload.opensslKey;
            delete sanitizedPayload.accountPassword;
            delete sanitizedPayload.assistantOpenAIKey;
        }
        const lock = {
            installId,
            payload: sanitizedPayload,
            startedAt: Date.now()
        };
        try {
            sessionStorage.setItem(INSTALL_LOCK_KEY, JSON.stringify(lock));
        } catch (error) {}
        try {
            localStorage.setItem(INSTALL_LOCK_LOCAL_KEY, JSON.stringify(lock));
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
        try {
            localStorage.removeItem(INSTALL_LOCK_LOCAL_KEY);
        } catch (error) {}
        if (document.body) {
            delete document.body.dataset.installLocked;
        }
    };

    const isInstallLocked = () => {
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

    const applyLockPayload = () => {
        const lock = getInstallLock();
        if (!lock || !lock.payload) return;
        const payload = lock.payload;
        setStateIfEmpty('appDomain', payload.appDomain);
        setStateIfEmpty('database', payload.database);
        setStateIfEmpty('httpPort', payload.httpPort);
        setStateIfEmpty('httpsPort', payload.httpsPort);
        setStateIfEmpty('emailCertificates', payload.emailCertificates);
        setStateIfEmpty('accountEmail', payload.accountEmail);
    };

    const getStoredInstallId = () => {
        try {
            const val = sessionStorage.getItem(INSTALL_ID_KEY);
            if (val) return val;
        } catch (error) {}
        try {
            return localStorage.getItem(INSTALL_ID_LOCAL_KEY);
        } catch (error) {}
        return null;
    };

    const storeInstallId = (installId) => {
        try {
            sessionStorage.setItem(INSTALL_ID_KEY, installId);
        } catch (error) {}
        try {
            localStorage.setItem(INSTALL_ID_LOCAL_KEY, installId);
        } catch (error) {}
    };

    const clearInstallId = () => {
        try {
            sessionStorage.removeItem(INSTALL_ID_KEY);
        } catch (error) {}
        try {
            localStorage.removeItem(INSTALL_ID_LOCAL_KEY);
        } catch (error) {}
    };

    window.InstallerStepsState = {
        formState,
        dispatchStateChange,
        setStateIfEmpty,
        applyBodyDefaults,
        applyLockPayload,
        getInstallLock,
        setInstallLock,
        clearInstallLock,
        isInstallLocked,
        syncInstallLockFlag,
        getStoredInstallId,
        storeInstallId,
        clearInstallId,
        getLockedDatabase: getLockedDatabase || (() => '')
    };
})();

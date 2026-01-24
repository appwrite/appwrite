(() => {
    const getBodyDataset = () => document.body?.dataset ?? {};
    const isMockMode = () => getBodyDataset().installMode === 'mock'
        || (typeof navigator !== 'undefined' && navigator.webdriver);

    const MOCK_SETTINGS_KEY = 'appwrite-installer-mock-settings';

    const readMockSettings = () => {
        if (typeof window === 'undefined') return {};
        try {
            const raw = sessionStorage.getItem(MOCK_SETTINGS_KEY);
            if (!raw) return {};
            const parsed = JSON.parse(raw);
            if (!parsed || typeof parsed !== 'object') return {};
            return parsed;
        } catch (error) {
            return {};
        }
    };

    const getMockFlag = (key) => {
        const settings = readMockSettings();
        return Boolean(settings?.[key]);
    };

    const isMockErrorMode = () => isMockMode() && getMockFlag('error');
    const isMockToastMode = () => isMockMode() && getMockFlag('toast');
    const isMockAccountErrorMode = () => isMockMode() && getMockFlag('accountError');
    const isMockProgressMode = () => isMockMode() || isMockErrorMode();

    window.InstallerMock = Object.freeze({
        isMockMode,
        isMockErrorMode,
        isMockAccountErrorMode,
        isMockToastMode,
        isMockProgressMode,
        MOCK_SETTINGS_KEY,
        readMockSettings
    });
})();

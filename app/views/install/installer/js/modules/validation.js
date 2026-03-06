(() => {
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

    const isValidPassword = (value) => {
        if (!value) return false;
        return value.length >= 8 && /\S/.test(value);
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

    window.InstallerStepsValidation = {
        isValidEmail,
        isValidPort,
        isValidPassword,
        isValidHostnameInput,
        extractHostname,
        isLocalHost
    };
})();

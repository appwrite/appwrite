import { ensure, isDefined, isNotBlank, isString } from 'tiny-types';

export interface CreateUrlOptions {
    protocol?: string;
    hostname: string;
    port?: string | number;
    username?: string;
    password?: string;
}

export function createUrl(options: CreateUrlOptions): URL {
    const hostname  = ensure('hostname', options?.hostname, isString(), isNotBlank()).trim();
    const port      = options?.port
        ? ':' + options?.port
        : (options?.protocol ? undefined : ':80');

    return new URL([
        options?.protocol && protocolFrom(options?.protocol),
        (options?.username || options?.password) && credentialsFrom(options.username, options.password),
        hostname,
        port,
    ].filter(Boolean).join(''));
}

function protocolFrom(protocol?: string): string {
    const protocolName = protocol.match(/([A-Za-z]+)[/:]*/)[1];

    ensure('hostname', protocolName, isDefined());

    return protocolName + '://';
}

function credentialsFrom(username?: string, password?: string): string {
    return [
        username && encodeURIComponent(username),
        password && ':' + encodeURIComponent(password),
        '@'
    ].filter(Boolean).join('');
}

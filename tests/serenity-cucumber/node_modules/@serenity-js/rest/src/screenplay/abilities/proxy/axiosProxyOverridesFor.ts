import type * as http from 'http';
import { ensure, isDefined } from 'tiny-types';

import type { AxiosRequestConfigDefaults } from '../AxiosRequestConfigDefaults';
import { createUrl } from './createUrl';
import { ProxyAgent } from './ProxyAgent';

/**
 * @param options
 */
export function axiosProxyOverridesFor<Data = any>(options: AxiosRequestConfigDefaults<Data>): {
    proxy: false, httpAgent: http.Agent, httpsAgent: http.Agent
} {
    const envProxyOverride: string | false = options.proxy
        && createUrl({
            username: options.proxy?.auth?.username,
            password: options.proxy?.auth?.password,
            protocol: options.proxy?.protocol,
            hostname: ensure('proxy.host', options.proxy?.host, isDefined()),
            port: options.proxy?.port
        }).toString();

    const agent = new ProxyAgent({
        httpAgent: options.httpAgent,
        httpsAgent: options.httpsAgent,

        // if there's a specific proxy override configured, use it
        // if not - detect proxy automatically based on env variables
        getProxyForUrl: envProxyOverride
            ? (url_: string) => envProxyOverride
            : undefined,
    });

    return {
        proxy: false,
        httpAgent: agent,
        httpsAgent: agent,
    };
}

import { Duration } from '@serenity-js/core';
import axios, { Axios, type AxiosInstance, type AxiosRequestConfig } from 'axios';

import { axiosProxyOverridesFor } from './axiosProxyOverridesFor';
import type { AxiosRequestConfigDefaults, AxiosRequestConfigProxyDefaults } from './AxiosRequestConfigDefaults';

/**
 * Creates an Axios instance with desired configuration and proxy support.
 *
 * @param axiosInstanceOrConfig
 */
export function createAxios(axiosInstanceOrConfig: AxiosInstance | AxiosRequestConfigDefaults = {}): AxiosInstance {
    const axiosInstanceGiven = isAxiosInstance(axiosInstanceOrConfig);

    const axiosInstance = axiosInstanceGiven
        ? axiosInstanceOrConfig
        : axios.create({
            timeout: Duration.ofSeconds(10).inMilliseconds(),
            ...omit(axiosInstanceOrConfig, 'proxy'),
        });

    const proxyConfig: AxiosRequestConfigProxyDefaults | false | undefined = axiosInstanceGiven
        ? axiosInstanceOrConfig.defaults.proxy
        : axiosInstanceOrConfig.proxy;

    const proxyOverrides = axiosProxyOverridesFor({
        ...axiosInstance.defaults,
        proxy: proxyConfig || undefined,
    });

    return withOverrides(axiosInstance, proxyOverrides);
}

function isAxiosInstance(axiosInstanceOrConfig: any): axiosInstanceOrConfig is AxiosInstance {
    return axiosInstanceOrConfig
        && (axiosInstanceOrConfig instanceof Axios || axiosInstanceOrConfig.defaults);
}

function withOverrides<Data = any>(axiosInstance: AxiosInstance, overrides: AxiosRequestConfig<Data>): AxiosInstance {
    for (const [key, override] of Object.entries(overrides)) {
        axiosInstance.defaults[key] = override;
    }

    return axiosInstance;
}

function omit<T extends object, K extends keyof T>(record: T, key: K): Omit<T, K> {
    const { [key]: omitted_, ...rest } = record;
    return rest;
}

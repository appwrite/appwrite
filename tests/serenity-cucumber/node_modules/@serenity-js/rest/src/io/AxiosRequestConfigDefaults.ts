import { type CreateAxiosDefaults } from 'axios';

export type AxiosRequestConfigProxyDefaults = {
    host: string;
    port?: number;          // SOCKS proxies don't require port number
    auth?: {
        username: string;
        password: string;
    };
    protocol?: string;
}

export type AxiosRequestConfigDefaults<Data = any> = Omit<CreateAxiosDefaults<Data>, 'proxy'> & {
    proxy?: AxiosRequestConfigProxyDefaults | false;
}

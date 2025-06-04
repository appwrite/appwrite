import { ConfigurationError } from '@serenity-js/core';
import type { AgentConnectOpts } from 'agent-base';
import { Agent } from 'agent-base';
import * as http from 'http';
import { HttpProxyAgent, type HttpProxyAgentOptions } from 'http-proxy-agent';
import * as https from 'https';
import { HttpsProxyAgent, type HttpsProxyAgentOptions } from 'https-proxy-agent';
import { LRUCache } from 'lru-cache';
import { getProxyForUrl as envGetProxyForUrl } from 'proxy-from-env';

const protocols = [
    ...HttpProxyAgent.protocols,
] as const;

type AgentConstructor = new (proxy: URL | string, options?: ProxyAgentOptions) => Agent;

type ValidProtocol = (typeof protocols)[number];

type GetProxyForUrlCallback = (url: string) => string;

export type ProxyAgentOptions =
    HttpProxyAgentOptions<''> &
    HttpsProxyAgentOptions<''> & {
        /**
         * Default `http.Agent` instance to use when no proxy is
         * configured for a request. Defaults to a new `http.Agent()`
         * instance with the proxy agent options passed in.
         */
        httpAgent?: http.Agent;
        /**
         * Default `http.Agent` instance to use when no proxy is
         * configured for a request. Defaults to a new `https.Agent()`
         * instance with the proxy agent options passed in.
         */
        httpsAgent?: http.Agent;
        /**
         * A callback for dynamic provision of proxy for url.
         * Defaults to standard proxy environment variables,
         * see https://www.npmjs.com/package/proxy-from-env for details
         */
        getProxyForUrl?: GetProxyForUrlCallback;
    };

/**
 * A simplified version of the original
 * [`ProxyAgent`](https://github.com/TooTallNate/proxy-agents/blob/5923589c2e5206504772c250ac4f20fc31122d3b/packages/proxy-agent/src/index.ts)
 * with fewer dependencies.
 *
 * Delegates requests to the appropriate `Agent` subclass based on the "proxy"
 * environment variables, or the provided `agentOptions.getProxyForUrl` callback.
 *
 * Uses an LRU cache to prevent unnecessary creation of proxy `http.Agent` instances.
 */
export class ProxyAgent extends Agent {

    private static proxyAgents: { [P in ValidProtocol]: [ AgentConstructor, AgentConstructor ] } = {
        http:   [ HttpProxyAgent, HttpsProxyAgent ],
        https:  [ HttpProxyAgent, HttpsProxyAgent ],
    };

    /**
     * Cache for `Agent` instances.
     */
    private readonly cache = new LRUCache<string, Agent>({
        max: 20,
        dispose: (value: Agent, key: string) => value.destroy(),
    });

    private readonly httpAgent: http.Agent;
    private readonly httpsAgent: http.Agent;
    private readonly getProxyForUrl: GetProxyForUrlCallback;

    constructor(private readonly agentOptions: ProxyAgentOptions) {
        super(agentOptions);
        this.httpAgent      = agentOptions?.httpAgent       || new http.Agent(agentOptions);
        this.httpsAgent     = agentOptions?.httpsAgent      || new https.Agent(agentOptions as https.AgentOptions);
        this.getProxyForUrl = agentOptions?.getProxyForUrl  || envGetProxyForUrl;
    }

    override async connect(request: http.ClientRequest, options: AgentConnectOpts): Promise<http.Agent> {
        const { secureEndpoint } = options;
        const isWebSocket = request.getHeader('upgrade') === 'websocket';
        const protocol = secureEndpoint
            ? (isWebSocket ? 'wss:' : 'https:')
            : (isWebSocket ? 'ws:' : 'http:');
        const host = request.getHeader('host');
        const url = new URL(request.path, `${protocol}//${host}`).href;
        const proxy = this.getProxyForUrl(url);

        if (! proxy) {
            return secureEndpoint
                ? this.httpsAgent
                : this.httpAgent;
        }

        // attempt to get a cached `http.Agent` instance first
        const cacheKey = `${ protocol }+${ proxy }`;
        let agent = this.cache.get(cacheKey);
        if (! agent) {
            agent = this.createAgent(new URL(proxy), secureEndpoint || isWebSocket);

            this.cache.set(cacheKey, agent);
        }

        return agent;
    }

    private createAgent(proxyUrl: URL, requiresTls: boolean): Agent {

        const protocol = proxyUrl.protocol.replace(':', '');

        if (! this.isValidProtocol(protocol)) {
            throw new ConfigurationError(`Unsupported protocol for proxy URL: ${ proxyUrl }`);
        }

        const ctor = ProxyAgent.proxyAgents[protocol][requiresTls ? 1 : 0];

        return new ctor(proxyUrl, this.agentOptions);
    }

    private isValidProtocol(v: string): v is ValidProtocol {
        return (protocols as readonly string[]).includes(v);
    }

    override destroy(): void {
        this.cache.clear();
        super.destroy();
    }
}

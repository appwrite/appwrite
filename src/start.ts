import { createStart } from '@tanstack/react-start'
import { agentDiscoveryMiddleware } from '@/server/middleware/agent-discovery'
import { changePasswordUrlMiddleware } from '@/server/middleware/change-password-url'
import { hostCanonicalMiddleware } from '@/server/middleware/host-canonical'
import { legacyRedirectsMiddleware } from '@/server/middleware/legacy-redirects'
import { legacyConsolePathMiddleware } from '@/server/middleware/legacy-console-path'
import { rootGuestRedirectMiddleware } from '@/server/middleware/root-guest-redirect'
import { runtimeConfigMiddleware } from '@/server/middleware/runtime-config'
import { seoIndexingMiddleware } from '@/server/middleware/seo-indexing'
import { serverFnGuardMiddleware } from '@/server/middleware/server-fn-guard'
import { preLaunchMiddleware } from '@/server/middleware/pre-launch'
import { selfHostedRoutesMiddleware } from '@/server/middleware/self-hosted-routes'

export const startInstance = createStart(() => ({
  defaultSsr: true,
  requestMiddleware: [
    // Discovery first so /.well-known MCP cards are never gated or rewritten.
    agentDiscoveryMiddleware,
    // Server-function boundary before anything else can act on /_serverFn/*:
    // non-RPC and navigation requests are refused, responses are sandboxed.
    serverFnGuardMiddleware,
    // Password-manager well-known URL (W3C change-password) before other gates.
    changePasswordUrlMiddleware,
    // Apex host before other gates so www/new never serve duplicate content.
    hostCanonicalMiddleware,
    seoIndexingMiddleware,
    runtimeConfigMiddleware,
    legacyRedirectsMiddleware,
    legacyConsolePathMiddleware,
    preLaunchMiddleware,
    selfHostedRoutesMiddleware,
    rootGuestRedirectMiddleware,
  ],
}))

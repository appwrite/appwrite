import { createStart } from '@tanstack/react-start'
import { agentDiscoveryMiddleware } from '@/server/middleware/agent-discovery'
import { legacyRedirectsMiddleware } from '@/server/middleware/legacy-redirects'
import { rootGuestRedirectMiddleware } from '@/server/middleware/root-guest-redirect'
import { runtimeConfigMiddleware } from '@/server/middleware/runtime-config'
import { seoIndexingMiddleware } from '@/server/middleware/seo-indexing'
import { preLaunchMiddleware } from '@/server/middleware/pre-launch'

export const startInstance = createStart(() => ({
  defaultSsr: true,
  requestMiddleware: [
    // Discovery first so /.well-known MCP cards are never gated or rewritten.
    agentDiscoveryMiddleware,
    seoIndexingMiddleware,
    runtimeConfigMiddleware,
    legacyRedirectsMiddleware,
    preLaunchMiddleware,
    rootGuestRedirectMiddleware,
  ],
}))

import { createStart } from '@tanstack/react-start'
import { rootGuestRedirectMiddleware } from '@/server/middleware/root-guest-redirect'
import { runtimeConfigMiddleware } from '@/server/middleware/runtime-config'
import { seoIndexingMiddleware } from '@/server/middleware/seo-indexing'

export const startInstance = createStart(() => ({
  defaultSsr: true,
  requestMiddleware: [
    seoIndexingMiddleware,
    runtimeConfigMiddleware,
    rootGuestRedirectMiddleware,
  ],
}))

import { createStart } from '@tanstack/react-start'
import { rootGuestRedirectMiddleware } from '@/server/middleware/root-guest-redirect'
import { runtimeConfigMiddleware } from '@/server/middleware/runtime-config'

export const startInstance = createStart(() => ({
  defaultSsr: true,
  requestMiddleware: [runtimeConfigMiddleware, rootGuestRedirectMiddleware],
}))

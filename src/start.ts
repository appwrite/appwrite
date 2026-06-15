import { createStart } from '@tanstack/react-start'
import { rootGuestRedirectMiddleware } from '@/server/middleware/root-guest-redirect'

export const startInstance = createStart(() => ({
  defaultSsr: true,
  requestMiddleware: [rootGuestRedirectMiddleware],
}))

import { createIsomorphicFn } from '@tanstack/react-start'
import { getRequest } from '@tanstack/react-start/server'
import { isRootConsoleHopRequest } from '@/lib/root-guest-redirect'

/**
 * Whether `/` should server-render the guest homepage. Server APIs stay inside
 * `.server()` so the client bundle does not import `@tanstack/react-start/server`.
 */
export const shouldServerRenderRootHome = createIsomorphicFn()
  .server((): boolean => {
    if (process.env.TSS_PRERENDERING === 'true') return false
    try {
      return !isRootConsoleHopRequest(getRequest())
    } catch {
      return false
    }
  })
  .client((): boolean => false)

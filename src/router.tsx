import { createRouter } from '@tanstack/react-router'
import * as TanstackQuery from './integrations/tanstack-query/root-provider'
import { setupQueryClientRouterIntegration } from './integrations/tanstack-query/ssr-integration'

// Import the generated route tree
import { routeTree } from './routeTree.gen'
import { ErrorComponent } from './components/error/Component'
import { NotFound } from './components/error/NotFound'
import {
  scheduleClearStaleChunkReloadGuard,
  tryReloadForStaleChunk,
} from '@/lib/stale-chunk-error'
import {
  reportRouterCaughtError,
  reportUnhandledError,
} from '@/lib/sentry/report-error'
// No default pending component: the root FullscreenLoader (Appwrite logo) is the
// single loader. Showing a router pending UI here caused a dual-loader flash on
// static build (text "Loading data for you" then logo).

// Create a new router instance
export function getRouter() {
  const rqContext = TanstackQuery.getContext()

  const router = createRouter({
    routeTree,
    context: { ...rqContext },
    defaultPreload: 'intent',
    // Keep the previous page visible while loaders run. A finite pendingMs with
    // defaultPendingComponent: () => null blanks the outlet after 1s on slow
    // navigations (e.g. TablesDB table switches). Wizards that need a pending
    // UI set their own pendingMs + pendingComponent.
    defaultPendingMs: Infinity,
    defaultPendingComponent: () => null,
    defaultNotFoundComponent: NotFound,
    defaultErrorComponent: ({ error, info, reset }) => (
      <ErrorComponent error={error} info={info} reset={reset} />
    ),
    // Fires when any route CatchBoundary catches - before the error UI mounts.
    // Critical for max-update-depth and other crashes that can break the error page.
    defaultOnCatch: (error, errorInfo) => {
      reportRouterCaughtError(error, errorInfo, {
        source: 'router-defaultOnCatch',
      })
    },
    Wrap: (props: { children: React.ReactNode }) => {
      return (
        <TanstackQuery.Provider {...rqContext}>
          {props.children}
        </TanstackQuery.Provider>
      )
    },
  })

  setupQueryClientRouterIntegration(router, rqContext.queryClient)

  if (!router.isServer) {
    // Clear only after a successful settle. Clearing on init defeated the
    // one-reload guard and caused infinite reload loops when a route chunk
    // was still missing after the first recovery attempt (MIME text/html).
    scheduleClearStaleChunkReloadGuard()

    const onUnhandledRejection = (event: PromiseRejectionEvent) => {
      if (tryReloadForStaleChunk(event.reason, { event })) {
        event.preventDefault()
        return
      }
      reportUnhandledError(event.reason, 'unhandledrejection')
    }
    const onWindowError = (event: ErrorEvent) => {
      if (
        tryReloadForStaleChunk(event.error ?? event.message, { event })
      ) {
        event.preventDefault()
        return
      }
      // Ignore ResizeObserver noise and events without a real error payload.
      if (!event.error && !event.message) return
      reportUnhandledError(event.error ?? event.message, 'window.error')
    }
    // Vite dispatches this when a dynamically imported chunk fails to load
    // (common right after a deploy deletes the previous hashed assets).
    // This is the canonical signal - no message matching required.
    const onVitePreloadError = (event: Event) => {
      const payload = (event as Event & { payload?: unknown }).payload
      if (
        tryReloadForStaleChunk(payload ?? 'vite:preloadError', {
          event,
          fromVitePreload: true,
        })
      ) {
        event.preventDefault()
      }
    }
    window.addEventListener('unhandledrejection', onUnhandledRejection)
    window.addEventListener('error', onWindowError, true)
    window.addEventListener('vite:preloadError', onVitePreloadError)
  }

  return router
}

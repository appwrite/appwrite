// Keep global CSS in the static entry graph so Start includes it in SSR HTML.
import './styles.css'
import { createRouter } from '@tanstack/react-router'
import * as TanstackQuery from './integrations/tanstack-query/root-provider'
import { setupQueryClientRouterIntegration } from './integrations/tanstack-query/ssr-integration'
import { seedLocaleQueryFromHtmlPrefetch } from '@/lib/locale/prefetch-locale'

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
import {
  getDefaultRouterPreload,
  ROUTER_PRELOAD_DELAY_MS,
} from '@/lib/router-preload'
// No default pending component: the root FullscreenLoader (Appwrite logo) is the
// single loader. Showing a router pending UI here caused a dual-loader flash on
// static build (text "Loading data for you" then logo).

// Create a new router instance
export async function getRouter() {
  const rqContext = TanstackQuery.getContext()
  seedLocaleQueryFromHtmlPrefetch(rqContext.queryClient)

  // Dynamic import breaks routeTree.gen ↔ router circular dependency (Register
  // augmentation type-imports this module; static import can TDZ under SSR).
  const { routeTree } = await import('./routeTree.gen')

  const router = createRouter({
    routeTree,
    context: { ...rqContext },
    defaultPreload: getDefaultRouterPreload(),
    // Touchstart used to start preload in ~50ms and steal the tap's next paint.
    defaultPreloadDelay: ROUTER_PRELOAD_DELAY_MS,
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
      // Stale hashed chunks often surface here as a generic TypeError from
      // lazyRouteComponent, not as an unhandled import rejection.
      if (tryReloadForStaleChunk(error)) return
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
    // Vite dispatches this when a dynamically imported chunk fails to load.
    // Do not reload on the event alone (Firefox cancels intent preloads with
    // the same signal). tryReloadForStaleChunk only reloads after MIME / 404
    // confirmation on a hashed /assets/ URL.
    const onVitePreloadError = (event: Event) => {
      const payload = (event as Event & { payload?: unknown }).payload
      if (
        tryReloadForStaleChunk(payload, {
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

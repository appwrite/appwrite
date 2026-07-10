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
    defaultPendingComponent: () => null,
    defaultNotFoundComponent: NotFound,
    defaultErrorComponent: ({ error, info, reset }) => (
      <ErrorComponent error={error} info={info} reset={reset} />
    ),
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
      if (tryReloadForStaleChunk(event.reason)) {
        event.preventDefault()
      }
    }
    const onWindowError = (event: ErrorEvent) => {
      if (tryReloadForStaleChunk(event.error ?? event.message)) {
        event.preventDefault()
      }
    }
    window.addEventListener('unhandledrejection', onUnhandledRejection)
    window.addEventListener('error', onWindowError)
  }

  return router
}

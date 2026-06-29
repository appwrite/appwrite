import { createRouter } from '@tanstack/react-router'
import * as TanstackQuery from './integrations/tanstack-query/root-provider'
import { setupQueryClientRouterIntegration } from './integrations/tanstack-query/ssr-integration'

// Import the generated route tree
import { routeTree } from './routeTree.gen'
import { ErrorComponent } from './components/error/Component'
import { NotFound } from './components/error/NotFound'
import {
  clearStaleChunkReloadGuard,
  tryReloadForStaleChunk,
} from '@/lib/stale-chunk-error'
// No default pending component: the root FullscreenLoader (Appwrite logo) is the
// single loader. Showing a router pending UI here caused a dual-loader flash on
// static build (text "Loading data for you" then logo).

// Create a new router instance
export const getRouter = () => {
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
    clearStaleChunkReloadGuard()

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

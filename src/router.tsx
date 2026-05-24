import { createRouter } from '@tanstack/react-router'
import * as Sentry from '@sentry/tanstackstart-react'
import * as TanstackQuery from './integrations/tanstack-query/root-provider'

// Import the generated route tree
import { routeTree } from './routeTree.gen'
import { ErrorComponent } from './components/error/Component'
import { Link } from '@tanstack/react-router'
import {
  clearStaleChunkReloadGuard,
  isStaleChunkLoadError,
  tryReloadForStaleChunk,
} from '@/lib/stale-chunk-error'

function NotFoundComponent() {
  return (
    <div className="flex min-h-svh flex-col items-center justify-center gap-4 px-6 text-center">
      <h1 className="text-[20px] font-semibold text-foreground">Page not found</h1>
      <p className="max-w-md text-[13px] text-muted-foreground">
        The page you are looking for does not exist or may have been moved.
      </p>
      <Link
        to="/"
        className="text-[13px] font-medium text-primary hover:underline"
      >
        Go to console home
      </Link>
    </div>
  )
}

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
    defaultNotFoundComponent: NotFoundComponent,
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

  // SSR is disabled - app runs as SPA (client-side only)
  // No need for SSR query integration

  if (!router.isServer) {
    clearStaleChunkReloadGuard()

    const onUnhandledRejection = (event: PromiseRejectionEvent) => {
      if (tryReloadForStaleChunk(event.reason)) {
        event.preventDefault()
      }
    }
    window.addEventListener('unhandledrejection', onUnhandledRejection)
  }

  // Initialize Sentry on client side only when VITE_SENTRY_DSN is set
  if (!router.isServer && import.meta.env.VITE_SENTRY_DSN) {
    Sentry.init({
      dsn: import.meta.env.VITE_SENTRY_DSN,
      // Disable PII collection - we don't want to collect IP addresses or other personal data
      sendDefaultPii: false,
      // Don't send 401 Unauthorized to Sentry - we catch these and redirect to login
      beforeSend(event, hint) {
        const err = hint.originalException
        if (err && typeof err === 'object') {
          const code = (err as { code?: number }).code
          const status = (err as { status?: number }).status
          if (code === 401 || status === 401) return null
        }
        if (isStaleChunkLoadError(err)) return null
        return event
      },
    })
  }

  return router
}

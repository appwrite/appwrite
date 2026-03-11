import { createRouter } from '@tanstack/react-router'
import * as Sentry from '@sentry/tanstackstart-react'
import * as TanstackQuery from './integrations/tanstack-query/root-provider'

// Import the generated route tree
import { routeTree } from './routeTree.gen'
import { ErrorComponent } from './components/error/Component'

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
        return event
      },
    })
  }

  return router
}

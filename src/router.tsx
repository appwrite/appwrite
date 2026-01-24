import { createRouter, useLocation } from '@tanstack/react-router'
import * as Sentry from '@sentry/tanstackstart-react'
import * as TanstackQuery from './integrations/tanstack-query/root-provider'

// Import the generated route tree
import { routeTree } from './routeTree.gen'
import { ErrorComponent } from './components/error/Component'

// Component for default pending state - checks if we're on an auth route
function DefaultPendingComponent() {
  const location = useLocation()
  
  // Don't show pending component on auth routes - they load instantly
  const isAuthRoute =
    location.pathname === '/sign-in' ||
    location.pathname === '/sign-up' ||
    location.pathname === '/recovery' ||
    location.pathname === '/mfa' ||
    location.pathname === '/join' ||
    location.pathname === '/sign-out'
  
  if (isAuthRoute) {
    return null
  }
  
  return (
    <div className="flex h-full items-center justify-center">
      <div className="text-muted-foreground">Loading data for you...</div>
    </div>
  )
}

// Create a new router instance
export const getRouter = () => {
  const rqContext = TanstackQuery.getContext()

  const router = createRouter({
    routeTree,
    context: { ...rqContext },
    defaultPreload: 'intent',
    defaultPendingComponent: DefaultPendingComponent,
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

  // Initialize Sentry on client side only
  if (!router.isServer) {
    Sentry.init({
      dsn: 'https://b74f51cf094e44b878a61395468fd771@o1063647.ingest.us.sentry.io/4510766869250048',

      // Disable PII collection - we don't want to collect IP addresses or other personal data
      sendDefaultPii: false,
    })
  }

  return router
}

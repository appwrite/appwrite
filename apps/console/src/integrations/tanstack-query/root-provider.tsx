import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { registerActiveEnvironmentChange } from '@/lib/active-environment'

export function getContext() {
  const queryClient = new QueryClient({
    defaultOptions: {
      queries: {
        // Disable automatic refetching on window focus to reduce CPU usage
        refetchOnWindowFocus: false,
        // Don't refetch on mount if data exists
        refetchOnMount: false,
        // Don't refetch on reconnect
        refetchOnReconnect: false,
        // Don't retry by default (hooks can override if needed)
        retry: false,
        // Default stale time to reduce unnecessary refetches
        staleTime: 30 * 1000, // 30 seconds
        // Garbage collect disabled queries immediately
        gcTime: 0, // Will be overridden by individual hooks
      },
    },
  })

  // An environment and the project it belongs to share the project's ID and so
  // share every query key. Only one of them is ever cached for a project at a
  // time: when a project's environment changes, drop that project's queries so
  // the new context refetches and nothing of the old one lingers or is matched
  // by a later invalidation on the shared key.
  registerActiveEnvironmentChange((projectId: string) => {
    queryClient.removeQueries({
      predicate: (query) =>
        Array.isArray(query.queryKey) && query.queryKey.includes(projectId),
    })
  })

  // Expose queryClient to window for debugging in development
  if (typeof window !== 'undefined' && import.meta.env.DEV) {
    ;(window as unknown).__REACT_QUERY_CLIENT__ = queryClient
  }

  return {
    queryClient,
  }
}

export function Provider({
  children,
  queryClient,
}: {
  children: React.ReactNode
  queryClient: QueryClient
}) {
  return (
    <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  )
}

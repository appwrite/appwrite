import { QueryClient, QueryClientProvider } from '@tanstack/react-query'

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

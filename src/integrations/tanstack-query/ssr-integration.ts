import { dehydrate, hydrate, type QueryClient } from '@tanstack/react-query'
import type { AnyRouter } from '@tanstack/react-router'

/**
 * Wire React Query to TanStack Router SSR without streaming late queries.
 *
 * The full `setupRouterSsrQueryIntegration` subscribes to the query cache and
 * streams queries that resolve after render; console UI hooks (assistant,
 * command center, etc.) often finish after the stream closes and spam server logs.
 *
 * This integration dehydrates loader data once, hydrates on the client, and clears
 * the per-request client after SSR to limit memory growth.
 */
export function setupQueryClientRouterIntegration(
  router: AnyRouter,
  queryClient: QueryClient,
) {
  if (router.isServer) {
    const ogDehydrate = router.options.dehydrate
    let renderCleanupRegistered = false

    const registerRenderCleanup = () => {
      if (renderCleanupRegistered || !router.serverSsr) return
      renderCleanupRegistered = true
      router.serverSsr.onRenderFinished(() => {
        queryClient.clear()
      })
    }

    router.options.dehydrate = async () => {
      // serverSsr is attached in attachRouterServerSsrUtils(), which runs after
      // getRouter() returns. Register cleanup here (same as TanStack's official
      // setupRouterSsrQueryIntegration) so queryClient.clear() actually runs.
      registerRenderCleanup()

      const ogDehydrated = await ogDehydrate?.()
      const dehydratedQueryClient = dehydrate(queryClient)

      return {
        ...ogDehydrated,
        ...(dehydratedQueryClient.queries.length > 0
          ? { dehydratedQueryClient }
          : {}),
      }
    }

    return
  }

  const ogHydrate = router.options.hydrate

  router.options.hydrate = async (dehydrated) => {
    await ogHydrate?.(dehydrated)
    if (dehydrated.dehydratedQueryClient) {
      hydrate(queryClient, dehydrated.dehydratedQueryClient)
    }
  }
}

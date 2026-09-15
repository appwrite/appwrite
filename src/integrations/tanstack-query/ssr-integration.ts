import {
  defaultShouldDehydrateQuery,
  dehydrate,
  hydrate,
  type QueryClient,
} from '@tanstack/react-query'
import type { AnyRouter } from '@tanstack/react-router'

type DehydratedQuery = {
  queryKey: unknown
  state?: { status?: string; dataUpdatedAt?: number }
}

/**
 * Session and header queries must stay on the client QueryClient. SSR payloads
 * often include a pending/empty observer (enabled is false on the server) whose
 * hydrate would wipe a settled account and trigger another `account.get`.
 */
export function isClientOwnedQueryKey(queryKey: unknown): boolean {
  if (!Array.isArray(queryKey) || queryKey.length === 0) return false
  const [scope, name] = queryKey
  if (scope === 'account' && name === 'console') return true
  if (
    scope === 'organization' &&
    (name === 'plan' || name === 'scopes' || name === 'project-scope')
  ) {
    return true
  }
  if (scope === 'locale' && name === 'console') return true
  return false
}

export function shouldDehydrateRouterQuery(query: {
  queryKey: unknown
  state: { status: string }
}): boolean {
  if (isClientOwnedQueryKey(query.queryKey)) return false
  if (query.state.status !== 'success') return false
  return defaultShouldDehydrateQuery(query as never)
}

export function shouldHydrateRouterQuery(
  queryClient: QueryClient,
  queryKey: unknown,
): boolean {
  if (!Array.isArray(queryKey)) return true
  const existing = queryClient.getQueryState(queryKey)
  if (!existing) return !isClientOwnedQueryKey(queryKey)
  if (isClientOwnedQueryKey(queryKey)) return false
  if (existing.status === 'success' || existing.status === 'error') {
    return false
  }
  if (existing.data !== undefined) return false
  return true
}

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
        queryClient.getQueryCache().clear()
        queryClient.getMutationCache().clear()
      })
    }

    router.options.dehydrate = async () => {
      // serverSsr is attached in attachRouterServerSsrUtils(), which runs after
      // getRouter() returns. Register cleanup here (same as TanStack's official
      // setupRouterSsrQueryIntegration) so queryClient.clear() actually runs.
      registerRenderCleanup()

      const ogDehydrated = await ogDehydrate?.()
      const dehydratedQueryClient = dehydrate(queryClient, {
        shouldDehydrateQuery: (query) => shouldDehydrateRouterQuery(query),
      })

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
    const incoming = dehydrated.dehydratedQueryClient as
      | { queries?: DehydratedQuery[]; mutations?: unknown[] }
      | undefined
    if (!incoming?.queries?.length) return

    hydrate(queryClient, {
      ...incoming,
      queries: incoming.queries.filter((query) =>
        shouldHydrateRouterQuery(queryClient, query.queryKey),
      ),
    })
  }
}

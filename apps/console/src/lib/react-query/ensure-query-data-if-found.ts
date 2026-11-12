import type {
  EnsureQueryDataOptions,
  QueryClient,
  QueryKey,
} from '@tanstack/react-query'
import { isHttpNotFoundError } from '@/lib/utils/error-formatting'

/**
 * Like `queryClient.ensureQueryData`, but resolves to `undefined` when the API
 * responds with 404 instead of rejecting.
 *
 * Use it in route loaders for records the parent resource merely references,
 * such as a function's or site's `deploymentId` / `latestDeploymentId`. The
 * referenced deployment can be deleted while the parent keeps pointing at it,
 * and that must not turn the whole page into an error screen. Any other
 * failure still rejects so callers keep their existing error handling.
 */
export async function ensureQueryDataIfFound<
  TQueryFnData,
  TError,
  TData,
  TQueryKey extends QueryKey,
>(
  queryClient: QueryClient,
  options: EnsureQueryDataOptions<TQueryFnData, TError, TData, TQueryKey>,
): Promise<TData | undefined> {
  try {
    return await queryClient.ensureQueryData(options)
  } catch (error) {
    if (isHttpNotFoundError(error)) return undefined
    throw error
  }
}

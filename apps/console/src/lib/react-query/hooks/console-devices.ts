import { queryOptions, useQuery } from '@tanstack/react-query'
import { ensureConsoleDevice } from '@/lib/console-devices'

/**
 * The operator's console account switching device (its sessions and the active one).
 * Uses create-or-return so a device always exists while the switcher is open; the
 * endpoint answers 201 with the existing device when one is already present.
 * Operator-gated by the caller via `enabled`.
 */
export function consoleDeviceQueryOptions() {
  return queryOptions({
    queryKey: ['console', 'device'],
    queryFn: ensureConsoleDevice,
    staleTime: 0,
    retry: false,
    refetchOnWindowFocus: false,
  })
}

export function useConsoleDevice(enabled: boolean) {
  return useQuery({
    ...consoleDeviceQueryOptions(),
    enabled,
  })
}

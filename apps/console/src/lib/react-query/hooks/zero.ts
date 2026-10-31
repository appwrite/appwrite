/**
 * Zero projects: claimable projects handed out without signup.
 */

import { useQuery } from '@tanstack/react-query'
import { sdk } from '@/lib/appwrite/sdk'

/** How often the banner and expired curtain re-read a Zero project's status. */
export const ZERO_POLL_INTERVAL_MS = 30 * 1000

export const ACCOUNT_ZERO_QUERY_KEY = ['zero', 'account'] as const

/**
 * The Zero project the signed-in anonymous account owns. The server returns
 * its claim link, so nothing about the claim is stored in the browser.
 */
export function useAccountZero(options?: {
  poll?: boolean
  enabled?: boolean
}) {
  return useQuery({
    queryKey: ACCOUNT_ZERO_QUERY_KEY,
    queryFn: () => sdk.forConsole.zero.getAccount(),
    enabled: options?.enabled ?? true,
    staleTime: ZERO_POLL_INTERVAL_MS,
    retry: false,
    refetchOnWindowFocus: true,
    refetchOnReconnect: true,
    refetchInterval: options?.poll ? ZERO_POLL_INTERVAL_MS : false,
  })
}

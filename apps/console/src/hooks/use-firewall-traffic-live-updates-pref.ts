import { useCallback } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import type { Models } from '@appwrite.io/console'
import { useAuth } from '@/components/global/auth/RequireAuth'
import {
  syncConsoleAccountAfterMutation,
  updateAccountPrefs,
} from '@/lib/react-query/hooks/auth'
import {
  mergeFirewallTrafficLiveUpdatesIntoPrefs,
  parseFirewallTrafficLiveUpdatesEnabled,
  type UserPrefs,
} from '@/lib/user-prefs-keys'

export function useFirewallTrafficLiveUpdatesEnabled() {
  const { account } = useAuth()
  const queryClient = useQueryClient()
  const accountPrefs = (account as Models.User | undefined)?.prefs as
    | UserPrefs
    | undefined
  const liveUpdatesEnabled = parseFirewallTrafficLiveUpdatesEnabled(accountPrefs)

  const updateMutation = useMutation({
    mutationFn: async (enabled: boolean) => {
      const currentAccount = account as Models.User | undefined
      if (!currentAccount) {
        throw new Error('Account data not available')
      }
      return await updateAccountPrefs(
        mergeFirewallTrafficLiveUpdatesIntoPrefs(
          (currentAccount.prefs ?? {}) as UserPrefs,
          enabled,
        ),
      )
    },
    onMutate: async (enabled) => {
      queryClient.setQueriesData<{ prefs?: Record<string, unknown> }>(
        { queryKey: ['account', 'console'] },
        (current) =>
          current
            ? {
                ...current,
                prefs: mergeFirewallTrafficLiveUpdatesIntoPrefs(
                  (current.prefs ?? {}) as UserPrefs,
                  enabled,
                ),
              }
            : current,
      )
    },
    onSuccess: (updatedAccount) => {
      syncConsoleAccountAfterMutation(queryClient, {
        apiResult: updatedAccount,
      })
    },
  })

  const setLiveUpdatesEnabled = useCallback(
    (enabled: boolean) => {
      if (!account || enabled === liveUpdatesEnabled) return
      updateMutation.mutate(enabled)
    },
    [account, liveUpdatesEnabled, updateMutation],
  )

  const toggleLiveUpdatesEnabled = useCallback(() => {
    setLiveUpdatesEnabled(!liveUpdatesEnabled)
  }, [liveUpdatesEnabled, setLiveUpdatesEnabled])

  return {
    liveUpdatesEnabled,
    setLiveUpdatesEnabled,
    toggleLiveUpdatesEnabled,
  }
}

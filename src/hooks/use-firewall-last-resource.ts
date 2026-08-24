import { useCallback, useMemo } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import type { Models } from '@appwrite.io/console'
import { useAuth } from '@/components/global/auth/RequireAuth'
import type { FirewallResourceSelection } from '@/lib/firewall/conditions'
import {
  firewallLastResourcesEqual,
  mergeFirewallLastResourceIntoPrefs,
  normalizeFirewallLastResource,
  parseFirewallLastResource,
} from '@/lib/firewall/last-resource'
import {
  syncConsoleAccountAfterMutation,
  updateAccountPrefs,
} from '@/lib/react-query/hooks/auth'
import type { UserPrefs } from '@/lib/user-prefs-keys'

export function useFirewallLastResource(
  projectId: string | null | undefined,
) {
  const { account } = useAuth()
  const queryClient = useQueryClient()
  const accountPrefs = (account as Models.User | undefined)?.prefs as
    | UserPrefs
    | undefined

  const lastResource = useMemo(
    () => parseFirewallLastResource(accountPrefs, projectId),
    [accountPrefs, projectId],
  )

  const updateMutation = useMutation({
    mutationFn: async (selection: FirewallResourceSelection) => {
      const currentAccount = account as Models.User | undefined
      if (!currentAccount || !projectId) {
        throw new Error('Account data not available')
      }
      return await updateAccountPrefs(
        mergeFirewallLastResourceIntoPrefs(
          (currentAccount.prefs ?? {}) as UserPrefs,
          projectId,
          selection,
        ),
        'firewall-last-resource',
      )
    },
    onMutate: async (selection) => {
      if (!projectId) return
      queryClient.setQueriesData<{ prefs?: Record<string, unknown> }>(
        { queryKey: ['account', 'console'] },
        (current) =>
          current
            ? {
                ...current,
                prefs: mergeFirewallLastResourceIntoPrefs(
                  (current.prefs ?? {}) as UserPrefs,
                  projectId,
                  selection,
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

  const setLastResource = useCallback(
    (selection: FirewallResourceSelection) => {
      if (!account || !projectId) return
      const next = normalizeFirewallLastResource(selection)
      if (!next || firewallLastResourcesEqual(lastResource, next)) return
      updateMutation.mutate(next)
    },
    [account, projectId, lastResource, updateMutation],
  )

  return { lastResource, setLastResource }
}

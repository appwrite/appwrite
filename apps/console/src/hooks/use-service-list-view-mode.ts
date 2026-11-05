import { useCallback } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import type { Models } from '@appwrite.io/console'
import { useAuth } from '@/components/global/auth/RequireAuth'
import {
  syncConsoleAccountAfterMutation,
  updateAccountPrefs,
} from '@/lib/react-query/hooks/auth'
import {
  mergeServiceListViewModeIntoPrefs,
  parseServiceListViewMode,
  type ServiceListViewMode,
  type ServiceListViewModeScope,
  type UserPrefs,
} from '@/lib/user-prefs-keys'

export function useServiceListViewMode(scope: ServiceListViewModeScope) {
  const { account } = useAuth()
  const queryClient = useQueryClient()
  const accountPrefs = (account as Models.User | undefined)?.prefs as
    | UserPrefs
    | undefined
  const viewMode = parseServiceListViewMode(accountPrefs, scope)

  const updateMutation = useMutation({
    mutationFn: async (mode: ServiceListViewMode) => {
      const currentAccount = account as Models.User | undefined
      if (!currentAccount) {
        throw new Error('Account data not available')
      }
      return await updateAccountPrefs(
        mergeServiceListViewModeIntoPrefs(
          (currentAccount.prefs ?? {}) as UserPrefs,
          scope,
          mode,
        ),
      )
    },
    onMutate: async (mode) => {
      queryClient.setQueriesData<{ prefs?: Record<string, unknown> }>(
        { queryKey: ['account', 'console'] },
        (current) =>
          current
            ? {
                ...current,
                prefs: mergeServiceListViewModeIntoPrefs(
                  (current.prefs ?? {}) as UserPrefs,
                  scope,
                  mode,
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

  const setViewMode = useCallback(
    (mode: ServiceListViewMode) => {
      if (!account || mode === viewMode) return
      updateMutation.mutate(mode)
    },
    [account, viewMode, updateMutation],
  )

  return { viewMode, setViewMode }
}

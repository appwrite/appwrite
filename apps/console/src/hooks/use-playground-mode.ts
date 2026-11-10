import { useCallback } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import type { Models } from '@appwrite.io/console'
import { useAuth } from '@/components/global/auth/RequireAuth'
import {
  syncConsoleAccountAfterMutation,
  updateAccountPrefs,
} from '@/lib/react-query/hooks/auth'
import {
  mergePlaygroundModeIntoPrefs,
  parsePlaygroundMode,
  type PlaygroundMode,
  type UserPrefs,
} from '@/lib/user-prefs-keys'

export function usePlaygroundMode() {
  const { account } = useAuth()
  const queryClient = useQueryClient()
  const accountPrefs = (account as Models.User | undefined)?.prefs as
    | UserPrefs
    | undefined
  const mode = parsePlaygroundMode(accountPrefs)

  const updateMutation = useMutation({
    mutationFn: async (next: PlaygroundMode) => {
      const currentAccount = account as Models.User | undefined
      if (!currentAccount) {
        throw new Error('Account data not available')
      }
      return await updateAccountPrefs(
        mergePlaygroundModeIntoPrefs(
          (currentAccount.prefs ?? {}) as UserPrefs,
          next,
        ),
        'playground-mode',
      )
    },
    onMutate: async (next) => {
      queryClient.setQueriesData<{ prefs?: Record<string, unknown> }>(
        { queryKey: ['account', 'console'] },
        (current) =>
          current
            ? {
                ...current,
                prefs: mergePlaygroundModeIntoPrefs(
                  (current.prefs ?? {}) as UserPrefs,
                  next,
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

  const setMode = useCallback(
    (next: PlaygroundMode) => {
      if (!account || next === mode) return
      updateMutation.mutate(next)
    },
    [account, mode, updateMutation],
  )

  return { mode, setMode }
}

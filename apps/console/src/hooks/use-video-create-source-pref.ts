import { useCallback } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import type { Models } from '@appwrite.io/console'
import { useAuth } from '@/components/global/auth/RequireAuth'
import {
  getConsoleAccountFromCache,
  syncConsoleAccountAfterMutation,
  updateAccountPrefs,
} from '@/lib/react-query/hooks/auth'
import {
  isAccountPrefsPayloadWithinLimit,
  mergeVideoCreateSourcePrefIntoPrefs,
  readVideoCreateSourcePref,
  USER_PREFS_KEY_VIDEOS_CREATE_SOURCE,
  VIDEO_CREATE_SOURCE_DEFAULT,
  type UserPrefs,
  type VideoCreateSourcePref,
} from '@/lib/user-prefs-keys'

/**
 * Browser fallback when `account.updatePrefs` cannot accept another key.
 * Account prefs are capped at 64KB; a full bag rejects the write with
 * "Value must be a valid object." Scoped by user id so it is not per project.
 */
function localSourceKey(userId: string) {
  return `${USER_PREFS_KEY_VIDEOS_CREATE_SOURCE}.${userId}`
}

function readLocalSource(userId: string | undefined): VideoCreateSourcePref | null {
  if (!userId || typeof window === 'undefined') return null
  try {
    return readVideoCreateSourcePref({
      [USER_PREFS_KEY_VIDEOS_CREATE_SOURCE]: window.localStorage.getItem(
        localSourceKey(userId),
      ),
    })
  } catch {
    return null
  }
}

function writeLocalSource(userId: string, value: VideoCreateSourcePref) {
  try {
    window.localStorage.setItem(localSourceKey(userId), value)
  } catch {
    // Private mode or a full disk. The in-memory tab still updates.
  }
}

/**
 * Last source tab in the create video wizard (`console.videos.createSource`).
 * Stored on the account, shared across projects. When the account prefs bag
 * is full, the same choice is kept in localStorage for that user.
 */
export function useVideoCreateSourcePref() {
  const { account } = useAuth()
  const queryClient = useQueryClient()
  const user = account as Models.User | undefined
  const prefs = user?.prefs as UserPrefs | undefined
  const source =
    readVideoCreateSourcePref(prefs) ??
    readLocalSource(user?.$id) ??
    VIDEO_CREATE_SOURCE_DEFAULT

  const mutation = useMutation({
    mutationFn: async (value: VideoCreateSourcePref) => {
      const current = (getConsoleAccountFromCache(queryClient) ?? account) as
        | Models.User
        | undefined
      if (!current) throw new Error('Account data not available')
      const next = mergeVideoCreateSourcePrefIntoPrefs(
        (current.prefs ?? {}) as UserPrefs,
        value,
      )
      if (!isAccountPrefsPayloadWithinLimit(next)) return undefined
      return await updateAccountPrefs(next, 'video-create-source')
    },
    onSuccess: (updatedAccount) => {
      if (!updatedAccount) return
      syncConsoleAccountAfterMutation(queryClient, {
        apiResult: updatedAccount,
      })
    },
  })

  const setSource = useCallback(
    (value: VideoCreateSourcePref) => {
      if (value === source) return
      if (user?.$id) writeLocalSource(user.$id, value)
      mutation.mutate(value)
    },
    [mutation, source, user?.$id],
  )

  return { source, setSource }
}

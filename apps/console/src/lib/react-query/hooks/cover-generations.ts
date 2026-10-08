import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useCallback, useMemo, useState } from 'react'
import { AppwriteException } from '@appwrite.io/console'
import {
  applyCoverGenerationName,
  clearLegacyCoverEditorLocalStorage,
  COVER_GENERATIONS_LOCAL_STORAGE_KEY,
  MAX_SAVED_COVER_GENERATION_NAME_LENGTH,
  mergeCoverGenerationsIntoPrefs,
  parseSavedCoverGenerations,
  readLegacyCoverEditorGeneration,
  removeSavedCoverGeneration,
  type SavedCoverGeneration,
  upsertSavedCoverGeneration,
  USER_PREFS_KEY_COVER_GENERATIONS,
} from '@/lib/cover-generator/cover-generation-prefs'
import {
  getConsoleAccountFromCache,
  syncConsoleAccountAfterMutation,
  updateAccountPrefs,
} from '@/lib/react-query/hooks/auth'
import { fetchConsoleAccount } from '@/lib/console-account-get'
import {
  isAccountPrefsPayloadWithinLimit,
  type UserPrefs,
} from '@/lib/user-prefs-keys'

function readLocalCoverGenerations(): SavedCoverGeneration[] {
  if (typeof window === 'undefined') return []
  try {
    const raw = window.localStorage.getItem(COVER_GENERATIONS_LOCAL_STORAGE_KEY)
    if (!raw) return []
    return parseSavedCoverGenerations(raw)
  } catch {
    return []
  }
}

function writeLocalCoverGenerations(list: SavedCoverGeneration[]): void {
  if (typeof window === 'undefined') return
  try {
    window.localStorage.setItem(
      COVER_GENERATIONS_LOCAL_STORAGE_KEY,
      JSON.stringify(list),
    )
  } catch {
    /* private mode */
  }
}

/**
 * Appwrite Assoc uses "Value must be a valid object" for both non-object prefs
 * and payloads over 64KB. Treat size-ish 400s as capacity so we can drop oldest.
 */
function isPrefsCapacityError(error: unknown): boolean {
  if (!(error instanceof AppwriteException)) return false
  if (error.code !== 400 && error.code !== 413) return false
  const message = error.message.toLowerCase()
  return (
    message.includes('valid object') ||
    message.includes('size') ||
    message.includes('limit') ||
    message.includes('too long') ||
    message.includes('too large') ||
    message.includes('storage') ||
    message.includes('maximum')
  )
}

function trimCoverGenerationsToPrefsLimit(
  existingPrefs: Record<string, unknown>,
  list: SavedCoverGeneration[],
): SavedCoverGeneration[] {
  let candidate = list
  while (candidate.length > 0) {
    const prefs = mergeCoverGenerationsIntoPrefs(existingPrefs, candidate)
    if (isAccountPrefsPayloadWithinLimit(prefs)) {
      return candidate
    }
    // Newest-first list: drop the oldest cover and retry.
    candidate = candidate.slice(0, -1)
  }
  return candidate
}

export type CoverGenerationsAccount = {
  prefs?: Record<string, unknown>
} | null | undefined

export function useCoverGenerations(account: CoverGenerationsAccount) {
  const queryClient = useQueryClient()
  const isAuthenticated = Boolean(account)
  const [localRevision, setLocalRevision] = useState(0)

  const resolveAccount = useCallback((): CoverGenerationsAccount => {
    return getConsoleAccountFromCache(queryClient) ?? account
  }, [account, queryClient])

  const readGenerationsList = useCallback((): SavedCoverGeneration[] => {
    if (isAuthenticated) {
      const currentAccount = resolveAccount()
      return parseSavedCoverGenerations(
        currentAccount?.prefs?.[USER_PREFS_KEY_COVER_GENERATIONS],
      )
    }
    return readLocalCoverGenerations()
  }, [isAuthenticated, resolveAccount])

  const generations = useMemo(() => {
    if (isAuthenticated) {
      const currentAccount = resolveAccount()
      return parseSavedCoverGenerations(
        currentAccount?.prefs?.[USER_PREFS_KEY_COVER_GENERATIONS],
      )
    }
    return readLocalCoverGenerations()
  }, [isAuthenticated, localRevision, resolveAccount])

  const persistList = useCallback(
    async (next: SavedCoverGeneration[]) => {
      if (isAuthenticated) {
        const currentAccount = resolveAccount()
        if (!currentAccount) {
          throw new Error('Account not available')
        }

        const existingPrefs = (currentAccount.prefs ?? {}) as UserPrefs
        let candidate = trimCoverGenerationsToPrefsLimit(existingPrefs, next)
        if (candidate.length === 0 && next.length > 0) {
          throw new Error(
            'Account preferences are full. Delete older covers or other saved prefs and try again.',
          )
        }

        let prefsBase = existingPrefs
        let refreshedFromServer = false

        for (;;) {
          try {
            const updatedAccount = await updateAccountPrefs(
              mergeCoverGenerationsIntoPrefs(prefsBase, candidate),
            )
            syncConsoleAccountAfterMutation(queryClient, {
              apiResult: updatedAccount,
            })
            return
          } catch (error) {
            if (!isPrefsCapacityError(error)) {
              throw error
            }

            // Client cache can retain stale/extra prefs keys; refetch once and retry.
            if (!refreshedFromServer) {
              refreshedFromServer = true
              const fresh = await fetchConsoleAccount({ force: true })
              syncConsoleAccountAfterMutation(queryClient, {
                apiResult: fresh,
              })
              prefsBase = (fresh.prefs ?? {}) as UserPrefs
              candidate = trimCoverGenerationsToPrefsLimit(prefsBase, next)
              if (candidate.length === 0 && next.length > 0) {
                throw new Error(
                  'Account preferences are full. Delete older covers or other saved prefs and try again.',
                )
              }
              continue
            }

            if (candidate.length <= 1) {
              throw error
            }
            candidate = candidate.slice(0, -1)
          }
        }
      }

      writeLocalCoverGenerations(next)
      setLocalRevision((value) => value + 1)
    },
    [isAuthenticated, queryClient, resolveAccount],
  )

  const saveMutation = useMutation({
    mutationFn: async (entry: SavedCoverGeneration) => {
      const next = upsertSavedCoverGeneration(readGenerationsList(), entry)
      await persistList(next)
      return next
    },
  })

  const deleteMutation = useMutation({
    mutationFn: async (id: string) => {
      const next = removeSavedCoverGeneration(readGenerationsList(), id)
      await persistList(next)
      return next
    },
  })

  const renameMutation = useMutation({
    mutationFn: async ({ id, name }: { id: string; name: string }) => {
      const generation = readGenerationsList().find((item) => item.id === id)
      if (!generation) {
        throw new Error('Cover not found')
      }
      const trimmed = name.trim().slice(0, MAX_SAVED_COVER_GENERATION_NAME_LENGTH)
      if (!trimmed) {
        throw new Error('Name is required')
      }
      const entry: SavedCoverGeneration = {
        ...generation,
        name: trimmed,
        updatedAt: Date.now(),
        data: applyCoverGenerationName(generation.data, trimmed),
      }
      const next = upsertSavedCoverGeneration(readGenerationsList(), entry)
      await persistList(next)
      return next
    },
  })

  const migrateLegacyMutation = useMutation({
    mutationFn: async () => {
      const current = readGenerationsList()
      if (current.length > 0) return current
      const legacy = readLegacyCoverEditorGeneration()
      if (!legacy) return current
      const next = upsertSavedCoverGeneration(current, legacy)
      await persistList(next)
      clearLegacyCoverEditorLocalStorage()
      return next
    },
  })

  const saveGeneration = useCallback(
    (entry: SavedCoverGeneration) => saveMutation.mutateAsync(entry),
    [saveMutation],
  )

  const deleteGeneration = useCallback(
    (id: string) => deleteMutation.mutateAsync(id),
    [deleteMutation],
  )

  const renameGeneration = useCallback(
    (id: string, name: string) => renameMutation.mutateAsync({ id, name }),
    [renameMutation],
  )

  const migrateLegacyIfNeeded = useCallback(
    () => migrateLegacyMutation.mutateAsync(),
    [migrateLegacyMutation],
  )

  return {
    generations,
    isAuthenticated,
    isSaving: saveMutation.isPending,
    isDeleting: deleteMutation.isPending,
    isRenaming: renameMutation.isPending,
    saveGeneration,
    deleteGeneration,
    renameGeneration,
    maxNameLength: MAX_SAVED_COVER_GENERATION_NAME_LENGTH,
    migrateLegacyIfNeeded,
  }
}

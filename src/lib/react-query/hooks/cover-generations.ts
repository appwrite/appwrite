import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useCallback, useMemo, useState } from 'react'
import {
  applyCoverGenerationName,
  buildSavedCoverGenerationsPrefs,
  clearLegacyCoverEditorLocalStorage,
  COVER_GENERATIONS_LOCAL_STORAGE_KEY,
  MAX_SAVED_COVER_GENERATION_NAME_LENGTH,
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

export type CoverGenerationsAccount = {
  prefs?: Record<string, unknown>
} | null | undefined

export function useCoverGenerations(account: CoverGenerationsAccount) {
  const queryClient = useQueryClient()
  const isAuthenticated = Boolean(account)
  const [localRevision, setLocalRevision] = useState(0)

  const readGenerationsList = useCallback((): SavedCoverGeneration[] => {
    if (isAuthenticated) {
      const currentAccount = getConsoleAccountFromCache(queryClient)
      return parseSavedCoverGenerations(
        currentAccount?.prefs?.[USER_PREFS_KEY_COVER_GENERATIONS],
      )
    }
    return readLocalCoverGenerations()
  }, [isAuthenticated, queryClient])

  const generations = useMemo(() => {
    if (isAuthenticated) {
      return parseSavedCoverGenerations(account?.prefs?.[USER_PREFS_KEY_COVER_GENERATIONS])
    }
    return readLocalCoverGenerations()
  }, [account?.prefs, isAuthenticated, localRevision])

  const persistList = useCallback(
    async (next: SavedCoverGeneration[]) => {
      if (isAuthenticated) {
        const currentAccount = getConsoleAccountFromCache(queryClient)
        if (!currentAccount) {
          throw new Error('Account not available')
        }
        const updatedAccount = await updateAccountPrefs({
          ...currentAccount.prefs,
          ...buildSavedCoverGenerationsPrefs(next),
        })
        syncConsoleAccountAfterMutation(queryClient, { apiResult: updatedAccount })
        return
      }

      writeLocalCoverGenerations(next)
      setLocalRevision((value) => value + 1)
    },
    [isAuthenticated, queryClient],
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

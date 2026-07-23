import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useCallback, useMemo, useState } from 'react'
import { AppwriteException } from '@appwrite.io/console'
import {
  DIAGRAM_GENERATIONS_LOCAL_STORAGE_KEY,
  MAX_SAVED_DIAGRAM_GENERATION_NAME_LENGTH,
  mergeDiagramGenerationsIntoPrefs,
  parseSavedDiagramGenerations,
  removeSavedDiagramGeneration,
  type SavedDiagramGeneration,
  upsertSavedDiagramGeneration,
  USER_PREFS_KEY_DIAGRAM_GENERATIONS,
} from '@/lib/diagram-generator/generation-prefs'
import { DIAGRAM_STORAGE_KEY } from '@/lib/diagram-generator/constants'
import { normalizeDiagramDocument } from '@/lib/diagram-generator/storage'
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

function readLocalDiagramGenerations(): SavedDiagramGeneration[] {
  if (typeof window === 'undefined') return []
  try {
    const raw = window.localStorage.getItem(DIAGRAM_GENERATIONS_LOCAL_STORAGE_KEY)
    if (!raw) return []
    return parseSavedDiagramGenerations(raw)
  } catch {
    return []
  }
}

function writeLocalDiagramGenerations(list: SavedDiagramGeneration[]): void {
  if (typeof window === 'undefined') return
  try {
    window.localStorage.setItem(
      DIAGRAM_GENERATIONS_LOCAL_STORAGE_KEY,
      JSON.stringify(list),
    )
  } catch {
    /* private mode */
  }
}

function readLegacySingleDiagramLocalStorage(): SavedDiagramGeneration | null {
  if (typeof window === 'undefined') return null
  try {
    const raw = window.localStorage.getItem(DIAGRAM_STORAGE_KEY)
    if (!raw) return null
    const parsed = JSON.parse(raw) as { version?: number; document?: unknown }
    if (parsed?.version !== 1 || !parsed.document) return null
    const document = normalizeDiagramDocument(
      parsed.document as SavedDiagramGeneration['document'],
    )
    const isEmpty =
      document.nodes.length === 0 &&
      document.edges.length === 0 &&
      document.title === 'Untitled diagram'
    if (isEmpty) return null
    return {
      id: crypto.randomUUID(),
      name: document.title.trim() || 'Imported diagram',
      updatedAt: Date.now(),
      document,
    }
  } catch {
    return null
  }
}

function clearLegacySingleDiagramLocalStorage(): void {
  if (typeof window === 'undefined') return
  try {
    window.localStorage.removeItem(DIAGRAM_STORAGE_KEY)
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

function trimDiagramGenerationsToPrefsLimit(
  existingPrefs: Record<string, unknown>,
  list: SavedDiagramGeneration[],
): SavedDiagramGeneration[] {
  let candidate = list
  while (candidate.length > 0) {
    const prefs = mergeDiagramGenerationsIntoPrefs(existingPrefs, candidate)
    if (isAccountPrefsPayloadWithinLimit(prefs)) {
      return candidate
    }
    // Newest-first list: drop the oldest diagram and retry.
    candidate = candidate.slice(0, -1)
  }
  return candidate
}

export type DiagramGenerationsAccount = {
  prefs?: Record<string, unknown>
} | null | undefined

export function useDiagramGenerations(account: DiagramGenerationsAccount) {
  const queryClient = useQueryClient()
  const isAuthenticated = Boolean(account)
  const [localRevision, setLocalRevision] = useState(0)

  const resolveAccount = useCallback((): DiagramGenerationsAccount => {
    return getConsoleAccountFromCache(queryClient) ?? account
  }, [account, queryClient])

  const readGenerationsList = useCallback((): SavedDiagramGeneration[] => {
    if (isAuthenticated) {
      const currentAccount = resolveAccount()
      return parseSavedDiagramGenerations(
        currentAccount?.prefs?.[USER_PREFS_KEY_DIAGRAM_GENERATIONS],
      )
    }
    return readLocalDiagramGenerations()
  }, [isAuthenticated, resolveAccount])

  const generations = useMemo(() => {
    if (isAuthenticated) {
      const currentAccount = resolveAccount()
      return parseSavedDiagramGenerations(
        currentAccount?.prefs?.[USER_PREFS_KEY_DIAGRAM_GENERATIONS],
      )
    }
    return readLocalDiagramGenerations()
  }, [isAuthenticated, localRevision, resolveAccount])

  const persistList = useCallback(
    async (next: SavedDiagramGeneration[]) => {
      if (isAuthenticated) {
        const currentAccount = resolveAccount()
        if (!currentAccount) {
          throw new Error('Account not available')
        }

        const existingPrefs = (currentAccount.prefs ?? {}) as UserPrefs
        let candidate = trimDiagramGenerationsToPrefsLimit(existingPrefs, next)
        if (candidate.length === 0 && next.length > 0) {
          throw new Error(
            'Account preferences are full. Delete older diagrams or other saved prefs and try again.',
          )
        }

        let prefsBase = existingPrefs
        let refreshedFromServer = false

        for (;;) {
          try {
            const updatedAccount = await updateAccountPrefs(
              mergeDiagramGenerationsIntoPrefs(prefsBase, candidate),
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
              candidate = trimDiagramGenerationsToPrefsLimit(prefsBase, next)
              if (candidate.length === 0 && next.length > 0) {
                throw new Error(
                  'Account preferences are full. Delete older diagrams or other saved prefs and try again.',
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

      writeLocalDiagramGenerations(next)
      setLocalRevision((value) => value + 1)
    },
    [isAuthenticated, queryClient, resolveAccount],
  )

  const saveMutation = useMutation({
    mutationFn: async (entry: SavedDiagramGeneration) => {
      const next = upsertSavedDiagramGeneration(readGenerationsList(), entry)
      await persistList(next)
      return next
    },
  })

  const deleteMutation = useMutation({
    mutationFn: async (id: string) => {
      const next = removeSavedDiagramGeneration(readGenerationsList(), id)
      await persistList(next)
      return next
    },
  })

  const renameMutation = useMutation({
    mutationFn: async ({ id, name }: { id: string; name: string }) => {
      const generation = readGenerationsList().find((item) => item.id === id)
      if (!generation) {
        throw new Error('Diagram not found')
      }
      const trimmed = name.trim().slice(0, MAX_SAVED_DIAGRAM_GENERATION_NAME_LENGTH)
      if (!trimmed) {
        throw new Error('Name is required')
      }
      const entry: SavedDiagramGeneration = {
        ...generation,
        name: trimmed,
        updatedAt: Date.now(),
        document: {
          ...generation.document,
          title: trimmed,
        },
      }
      const next = upsertSavedDiagramGeneration(readGenerationsList(), entry)
      await persistList(next)
      return next
    },
  })

  const migrateLegacyMutation = useMutation({
    mutationFn: async () => {
      const current = readGenerationsList()
      if (current.length > 0) return current
      const legacy = readLegacySingleDiagramLocalStorage()
      if (!legacy) return current
      const next = upsertSavedDiagramGeneration(current, legacy)
      await persistList(next)
      clearLegacySingleDiagramLocalStorage()
      return next
    },
  })

  const saveGeneration = useCallback(
    (entry: SavedDiagramGeneration) => saveMutation.mutateAsync(entry),
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
    maxNameLength: MAX_SAVED_DIAGRAM_GENERATION_NAME_LENGTH,
    migrateLegacyIfNeeded,
  }
}
